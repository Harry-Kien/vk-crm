<?php

namespace App\Console\Commands;

use App\Actions\User\ResetStaffTwoFactor;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * `vkcrm:reset-2fa {email}` (R2, kế hoạch M8 Task 2) — cửa sau cho đúng MỘT tình huống: admin duy
 * nhất của hệ thống mất cả điện thoại lẫn mã khôi phục, không còn ai khác vào được `/admin` để bấm
 * nút "Đặt lại 2FA" trên `EditUser`. Gọi CÙNG {@see ResetStaffTwoFactor} (causer `null` — Action
 * ghi `via = console`), không phải một luật riêng: người có quyền chạy lệnh này trên máy chủ đã ở
 * trong vòng tin cậy (cùng tinh thần `vkcrm:create-admin`, Task 7).
 *
 * Không hỏi lại xác nhận (`--force` hay tương tự): lệnh này chỉ chạy được bởi người đã có quyền
 * truy cập máy chủ, và một CLI script gọi nó (tình huống khẩn cấp thật) không nên bị chặn bởi một
 * bước tương tác.
 */
class ResetStaffTwoFactorCommand extends Command
{
    protected $signature = 'vkcrm:reset-2fa {email : Email đăng nhập của nhân sự cần đặt lại 2FA}';

    protected $description = 'Xoá secret 2FA của một nhân sự — buộc họ cài lại ở lần đăng nhập kế tiếp (R2)';

    public function handle(ResetStaffTwoFactor $action): int
    {
        /** @var string $email */
        $email = $this->argument('email');

        $target = User::query()->where('email', $email)->first();

        if ($target === null) {
            $this->error(__('users.actions.reset_two_factor.console_not_found', ['email' => $email]));

            return self::FAILURE;
        }

        $action->handle(null, $target);

        $this->info(__('users.actions.reset_two_factor.console_done', ['email' => $email]));

        return self::SUCCESS;
    }
}
