<?php

namespace App\Actions\Schedule;

use App\Actions\Notification\ResolveClientRecipients;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Models\ClientUser;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use NotificationChannels\WebPush\PushSubscription;

/**
 * M12 R9 — dọn đăng ký thông báo đẩy hằng ngày lúc 03:30 giờ Việt Nam (`push-subscriptions.prune`,
 * `routes/console.php`).
 *
 * # Vệ sinh, không phải lớp bảo vệ
 *
 * Nơi quyết định thật là luật người nhận LÚC GỬI (R10: {@see ResolveClientRecipients} cho khách,
 * {@see ResolveStaffRecipients} cho nhân sự): một đăng ký còn sót của tài khoản đã vô hiệu không bao
 * giờ được dùng, vì chủ của nó không bao giờ lọt vào danh sách người nhận. Lượt dọn này chỉ để bảng
 * không phình và trang "Thông báo trên điện thoại" không liệt kê máy chết. Nó bỏ:
 *
 *  - đăng ký mà chủ không còn dùng được: nhân sự không `is_active` hoặc đã xoá mềm; tài khoản cổng
 *    không `is_active`, đã xoá mềm, hoặc thuộc một khách hàng đã xoá mềm (`whereHas('client')` mang
 *    `SoftDeletingScope` của `Client` — cùng luật `ResolveClientRecipients::eligibleQuery()`); và
 *    đăng ký mà chủ không còn dòng nào. Câu hỏi "có" đi qua global scope của model chủ, nên xoá mềm
 *    tự rơi vào nhánh "không";
 *  - đăng ký không mở ứng dụng quá {@see self::STALE_AFTER_DAYS} ngày: `last_seen_at` (làm mới ở lượt
 *    kiểm "của mình" mỗi phiên và ở cú bấm Bật) cũ hơn mốc, hoặc chưa từng có mà ngày bật cũ hơn mốc.
 *    Ai chạm một thông báo rồi đăng nhập trong 180 ngày thì lượt kiểm đầu phiên đã làm mới nó.
 *
 * `activated_at` cố ý KHÔNG là điều kiện dọn (kế hoạch R9 không liệt kê). Máy đã bật trước khi văn
 * phòng đổi email của khách (`activated_at` về null, phải đổi mật khẩu lại) là máy của chính khách ấy:
 * luật người nhận lúc gửi tạm loại nó cho tới khi khách kích hoạt lại, còn dọn nó đi thì khách phải
 * bật lại mà không vì lý do gì.
 *
 * Mọi điều kiện là MỘT câu `DELETE` cho mỗi nhóm, trong một transaction: chạy hai lần liên tiếp
 * không đổi kết quả (M6 R4) — lần hai không còn dòng nào khớp. Hai tiến trình chồng nhau cũng vô hại
 * (bên sau xoá 0 dòng), nên lịch không đặt `withoutOverlapping()`.
 *
 * Một audit `push_subscriptions_pruned` cho MỖI lượt có dọn (không có gì thì không ghi — một dòng
 * nhật ký rỗng mỗi đêm là nhiễu), chỉ mang các con số và `via = schedule`, không người thực hiện, và
 * KHÔNG BAO GIỜ endpoint (R8: một URL mang quyền gửi). Không ghi `push_device_removed` từng máy: đó
 * là dấu vết của một người gỡ máy của chính mình.
 *
 * Một trong các Action được chạm thẳng bảng đăng ký (`PushSubscriptionAccessTest`): "mọi dòng của mọi
 * người" chính là nghiệp vụ ở đây.
 */
class PrunePushSubscriptions
{
    /** Đăng ký không mở ứng dụng lâu hơn số ngày này thì bị dọn (kế hoạch M12, R9). */
    public const STALE_AFTER_DAYS = 180;

    /** @return array{pruned: int, owner_ineligible: int, stale: int} */
    public function __invoke(): array
    {
        return $this->handle();
    }

    /** @return array{pruned: int, owner_ineligible: int, stale: int} */
    public function handle(): array
    {
        $cutoff = now()->subDays(self::STALE_AFTER_DAYS);

        return DB::transaction(function () use ($cutoff): array {
            $ownerIneligible = PushSubscription::query()
                ->where(fn (Builder $rows) => $rows
                    ->whereDoesntHaveMorph('subscribable', [User::class], fn (Builder $staff) => $staff
                        ->where('is_active', true))
                    ->orWhereDoesntHaveMorph('subscribable', [ClientUser::class], fn (Builder $account) => $account
                        ->where('is_active', true)
                        ->whereHas('client')))
                ->delete();

            $stale = PushSubscription::query()
                ->where(fn (Builder $rows) => $rows
                    ->where('last_seen_at', '<', $cutoff)
                    ->orWhere(fn (Builder $neverSeen) => $neverSeen
                        ->whereNull('last_seen_at')
                        ->where('created_at', '<', $cutoff)))
                ->delete();

            $pruned = $ownerIneligible + $stale;

            if ($pruned > 0) {
                Audit::record('push_subscriptions_pruned', null, [
                    'count' => $pruned,
                    'owner_ineligible' => $ownerIneligible,
                    'stale' => $stale,
                    'via' => 'schedule',
                ]);
            }

            return ['pruned' => $pruned, 'owner_ineligible' => $ownerIneligible, 'stale' => $stale];
        });
    }
}
