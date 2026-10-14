<?php

namespace App\Notifications\Staff;

use App\Actions\Intake\CheckIntakeConflict;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Models\IntakeRequest;
use Illuminate\Notifications\Notification;

/**
 * Làn fa (kiểm tra nghiệp vụ 2026-10-09, mục nhỏ) — thông báo TRONG HỆ THỐNG cho trưởng phòng/quản trị
 * khi một lần tiếp nhận vừa ra Đỏ chờ xử lý (lần ĐẦU `conflict_red_pending_since` được đặt,
 * {@see CheckIntakeConflict}). Trước bản sửa không ai được báo: chỉ quản lý/admin xử lý được Đỏ, nhưng
 * họ chỉ thấy khi tự mở bộ lọc "Đỏ chờ trưởng phòng xử lý". Người nhận:
 * {@see ResolveStaffRecipients::forIntakeConflictResolution()}.
 *
 * **Ranh giới dữ liệu:** chỉ mã bản ghi — không tên, không bên trùng, không câu chuyện (R1, R8).
 * Kênh `database`, không `ShouldQueue`, gửi sau khi transaction kiểm tra đã commit.
 */
class IntakeRedPending extends Notification
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
            'body' => __('intake_alerts.red_pending.body', ['code' => $this->intake->code]),
            'color' => 'danger',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => 'danger',
            'title' => __('intake_alerts.red_pending.title'),
            'view' => null,
            'viewData' => ['intake_id' => $this->intake->getKey()],
            'format' => 'filament',
        ];
    }
}
