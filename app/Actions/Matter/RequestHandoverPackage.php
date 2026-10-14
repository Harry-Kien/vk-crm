<?php

namespace App\Actions\Matter;

use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Enums\HandoverIndexImpact;
use App\Enums\HandoverPackageStatus;
use App\Exceptions\HandoverPackageBusy;
use App\Exceptions\HandoverPackageUnavailable;
use App\Exceptions\MatterRecordDestroyed;
use App\Jobs\GenerateHandoverPackage;
use App\Listeners\SyncMatterArchiveOnStageChange;
use App\Models\Document;
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
 *    (nút bấm), vì mỗi lần sinh có thể xoá tệp của version trước. Không đủ điều kiện thì trả `null`
 *    LẶNG LẼ: đường này chạy sau MỖI lần chuyển giai đoạn, và "chưa nên sinh" không phải lỗi.
 *  - **Thủ công** (có `$actor`): phải qua `MatterArchivePolicy::generateHandover` (`document.publish`
 *    và xem được vụ), và mọi điều kiện không đủ là một exception có tên (`HandoverPackageUnavailable`
 *    khi vụ chưa kết thúc / chưa có bản ghi lưu trữ / gói hiện tại đang công bố cho khách,
 *    `HandoverPackageBusy` khi đang có lần sinh chạy) để màn hình báo cho người bấm.
 *
 * # Gói đang công bố cho khách thì không sinh lại (rà soát cuối M7, I2)
 *
 * "Một đường rút duy nhất" (plan Task 7): với tài liệu đang ra tới khách, mọi thao tác đưa nó ra
 * khỏi tầm mắt khách hoặc đi qua `RetractDocument`, hoặc bị chặn kèm câu chỉ tới nút "Rút lại". Bản
 * Task 4 để job sinh lại lặng lẽ hạ version đang công bố về `signed_filed` — một đường rút thứ ba,
 * không lý do, không dòng giải thích cho khách. Nay sinh lại bị từ chối ở đây
 * (`HandoverPackageUnavailable::released()`) khi version mà `handover_document_id` trỏ tới đang
 * `Document::isReleasedToPortal()`; luật sư rút nó (lý do khách đọc được) rồi bấm lại. Đọc version đó
 * có khoá, SAU `matter_archives` (thứ tự `matters` → `matter_archives` → `documents`, cùng
 * {@see BuildHandoverPackage}). Job hỏi lại cùng câu dưới khoá lúc lưu, vì gói có thể được công bố
 * trong lúc job chờ hàng. Chế độ tự động chỉ chạy khi `handover_status` còn NULL — chưa có gói nào
 * — nên không bao giờ tới được câu hỏi này.
 *
 * # Khoá và trạng thái
 *
 * Khoá dòng `matters` TRƯỚC, rồi mới tới `matter_archives` (ràng buộc thứ tự khoá của làn) — cùng
 * thứ tự với {@see SyncMatterArchive}. Hai người bấm cùng lúc: người thứ hai đọc lại trạng thái
 * SAU khi người thứ nhất commit và thấy `generating` → `HandoverPackageBusy`.
 *
 * `generating` KẸT: một worker chết giữa chừng không bao giờ gọi `failed()`, và nút sẽ khoá vĩnh
 * viễn. Vì vậy lần `generating` cũ hơn {@see self::STALE_AFTER_MINUTES} phút được coi là chết và
 * cho yêu cầu lại. Con số (90; 60 trước M14, kế hoạch R12) phải lớn hơn thời gian SỐNG lâu nhất của
 * job: `retry_after` 1500 giây + 2 lượt × `$timeout` 1200 giây + 120 giây chờ giữa hai lượt ≈ 67
 * phút; nhỏ hơn thì nút "sinh lại" mở khoá giữa một lượt còn sống (`QueueHandoverScheduleTest` ghim
 * quan hệ này). Job cũ, nếu nhấc dậy muộn, sẽ tự bị loại bởi dấu
 * `handover_requested_at` ({@see BuildHandoverPackage}).
 *
 * Job được dispatch `->afterCommit()`: trạng thái `generating` đã commit trước khi worker có thể
 * nhặt nó, và nếu một transaction ngoài rollback thì job không bao giờ được xếp.
 *
 * # Đường thứ ba: sinh lại vì một dòng tiến độ bị rút (làn fm, Task 1, vòng sửa 1)
 *
 * Mục lục in mọi dòng tiến độ ĐANG công bố lúc gói được dựng, nên một gói dựng trước khi
 * {@see RetractStageLog} rút một dòng vẫn mang dòng đó — kể cả gói tự sinh lúc đóng vụ đang chờ
 * luật sư công bố. {@see self::refreshAfterRetraction()} (gọi trong transaction của lần rút) xếp
 * sinh lại khi {@see self::indexImpact()} nói `Regenerated`: vụ đang kết thúc, hồ sơ chưa tiêu
 * huỷ, và gói hiện có (hoặc lần sinh đang chờ) chưa ra tới khách. Khác chế độ tự động, đường này
 * KHÔNG dừng khi `handover_status` đã có giá trị (có gói mới là lý do để sinh lại) và KHÔNG dừng khi
 * một lần sinh đang chạy: lần đó có thể đã đọc dòng vừa rút, nên dấu yêu cầu mới thay dấu cũ và job
 * cũ thoát lặng lẽ ở lần kiểm dấu của {@see BuildHandoverPackage}. Gói đang công bố thì không sinh
 * lại (một đường rút duy nhất, như trên) — hộp thoại rút bảo người dùng rút gói trước.
 */
class RequestHandoverPackage
{
    use ReadsWithoutPortalScope;

    /** Sau ngần này phút, một lần `generating` được coi là đã chết — xem docblock lớp. */
    public const STALE_AFTER_MINUTES = 90;

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

            if ($matter === null || ! $matter->isClosed()) {
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

            // Làn fm B2: hồ sơ đã ghi quyết định tiêu huỷ — không sinh (lại) gói, tự động hay bấm tay.
            if ($archive->destroyed_at !== null) {
                if ($automatic) {
                    return null;
                }

                throw MatterRecordDestroyed::make();
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

            // Rà soát cuối M7, I2 — xem docblock lớp, mục "Gói đang công bố cho khách".
            if ($this->currentPackageIsReleased($archive)) {
                if ($automatic) {
                    return null;
                }

                throw HandoverPackageUnavailable::released();
            }

            return $this->queue($matter, $archive, $actor, ['automatic' => $actor === null]);
        });
    }

    /**
     * Rút một dòng tiến độ làm gì với mục lục của gói bàn giao của vụ — đọc KHÔNG khoá, cho hộp thoại
     * "Rút khỏi cổng" (cùng phép phân loại mà {@see self::refreshAfterRetraction()} hỏi lại dưới khoá).
     */
    public function indexImpact(int $matterId): HandoverIndexImpact
    {
        $matter = $this->scopelessly(Matter::query())->find($matterId);
        $archive = $matter === null ? null : $this->scopelessly(MatterArchive::query())
            ->where('matter_id', $matter->getKey())
            ->first();

        return $this->classify($matter, $archive, $this->packageOf($archive, lock: false));
    }

    /**
     * Gọi TRONG transaction của {@see RetractStageLog}, sau khi dòng đã rút: khoá `matters` (đã khoá
     * sẵn bởi người gọi) → `matter_archives` → `documents`, phân loại lại, và với `Regenerated` thì
     * xếp sinh lại gói — người rút đứng tên lần yêu cầu. Xem docblock lớp, mục "Đường thứ ba".
     */
    public function refreshAfterRetraction(int $matterId, User $actor): HandoverIndexImpact
    {
        return DB::transaction(function () use ($matterId, $actor): HandoverIndexImpact {
            $matter = $this->scopelessly(Matter::query())->lockForUpdate()->find($matterId);
            $archive = $matter === null ? null : $this->scopelessly(MatterArchive::query())
                ->where('matter_id', $matter->getKey())
                ->lockForUpdate()
                ->first();

            $impact = $this->classify($matter, $archive, $this->packageOf($archive, lock: true));

            if ($impact === HandoverIndexImpact::Regenerated) {
                $this->queue($matter, $archive, $actor, ['automatic' => false, 'trigger' => 'stage_log_retracted']);
            }

            return $impact;
        });
    }

    /**
     * Một phép phân loại cho cả hộp thoại lẫn Action — xem {@see HandoverIndexImpact}. Gói đang công
     * bố được hỏi TRƯỚC: kể cả trên vụ đã mở lại hay hồ sơ đã tiêu huỷ, nó vẫn đang ở trên cổng.
     */
    private function classify(?Matter $matter, ?MatterArchive $archive, ?Document $package): HandoverIndexImpact
    {
        if ($matter === null || $archive === null) {
            return HandoverIndexImpact::None;
        }

        if ($package !== null && $package->isReleasedToPortal()) {
            return HandoverIndexImpact::Released;
        }

        $hasPackage = $package !== null;

        // Hồ sơ đã tiêu huỷ: gói không công bố, không sinh lại được nữa (làn fm B2) — không còn
        // đường nào đưa mục lục cũ tới khách.
        if ($archive->destroyed_at !== null) {
            return HandoverIndexImpact::None;
        }

        // Vụ đã mở lại: gói chỉ sinh cho vụ đã kết thúc. Lần sinh đang chờ (nếu có) sẽ tự hỏng với
        // lỗi có tên, nên chỉ một gói đã dựng mới là gói cũ cần cảnh báo.
        if (! $matter->isClosed()) {
            return $hasPackage ? HandoverIndexImpact::Stale : HandoverIndexImpact::None;
        }

        $pending = $archive->handover_status === HandoverPackageStatus::Generating;

        return $hasPackage || $pending ? HandoverIndexImpact::Regenerated : HandoverIndexImpact::None;
    }

    /** Version gói mà dòng lưu trữ trỏ tới, gỡ scope portal, kể cả bản đã xoá mềm. */
    private function packageOf(?MatterArchive $archive, bool $lock): ?Document
    {
        if ($archive?->handover_document_id === null) {
            return null;
        }

        $query = $this->scopelessly(Document::query())->withTrashed();

        return ($lock ? $query->lockForUpdate() : $query)->find($archive->handover_document_id);
    }

    /**
     * Đặt dòng lưu trữ về `generating` với một dấu yêu cầu mới, ghi nhật ký, xếp job sau commit.
     *
     * @param  array<string, mixed>  $properties  thêm vào dòng nhật ký `handover_package_requested`
     */
    private function queue(Matter $matter, MatterArchive $archive, ?User $actor, array $properties): MatterArchive
    {
        // Giây tròn: cột `timestamp` không lưu phần lẻ, và dấu này được job so LẠI với giá trị
        // đọc từ cột — phải bằng nhau tuyệt đối. Dấu mới luôn SAU dấu cũ (kể cả khi lần yêu cầu cũ
        // rơi vào cùng giây): một job cũ còn sống phải thấy dấu đã đổi và thoát.
        $requestedAt = now()->startOfSecond();
        $previous = $archive->handover_requested_at;

        if ($previous !== null && $requestedAt->lte($previous)) {
            $requestedAt = $previous->copy()->startOfSecond()->addSecond();
        }

        $archive->update([
            'handover_status' => HandoverPackageStatus::Generating,
            'handover_requested_at' => $requestedAt,
            'handover_requested_by' => $actor?->getKey(),
            'handover_error' => null,
        ]);

        Audit::record('handover_package_requested', $matter, [
            'matter_id' => $matter->getKey(),
            'client_id' => $matter->client_id,
            ...$properties,
        ], $actor);

        GenerateHandoverPackage::dispatch($matter->getKey(), $requestedAt->getTimestamp())->afterCommit();

        return $archive;
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

    /**
     * Version gói mà dòng lưu trữ đang trỏ tới có đang ra tới khách không — đọc có khoá, gỡ scope
     * portal, kể cả bản đã xoá mềm (bản đã xoá mềm thì `isReleasedToPortal()` sai). Cùng vị từ mà
     * `RetractDocument` dùng làm cổng, nên "đang công bố" ở đây đúng là "rút được bằng nút Rút lại".
     */
    private function currentPackageIsReleased(MatterArchive $archive): bool
    {
        if ($archive->handover_document_id === null) {
            return false;
        }

        $package = $this->scopelessly(Document::query())
            ->withTrashed()
            ->lockForUpdate()
            ->find($archive->handover_document_id);

        return $package !== null && $package->isReleasedToPortal();
    }
}
