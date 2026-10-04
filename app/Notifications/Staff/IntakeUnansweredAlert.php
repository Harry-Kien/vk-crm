<?php

namespace App\Notifications\Staff;

use App\Actions\Schedule\RemindUnansweredIntakes;
use App\Models\IntakeRequest;
use App\Support\Intake\FirstResponseClock;
use Illuminate\Notifications\Notification;

/**
 * Thông báo TRONG HỆ THỐNG (chuông của panel admin) đi cùng thư `staff.intake_unanswered` (M10 R5):
 * một lần có người liên hệ đã quá ngưỡng phản hồi mà chưa ai gọi lại. Cùng người nhận với thư
 * (`ResolveStaffRecipients::forIntake()`), do {@see RemindUnansweredIntakes} gửi sau khi đã chọn bản
 * ghi, mỗi (người nhận, bản ghi) một lần — khoá chống lặp là `viewData.intake_id`.
 *
 * **Cùng ranh giới dữ liệu với thư:** mã bản ghi, nguồn, lúc nhận, ngưỡng — KHÔNG tên, số điện thoại,
 * email hay câu chuyện của người liên hệ (`notifications.data` cũng là một nơi dữ liệu nằm lâu).
 * Câu chữ là sự kiện cố định (lúc nhận, ngưỡng), không phải "đã chờ X giờ" — một con số chờ ghi vào
 * bảng sẽ sai ngay sau khi ghi.
 *
 * Cùng khuôn `DeadlineOverdueAlert`: `Illuminate\Notifications\Notification` kênh `database`, mảng
 * dựng đúng hình dạng Filament đọc được (`Notification::fromDatabase()`), không `ShouldQueue` (một câu
 * INSERT, không chạm mạng), gọi NGOÀI mọi transaction.
 */
class IntakeUnansweredAlert extends Notification
{
    public function __construct(
        private readonly IntakeRequest $intake,
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
            'body' => __('intake.reminder.alert.body', [
                'code' => $this->intake->code,
                'source' => $this->intake->source->label(),
                'at' => $this->intake->received_at->format('H:i d/m/Y'),
                'hours' => FirstResponseClock::fromConfig()->thresholdHours,
            ]),
            'color' => 'warning',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => 'warning',
            'title' => __('intake.reminder.alert.title'),
            'view' => null,
            // Khoá chống lặp của RemindUnansweredIntakes::alreadyAlerted(): (người nhận, bản ghi).
            'viewData' => [
                'intake_id' => $this->intake->getKey(),
            ],
            'format' => 'filament',
        ];
    }
}
