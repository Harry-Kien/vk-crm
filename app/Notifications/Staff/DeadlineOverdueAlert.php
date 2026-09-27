<?php

namespace App\Notifications\Staff;

use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Schedule\CheckDeadlines;
use App\Models\Deadline;
use Illuminate\Notifications\Notification;

/**
 * Thông báo TRONG HỆ THỐNG khi một mốc thời hạn đã quá hạn (SPEC §6.8: bậc "< 0" ghi
 * "Đánh dấu quá hạn, TẠO THÔNG BÁO CẢNH BÁO" — khác ba bậc 7/3/1 chỉ ghi "Email"). M6.5 Task 14
 * (`deadlines/F6`, `spec-gap-05`) — trước bản sửa này hệ thống chỉ gửi email ở bậc này, không có
 * gì hiện trên chuông thông báo của panel admin (`AdminPanelProvider::databaseNotifications()`
 * đã bật từ M6 Task 2 nhưng không nơi nào ghi vào bảng `notifications`).
 *
 * # Vì sao lớp này KHÔNG dùng `Filament\Notifications\Notification`
 *
 * Nơi GỌI nó là {@see CheckDeadlines::processOne()} — một Action, và
 * `tests/Feature/ArchitectureTest.php` ("nghiệp vụ không phụ thuộc vào Filament") cấm mọi
 * `App\Actions\*` dùng `Filament\...` — kể cả `Notification::make()->sendToDatabase()`. Nên lớp
 * này là một `Illuminate\Notifications\Notification` thường, kênh `database`, và `toDatabase()`
 * tự dựng ĐÚNG hình dạng mảng mà `Filament\Notifications\Notification::getDatabaseMessage()` sinh
 * ra (`toArray()` bỏ `id`, cộng `duration => 'persistent'` và `format => 'filament'`). Chuông của
 * panel đọc dòng này qua `Notification::fromDatabase()` như mọi thông báo khác — test "writes an
 * overdue notification the admin panel bell can render" (`CheckDeadlinesTest`) dựng lại nó bằng
 * đúng hàm đó.
 *
 * Không `ShouldQueue`: kênh `database` chỉ là một câu INSERT, không chạm mạng — R2 ("mọi thư qua
 * hàng đợi") nói về thư. Nó vẫn chạy NGOÀI transaction của mốc, sau khi commit — xem
 * `CheckDeadlines::processOne()`.
 *
 * # Cùng đối tượng nhận với thư email (R3), không viết luật nhận thứ hai
 *
 * `CheckDeadlines::processOne()` gửi thông báo này cho ĐÚNG `$recipients` mà
 * `CheckDeadlines::recipientsFor($deadline, 'overdue')` tính qua
 * {@see ResolveStaffRecipients} — người giữ mốc (hoặc luật sư phụ trách
 * vụ khi người giữ không còn hợp lệ) cộng `supervisorsFor()` — cùng hàm mà job gửi thư gọi lại lúc
 * chạy. Nội dung mang mã hồ sơ và tên mốc, nên chỉ người qua được `Gate::view()` mới nhận.
 */
class DeadlineOverdueAlert extends Notification
{
    public function __construct(
        private readonly Deadline $deadline,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'actions' => [],
            'body' => __('deadlines.overdue_notification.body', [
                'name' => $this->deadline->name,
                'code' => $this->deadline->matter?->code ?? '',
            ]),
            'color' => 'danger',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => 'danger',
            'title' => __('deadlines.overdue_notification.title'),
            'view' => null,
            'viewData' => [],
            'format' => 'filament',
        ];
    }
}
