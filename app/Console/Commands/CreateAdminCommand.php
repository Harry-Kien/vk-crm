<?php

namespace App\Console\Commands;

use App\Actions\User\CreateAdminFromConsole;
use App\Exceptions\AdminCreationRefused;
use Filament\Facades\Filament;
use Illuminate\Console\Command;

/**
 * `vkcrm:create-admin` — tài khoản quản trị viên đầu tiên trên máy chủ thật (kế hoạch M8 Task 7),
 * chạy ngay sau `db:seed --force` (`docs/CAI-DAT.md`). Luật nằm ở {@see CreateAdminFromConsole};
 * lệnh chỉ hỏi và in.
 *
 * **Chỉ chạy tương tác** (phán quyết controller T7): họ tên, email, rồi mật khẩu nhập ẩn HAI lần.
 * Không có tham số nào nhận mật khẩu — một mật khẩu trên dòng lệnh nằm lại trong lịch sử shell và
 * hiện trong danh sách tiến trình của máy chủ. Ở `--no-interaction` lệnh từ chối, có thông báo:
 * cái giá là không tự động hoá được một việc làm đúng một lần.
 *
 * Kiểm số quản trị viên TRƯỚC khi hỏi gì (đã có admin mà không `--additional` thì không bắt ai gõ
 * mật khẩu vô ích), và kiểm từng ô ngay sau khi hỏi nó. Ô nào sai thì dừng, không hỏi lại: chạy
 * lại lệnh là đủ, và không có vòng lặp nào kẹt được một phiên SSH.
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'vkcrm:create-admin
        {--additional : Tạo THÊM một quản trị viên dù hệ thống đã có — chỉ khi không còn ai vào được /admin để tạo bằng màn hình Nhân sự}';

    protected $description = 'Tạo tài khoản quản trị viên đầu tiên (hỏi tương tác, mật khẩu nhập ẩn) — chạy sau db:seed --force trên máy chủ thật';

    public function handle(CreateAdminFromConsole $action): int
    {
        if (! $this->input->isInteractive()) {
            $this->error(__('users.create_admin.non_interactive'));

            return self::FAILURE;
        }

        $additional = (bool) $this->option('additional');
        $existing = $action->existingAdminCount();

        if ($existing > 0 && ! $additional) {
            return $this->refuse(__('users.create_admin.admins_exist', ['count' => $existing]));
        }

        if ($existing > 0) {
            $this->warn(__('users.create_admin.additional_notice', ['count' => $existing]));
        }

        $name = (string) $this->ask(__('users.create_admin.ask_name'));

        if (($error = $action->nameError($name)) !== null) {
            return $this->refuse($error);
        }

        $email = (string) $this->ask(__('users.create_admin.ask_email'));

        if (($error = $action->emailError($email)) !== null) {
            return $this->refuse($error);
        }

        $password = (string) $this->secret(__('users.create_admin.ask_password'));

        if (($error = $action->passwordError($password)) !== null) {
            return $this->refuse($error);
        }

        $confirmation = (string) $this->secret(__('users.create_admin.ask_password_confirmation'));

        if (! hash_equals($password, $confirmation)) {
            return $this->refuse(__('users.create_admin.password_mismatch'));
        }

        try {
            $admin = $action->handle($name, $email, $password, $additional);
        } catch (AdminCreationRefused $refused) {
            return $this->refuse($refused->getMessage());
        }

        $this->info(__('users.create_admin.created', [
            'name' => $admin->name,
            'email' => $admin->email,
            'url' => Filament::getPanel('admin')->getLoginUrl(),
        ]));

        return self::SUCCESS;
    }

    private function refuse(string $reason): int
    {
        $this->error($reason);
        $this->line(__('users.create_admin.nothing_created'));

        return self::FAILURE;
    }
}
