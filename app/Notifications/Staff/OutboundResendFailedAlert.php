<?php

namespace App\Notifications\Staff;

use App\Jobs\ResendOutboundMessageJob;
use Illuminate\Notifications\Notification;

/**
 * Thông báo trong hệ thống khi {@see ResendOutboundMessageJob} hỏng HẲN (hết mọi lượt thử) — tới
 * người bấm "Gửi lại", hoặc người thay theo chuỗi dự phòng R3 nếu người bấm đã nghỉ (xem
 * `ResendOutboundMessageJob::failed()`). Cùng khuôn `DocumentPublishedMailFailedAlert` (Notification
 * thường của Laravel, kênh `database`, mảng đúng hình dạng Filament).
 *
 * Câu chữ cố ý KHÔNG có mã vụ việc, tiêu đề, tên khách hay địa chỉ: người nhận xem được vụ (R3),
 * nhưng chuông thông báo là nơi câu chữ đi xa hơn màn hình đã kiểm quyền. Chỉ id dòng nhật ký (số
 * thô) đi kèm trong `viewData`.
 */
class OutboundResendFailedAlert extends Notification
{
    public function __construct(
        private readonly int $messageId,
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
            'body' => __('outbound.resend.failed_notification.body'),
            'color' => 'danger',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => 'danger',
            'title' => __('outbound.resend.failed_notification.title'),
            'view' => null,
            'viewData' => ['outbound_message_id' => $this->messageId],
            'format' => 'filament',
        ];
    }
}
