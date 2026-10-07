<?php

namespace App\Listeners;

use App\Actions\Notification\RecordOutboundPush;
use NotificationChannels\WebPush\Events\NotificationFailed;
use NotificationChannels\WebPush\Events\NotificationSent;

/**
 * M12 R13 — mỗi lần đẩy để lại dấu vết trong `outbound_messages`, mỗi máy một dòng. Đăng ký qua
 * auto-discovery (kiểu tham số của `handle`), cùng khuôn `RecordOutboundMail` của email.
 *
 * `ReportHandler` của gói phát `NotificationSent` (máy chủ push nhận) hay `NotificationFailed` (từ
 * chối, hết hạn, lỗi kết nối) cho TỪNG máy, ngay trong job `PushAlert` — sau khi đã xoá đăng ký 404/410
 * (dòng đã xoá vẫn còn thuộc tính trong bộ nhớ, đủ để biết chủ của nó). KHÔNG `ShouldQueue`: sự kiện
 * mang `SerializesModels` và một đăng ký vừa bị xoá không tuần tự hoá lại được.
 *
 * Luật ở {@see RecordOutboundPush}; hàm đó không bao giờ ném.
 */
class RecordOutboundPushReport
{
    public function __construct(private readonly RecordOutboundPush $record) {}

    public function handle(NotificationSent|NotificationFailed $event): void
    {
        $this->record->record($event->report, $event->subscription, $event->message);
    }
}
