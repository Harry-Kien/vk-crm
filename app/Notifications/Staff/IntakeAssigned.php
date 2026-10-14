<?php

namespace App\Notifications\Staff;

use App\Actions\Notification\ResolveStaffRecipients;
use App\Models\IntakeRequest;
use Illuminate\Notifications\Notification;

/**
 * Làn fa (kiểm tra nghiệp vụ 2026-10-09, mục nhỏ) — thông báo TRONG HỆ THỐNG cho người vừa được giao
 * một lần tiếp nhận (`assigned_to` đổi sang người đó, lúc ghi hay lúc sửa). Trước bản sửa không ai được
 * báo: người được giao chỉ biết khi tự mở danh sách. Người nhận: {@see ResolveStaffRecipients::forIntakeAssignment()}.
 *
 * **Ranh giới dữ liệu như `IntakeUnansweredAlert`:** chỉ mã bản ghi và nguồn — không tên, SĐT, email
 * hay câu chuyện của người liên hệ (`notifications.data` cũng là nơi dữ liệu nằm lâu, và ẩn danh R7
 * không quét bảng này). Kênh `database`, không `ShouldQueue`, gửi sau khi transaction ghi đã commit.
 */
class IntakeAssigned extends Notification
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
            'body' => __('intake_alerts.assigned.body', [
                'code' => $this->intake->code,
                'source' => $this->intake->source->label(),
            ]),
            'color' => 'info',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => 'info',
            'title' => __('intake_alerts.assigned.title'),
            'view' => null,
            'viewData' => ['intake_id' => $this->intake->getKey()],
            'format' => 'filament',
        ];
    }
}
