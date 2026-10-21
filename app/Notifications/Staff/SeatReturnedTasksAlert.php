<?php

namespace App\Notifications\Staff;

use App\Filament\Admin\Resources\Tasks\Tables\TaskColumns;
use App\Jobs\SendSeatReturnedTaskNotices;
use Filament\Actions\Action;
use Illuminate\Notifications\Notification;

/**
 * Chuông "người giữ việc anh/chị giao đã trả lại hồ sơ — n việc cần giao lại" (sửa sau kiểm toán luồng Công việc
 * 2026-10-21, mục 7) — chỉ {@see SendSeatReturnedTaskNotices} gửi, sau commit của `DeclineMatterAssignment`, MỘT chuông
 * mỗi người đã giao việc cho người vừa trả lại ghế (trừ những người đã nhận lời báo trả lại ghế). Các việc đó hiện
 * "Cần giao lại" (vế `seat_declined` của `Task::scopeNeedsReassignment()`); không có chuông này người giao không biết.
 *
 * Mã hồ sơ (job chỉ gửi người còn mở được hồ sơ), tên người trả lại, số việc — không tiêu đề việc, không lý do trả lại
 * ghế (lý do chỉ cho người quản lý được đội ngũ), không tên khách. Nút "Mở" về tab "Công việc" của hồ sơ, nơi người giao
 * giao lại. Kênh `database`, không `ShouldQueue`, `e()` mọi giá trị nội suy. `viewData.seat_returned_key` là khoá chống
 * trùng của job.
 */
class SeatReturnedTasksAlert extends Notification
{
    public function __construct(
        private readonly int $matterId,
        private readonly string $code,
        private readonly string $memberName,
        private readonly int $count,
        private readonly string $key,
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
            'actions' => [
                Action::make('open')
                    ->label(__('internal_flow.seat_returned_tasks.open'))
                    ->url(TaskColumns::matterTabUrl($this->matterId))
                    ->markAsRead()
                    ->toArray(),
            ],
            'body' => __('internal_flow.seat_returned_tasks.body', [
                'member' => e($this->memberName),
                'code' => e($this->code),
                'count' => $this->count,
            ]),
            'color' => 'warning',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => 'warning',
            'title' => __('internal_flow.seat_returned_tasks.title'),
            'view' => null,
            'viewData' => ['seat_returned_key' => $this->key],
            'format' => 'filament',
        ];
    }
}
