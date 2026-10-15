<?php

namespace App\Notifications\Staff;

use App\Actions\Intake\HandIntakeToLawyer;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Models\IntakeRequest;
use Illuminate\Notifications\Notification;

/**
 * Làn kd (2026-10-14) — thông báo TRONG HỆ THỐNG cho luật sư vừa được "Chuyển cho luật sư" một lead
 * ({@see HandIntakeToLawyer}). Người nhận: {@see ResolveStaffRecipients::forIntakeHandoff()}.
 *
 * **Ranh giới dữ liệu như `IntakeAssigned`:** chỉ mã bản ghi và nguồn — không tên, SĐT, email hay câu
 * chuyện của người liên hệ (`notifications.data` là nơi dữ liệu nằm lâu, và ẩn danh R7 không quét bảng
 * này). Kênh `database`, không `ShouldQueue`, gửi sau khi transaction ghi đã commit.
 */
class IntakeHandedToLawyer extends Notification
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
            'body' => __('leads.notifications.handed.body', [
                'code' => $this->intake->code,
                'source' => $this->intake->source->label(),
            ]),
            'color' => 'info',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => 'info',
            'title' => __('leads.notifications.handed.title'),
            'view' => null,
            'viewData' => ['intake_id' => $this->intake->getKey()],
            'format' => 'filament',
        ];
    }
}
