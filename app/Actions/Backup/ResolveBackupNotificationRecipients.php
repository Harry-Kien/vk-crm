<?php

namespace App\Actions\Backup;

use App\Enums\Role;
use App\Models\User;
use App\Support\Backup\BackupNotifyEmails;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * SPEC §10 mục 8, M8a Task 1 — người nhận thư báo lỗi sao lưu/dọn dẹp/bản sao không lành mạnh.
 *
 * `BACKUP_NOTIFY_EMAIL` (`config('vkcrm.backup.notify_email')`, một hoặc nhiều địa chỉ phân
 * tách dấu phẩy — xem {@see BackupNotifyEmails}) thắng tuyệt đối khi có ÍT NHẤT một địa chỉ hợp
 * lệ. Trống hoặc toàn địa chỉ hỏng thì rơi về MỌI nhân sự đang hoạt động (`is_active = true`)
 * mang vai trò Admin — không phải toàn bộ Admin đã từng tồn tại, và không phải nhân sự khác: một
 * lỗi hạ tầng là việc của người vận hành, không phải của luật sư đang xử lý hồ sơ.
 *
 * Đọc `config('vkcrm.backup.notify_email')` chứ KHÔNG `config('backup.notifications.mail.to')`
 * của gói: trường đó luôn là một placeholder cố định, không liên quan tới biến này (xem docblock
 * ở `config/backup.php`, fix I1 — đưa giá trị thô của toán tử vào trường validate chặt của gói
 * làm hỏng MỌI lệnh artisan).
 *
 * Kiểm tra vai trò Admin TỒN TẠI trước khi lọc theo nó: `HasRoles::scopeRole()` (từ
 * `spatie/laravel-permission`) ném `RoleDoesNotExist` khi tên vai trò chưa có dòng nào trong
 * bảng `roles` — tình huống có thật trên một máy chủ MỚI CÀI, trước khi tài khoản admin đầu
 * tiên được tạo (`User::assignRoleFromPosition()` mới là nơi dòng vai trò được sinh ra). Một lỗi
 * sao lưu xảy ra ĐÚNG LÚC đó không được phép biến thành một `RoleDoesNotExist` 500 thay vì
 * "không tìm được người nhận, coi như rỗng".
 */
class ResolveBackupNotificationRecipients
{
    /** @return list<string> */
    public function handle(): array
    {
        $configured = BackupNotifyEmails::parse(config('vkcrm.backup.notify_email'));

        if ($configured !== []) {
            return $configured;
        }

        if (! SpatieRole::query()->where('name', Role::Admin->value)->exists()) {
            return [];
        }

        return User::query()
            ->where('is_active', true)
            ->role(Role::Admin->value)
            ->orderBy('email')
            ->pluck('email')
            ->all();
    }
}
