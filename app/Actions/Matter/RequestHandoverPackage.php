<?php

namespace App\Actions\Matter;

use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Enums\HandoverPackageStatus;
use App\Exceptions\HandoverPackageBusy;
use App\Exceptions\HandoverPackageUnavailable;
use App\Jobs\GenerateHandoverPackage;
use App\Listeners\SyncMatterArchiveOnStageChange;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * M7 Task 4 (R9) — xếp hàng MỘT lần sinh gói bàn giao của một vụ việc đã kết thúc. Đây là cửa
 * DUY NHẤT xếp hàng job {@see GenerateHandoverPackage}: hai nơi gọi nó là
 * {@see SyncMatterArchiveOnStageChange} (tự sinh khi vụ vào giai đoạn kết thúc) và nút "Sinh (lại)
 * gói bàn giao" trên trang vụ việc.
 *
 * # Hai chế độ
 *
 *  - **Tự động** (`$automatic = true`, không có người bấm): xếp hàng ĐÚNG MỘT LẦN cho một vụ
 *    (SPEC §6.12 "khi vụ việc chuyển sang giai đoạn kết thúc, hệ thống sinh"). "Một lần" = chỉ khi
 *    `handover_status` còn NULL. Một vụ đóng rồi mở lại rồi đóng lại, hay chuyển từ giai đoạn kết
 *    thúc này sang giai đoạn kết thúc khác, không tự sinh lại — sinh lại là quyết định của người
 *    (nút bấm), vì mỗi lần sinh xoá tệp của version trước. Không đủ điều kiện thì trả `null` LẶNG
 *    LẼ: đường này chạy sau MỖI lần chuyển giai đoạn, và "chưa nên sinh" không phải lỗi.
 *  - **Thủ công** (có `$actor`): phải qua `MatterArchivePolicy::generateHandover` (`document.publish`
 *    và xem được vụ), và mọi điều kiện không đủ là một exception có tên (`HandoverPackageUnavailable`
 *    khi vụ chưa kết thúc / chưa có bản ghi lưu trữ, `HandoverPackageBusy` khi đang có lần sinh chạy)
 *    để màn hình báo cho người bấm.
 *
 * # Khoá và trạng thái
 *
 * Khoá dòng `matters` TRƯỚC, rồi mới tới `matter_archives` (ràng buộc thứ tự khoá của làn) — cùng
 * thứ tự với {@see SyncMatterArchive}. Hai người bấm cùng lúc: người thứ hai đọc lại trạng thái
 * SAU khi người thứ nhất commit và thấy `generating` → `HandoverPackageBusy`.
 *
 * `generating` KẸT: một worker chết giữa chừng không bao giờ gọi `failed()`, và nút sẽ khoá vĩnh
 * viễn. Vì vậy lần `generating` cũ hơn {@see self::STALE_AFTER_MINUTES} phút được coi là chết và
 * cho yêu cầu lại (dài hơn nhiều lần tổng thời gian tối đa của job: `$timeout` 600 giây × 2 lượt
 * thử + độ trễ giữa hai lượt). Job cũ, nếu nhấc dậy muộn, sẽ tự bị loại bởi dấu
 * `handover_requested_at` ({@see BuildHandoverPackage}).
 *
 * Job được dispatch `->afterCommit()`: trạng thái `generating` đã commit trước khi worker có thể
 * nhặt nó, và nếu một transaction ngoài rollback thì job không bao giờ được xếp.
 */
class RequestHandoverPackage
{
    use ReadsWithoutPortalScope;

    /** Sau ngần này phút, một lần `generating` được coi là đã chết — xem docblock lớp. */
    public const STALE_AFTER_MINUTES = 60;

    public function handle(int $matterId, ?User $actor = null, bool $automatic = false): ?MatterArchive
    {
        return DB::transaction(function () use ($matterId, $actor, $automatic): ?MatterArchive {
            // Khoá `matters` TRƯỚC.
            $matter = $this->scopelessly(Matter::query())
                ->withTrashed()
                ->whereKey($matterId)
                ->lockForUpdate()
                ->first();

            $archive = $matter === null ? null : $this->scopelessly(MatterArchive::query())
                ->where('matter_id', $matter->getKey())
                ->lockForUpdate()
                ->first();

            if ($actor !== null) {
                // Với người bấm, thiếu bản ghi lưu trữ là lỗi có tên ngay: chưa có bản ghi để kiểm
                // quyền trên nó.
                if ($archive === null || $matter === null || $matter->trashed()) {
                    throw HandoverPackageUnavailable::noArchive();
                }

                Gate::forUser($actor)->authorize('generateHandover', $archive->setRelation('matter', $matter));
            }

            if ($matter === null || $matter->trashed() || $matter->closed_at === null) {
                if ($automatic) {
                    return null;
                }

                throw HandoverPackageUnavailable::notClosed();
            }

            if ($archive === null) {
                if ($automatic) {
                    return null;
                }

                throw HandoverPackageUnavailable::noArchive();
            }

            if ($automatic && $archive->handover_status !== null) {
                return null;
            }

            if ($this->isRunning($archive)) {
                if ($automatic) {
                    return null;
                }

                throw HandoverPackageBusy::make();
            }

            // Giây tròn: cột `timestamp` không lưu phần lẻ, và dấu này được job so LẠI với giá trị
            // đọc từ cột — phải bằng nhau tuyệt đối.
            $requestedAt = now()->startOfSecond();

            $archive->update([
                'handover_status' => HandoverPackageStatus::Generating,
                'handover_requested_at' => $requestedAt,
                'handover_requested_by' => $actor?->getKey(),
                'handover_error' => null,
            ]);

            Audit::record('handover_package_requested', $matter, [
                'matter_id' => $matter->getKey(),
                'client_id' => $matter->client_id,
                'automatic' => $actor === null,
            ], $actor);

            GenerateHandoverPackage::dispatch($matter->getKey(), $requestedAt->getTimestamp())->afterCommit();

            return $archive;
        });
    }

    /**
     * Đang có một lần sinh chạy và nó CHƯA kẹt. Công khai để màn hình khoá/mở nút đúng cùng một
     * định nghĩa với Action (không hai bản của cùng một luật).
     */
    public static function isRunning(MatterArchive $archive): bool
    {
        return $archive->handover_status === HandoverPackageStatus::Generating
            && ! self::isStuck($archive);
    }

    /** `generating` quá lâu — xem docblock lớp, mục "generating KẸT". */
    public static function isStuck(MatterArchive $archive): bool
    {
        return $archive->handover_status === HandoverPackageStatus::Generating
            && ($archive->handover_requested_at === null
                || $archive->handover_requested_at->lt(now()->subMinutes(self::STALE_AFTER_MINUTES)));
    }
}
