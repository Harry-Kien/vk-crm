<?php

namespace App\Console\Commands;

use App\Actions\Push\ResetPushSubscriptions;
use Illuminate\Console\Command;

/**
 * `vkcrm:push-reset` (M12 R7) — xoá mọi đăng ký thông báo đẩy sau khi đổi khoá VAPID. Nghiệp vụ ở
 * {@see ResetPushSubscriptions}; lệnh chỉ hỏi và in.
 *
 * Khác `vkcrm:reset-2fa` (không hỏi lại), lệnh này HỎI xác nhận và nói trước số đăng ký sẽ mất: nó
 * tắt thông báo trên MỌI điện thoại của mọi nhân sự và khách, và chỉ đúng khi khoá vừa đổi — chạy
 * nhầm trên một máy chủ đang chạy tốt bắt mọi người bật lại từng máy. `--force` bỏ bước hỏi cho
 * kịch bản đổi khoá. Chạy không tương tác mà thiếu `--force` thì `confirm()` trả mặc định "không":
 * lệnh từ chối, mã thoát khác 0, không xoá gì.
 */
class PushResetCommand extends Command
{
    protected $signature = 'vkcrm:push-reset
        {--force : Không hỏi xác nhận — dùng trong kịch bản đổi khoá VAPID}';

    protected $description = 'Xoá MỌI đăng ký thông báo đẩy — chạy sau mỗi lần đổi khoá VAPID (M12 R7)';

    public function handle(ResetPushSubscriptions $action): int
    {
        if (! $this->option('force') && ! $this->confirm(__('push.reset.confirm', ['count' => $action->count()]))) {
            $this->warn(__('push.reset.cancelled'));

            return self::FAILURE;
        }

        $deleted = $action->handle();

        $this->info(__('push.reset.done', ['count' => $deleted]));

        return self::SUCCESS;
    }
}
