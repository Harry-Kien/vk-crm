<?php

namespace App\Actions\Schedule;

use App\Models\ClientUser;
use App\Models\Matter;
use App\Support\Audit;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SPEC §6.12, M7 Task 5 (R4) — tác vụ hằng ngày `client-access.expire` (`routes/console.php`).
 *
 * **Việc của tác vụ này CHỈ là vô hiệu hoá tài khoản cổng.** Vụ việc rời cổng khi quá
 * `client_access_until` là việc của hai tầng ranh giới — `Matter::applyClientPortalConstraints()`
 * và `MatterPolicy::releasedToPortal()` — và nó đúng từ 00:00 ngày SAU `client_access_until`, dù
 * tác vụ này đã chạy hay chưa. Tác vụ không đổi một cột nào của `matters`, `matter_archives` hay
 * `documents` (R4: "làm khách mất quyền xem, không làm mất dữ liệu").
 *
 * **Ai bị vô hiệu hoá (R4, phán quyết của controller):** mọi tài khoản `client_users` ĐANG hoạt động
 * (chưa xoá mềm) của một khách hàng có
 *  1. ÍT NHẤT MỘT vụ việc (chưa xoá mềm) đã hết hạn tra cứu — định nghĩa duy nhất ở
 *     `MatterArchive::scopeClientAccessExpired()`; VÀ
 *  2. KHÔNG còn vụ nào hiển thị trên cổng — hỏi bằng CHÍNH định nghĩa cổng
 *     (`ClientPortalScope::actingAs()` chạy `Matter::applyClientPortalConstraints()` thật), không
 *     viết định nghĩa thứ ba.
 *
 * Vế 1 là thứ giữ một khách MỚI có vụ đầu tiên chưa công bố: họ cũng "không có vụ nào trên cổng",
 * nhưng chưa từng có vụ nào hết hạn. Hệ quả theo đúng chữ của R4, ghi ra cho người đọc sau: một khách
 * CŨ có vụ đã hết hạn và vừa có một vụ MỚI chưa công bố thoả cả hai vế, nên bị vô hiệu hoá — nhân
 * sự bật lại tay khi công bố vụ mới (xem "Ghi chú M7" ở `docs/PROGRESS.md`).
 *
 * **Không bao giờ bật lại.** Tác vụ chỉ đặt `is_active = false`; một vụ được mở lại (đường bỏ qua
 * của admin, `SyncMatterArchive` xoá `client_access_until` về `null`) đưa vụ về lại cổng nhưng KHÔNG
 * tự bật tài khoản — nhân sự bật tay. Bật tự động là một quyết định trao quyền truy cập, và quyết
 * định đó thuộc về người.
 *
 * **Dấu vết (SPEC §10.6 "vô hiệu hoá tài khoản portal").** Lưu TỪNG model bằng `save()` để
 * `LogsActivity` của `ClientUser` ghi dòng `updated` mang `is_active` cũ/mới — một `update()` hàng
 * loạt bỏ qua sự kiện model và không để lại gì — kèm một dòng `portal_account_deactivated` qua
 * `Audit::record()`. Từ scheduler không có phiên đăng nhập nào, nên người thực hiện để trống — trang
 * Nhật ký hiện "Hệ thống" (`Audit::record()` chỉ gán người thực hiện khi có một phiên đang mở).
 * Thuộc tính chỉ có `reason`, không mã hay tiêu đề vụ việc nào: trang Nhật ký hiện thuộc tính, và
 * một vụ `restricted` không được lộ qua đó.
 *
 * **Idempotent.** Chỉ tài khoản đang `is_active` được đụng tới, nên lần chạy thứ hai không ghi thêm
 * dòng nào.
 *
 * **Một khách, một transaction, và đọc lại dưới khoá.** Tập ứng viên được dựng TRƯỚC vòng lặp (một
 * truy vấn), rồi mỗi khách được xử lý trong transaction riêng: câu lệnh ĐẦU TIÊN khoá các dòng
 * `matters` của khách đó (thứ tự khoá toàn cục: `matters` trước), rồi cả hai vế được đọc LẠI dưới
 * khoá trước khi đụng tới tài khoản. Không đọc lại thì một vụ vừa được công bố (hay vừa mở lại) giữa
 * lúc vòng lặp đang xử lý những khách khác vẫn để khách đó mất tài khoản ngay sau khi văn phòng vừa
 * mở hồ sơ cho họ. Mỗi transaction mới mở lại ảnh chụp đọc của nó, nên lần đọc lại thấy được thay đổi
 * đã commit trong lúc chờ tới lượt.
 *
 * **Lỗi ở một khách không dừng vòng lặp** — cùng hình dạng `CheckDeadlines`: `try`/`catch` riêng
 * cho từng khách, `report()` để lỗi không biến mất, transaction của khách đó rollback trọn vẹn —
 * không có khách nào bị khoá nửa chừng (tài khoản thứ nhất đã khoá, tài khoản thứ hai lỗi nên vẫn mở).
 *
 * **Không thư nào.** Tác vụ không gửi gì cho khách — câu báo là việc của màn hình đăng nhập
 * (`EnsurePortalAccountIsActive`, `portal.inactive`), và phiên đang mở mất ở request kế tiếp.
 *
 * **Mọi truy vấn trên `Matter`/`MatterArchive` gỡ `ClientPortalScope` tường minh** (`ClientUser`
 * không mang scope đó). Tác vụ chạy từ scheduler (không phiên nào), nhưng nếu không gỡ thì một lời
 * gọi trong một tiến trình có phiên cổng đang mở sẽ để scope của khách ĐÓ cắt tập ứng viên — đúng
 * thứ `ReadsWithoutPortalScope` đã ghi cho các Action tài liệu. Riêng vế 2 cố ý chạy scope THẬT,
 * nhưng qua `actingAs()` của đúng khách đang xét, không qua phiên ambient.
 */
class ExpireClientAccess
{
    public const REASON = 'client_access_expired';

    /**
     * @return array{deactivated: int, failed: int}
     */
    public function __invoke(): array
    {
        return $this->handle();
    }

    /**
     * @return array{deactivated: int, failed: int}
     */
    public function handle(): array
    {
        $deactivated = 0;
        $failed = 0;

        // Chỉ khách còn tài khoản đang hoạt động là ứng viên: sau lần chạy đầu, mọi khách đã xử lý
        // rơi khỏi tập này, nên số transaction mỗi đêm không lớn dần theo số vụ đã lưu trữ.
        // `ClientUser` không mang `ClientPortalScope` (xem docblock lớp đó), nên truy vấn con này
        // không bị phiên nào cắt.
        $clientIds = $this->expiredMatters(Matter::query()->withoutGlobalScope(ClientPortalScope::class))
            ->whereIn('client_id', ClientUser::query()->where('is_active', true)->select('client_id'))
            ->distinct()
            ->orderBy('client_id')
            ->pluck('client_id');

        foreach ($clientIds as $clientId) {
            try {
                $deactivated += $this->expireClient((int) $clientId);
            } catch (Throwable $e) {
                // Một khách lỗi không dừng những khách còn lại — xem docblock lớp.
                report($e);
                $failed++;
            }
        }

        return ['deactivated' => $deactivated, 'failed' => $failed];
    }

    /**
     * Một khách, một transaction — trả số tài khoản đã vô hiệu hoá.
     */
    private function expireClient(int $clientId): int
    {
        return DB::transaction(function () use ($clientId): int {
            // Câu lệnh ĐẦU TIÊN: khoá mọi dòng `matters` của khách (kể cả đã xoá mềm, để một lần
            // khôi phục chạy đua cũng phải chờ), trước mọi dòng nào khác — thứ tự khoá toàn cục.
            Matter::query()
                ->withoutGlobalScope(ClientPortalScope::class)
                ->withTrashed()
                ->where('client_id', $clientId)
                ->lockForUpdate()
                ->pluck('id');

            // Vế 1, đọc lại dưới khoá.
            $hasExpired = $this->expiredMatters(Matter::query()->withoutGlobalScope(ClientPortalScope::class))
                ->where('client_id', $clientId)
                ->exists();

            if (! $hasExpired) {
                return 0;
            }

            $accounts = ClientUser::query()
                ->where('client_id', $clientId)
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($accounts->isEmpty()) {
                return 0;
            }

            // Vế 2, đọc lại dưới khoá, bằng CHÍNH định nghĩa cổng: điều kiện cổng của `Matter` chỉ
            // phụ thuộc `client_id` của tài khoản, nên một tài khoản bất kỳ của khách trả lời cho
            // cả khách.
            $stillVisible = ClientPortalScope::actingAs(
                $accounts->first(),
                fn (): bool => Matter::query()->exists(),
            );

            if ($stillVisible) {
                return 0;
            }

            foreach ($accounts as $account) {
                $account->is_active = false;
                $account->save();

                Audit::record('portal_account_deactivated', $account, ['reason' => self::REASON]);
            }

            return $accounts->count();
        });
    }

    /**
     * Vụ việc (chưa xoá mềm) có dòng lưu trữ (chưa xoá mềm) đã hết hạn tra cứu. `ClientPortalScope`
     * của `MatterArchive` gỡ trong truy vấn con vì cùng lý do với `Matter::applyClientPortalConstraints()`.
     *
     * @param  Builder<Matter>  $query
     * @return Builder<Matter>
     */
    private function expiredMatters(Builder $query): Builder
    {
        return $query->whereHas('archive', fn (Builder $archive) => $archive
            ->withoutGlobalScope(ClientPortalScope::class)
            ->clientAccessExpired());
    }
}
