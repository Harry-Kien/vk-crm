<?php

namespace App\Actions\Push;

use App\Console\Commands\PushResetCommand;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use NotificationChannels\WebPush\PushSubscription;

/**
 * M12 R7 — xoá MỌI đăng ký thông báo đẩy, của mọi người, trên mọi máy. Gọi từ
 * {@see PushResetCommand} (`vkcrm:push-reset`) sau mỗi lần đổi khoá VAPID.
 *
 * Vì sao cần: đổi (hay mất) khoá riêng thì mọi đăng ký cũ chết IM LẶNG — máy chủ push trả 401/403
 * chứ không 410, nên `ReportHandler` của gói không tự xoá dòng nào và mỗi lần gửi tốn một request
 * hỏng cho mỗi máy. Sau lệnh này mọi người bật lại trên từng máy (kế hoạch: `register.js` của
 * Task 5 so khoá công khai và mời bật lại bằng một chạm).
 *
 * Một trong các Action được phép chạm thẳng bảng đăng ký (`PushSubscriptionAccessTest`): "mọi
 * dòng" chính là nghiệp vụ ở đây. Audit `push_subscriptions_reset` chỉ mang SỐ dòng đã xoá và
 * `via = console`, không người thực hiện (người chạy lệnh trên máy chủ đã ở trong vòng tin cậy —
 * cùng tinh thần `vkcrm:reset-2fa`), và KHÔNG BAO GIỜ endpoint (R8: một URL mang quyền gửi).
 */
class ResetPushSubscriptions
{
    /** Số đăng ký hiện có — để lệnh nói trước cho người vận hành biết sẽ xoá bao nhiêu. */
    public function count(): int
    {
        return PushSubscription::query()->count();
    }

    /** @return int số dòng đã xoá */
    public function handle(): int
    {
        return DB::transaction(function (): int {
            $deleted = PushSubscription::query()->delete();

            Audit::record('push_subscriptions_reset', null, [
                'count' => $deleted,
                'via' => 'console',
            ]);

            return $deleted;
        });
    }
}
