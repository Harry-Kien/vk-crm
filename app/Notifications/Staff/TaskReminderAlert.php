<?php

namespace App\Notifications\Staff;

use App\Actions\Schedule\RemindPendingWork;
use App\Filament\Admin\Resources\Tasks\TaskResource;
use App\Jobs\SendWorkReminder;
use App\Models\Task;
use Filament\Actions\Action;
use Illuminate\Notifications\Notification;

/**
 * Chuông của lịch nhắc VIỆC giao nội bộ (làn tk, Task 2) — năm loại nhắc của {@see RemindPendingWork}
 * (chờ nhận quá lâu, báo người giao chưa ai nhận, sắp đến hạn, quá hạn, báo người giao việc quá hạn). Chỉ
 * {@see SendWorkReminder} gửi nó, SAU khi đã lọc người nhận qua `ResolveStaffRecipients::forTask()` (còn
 * xem được việc — một việc của vụ hạn chế không bao giờ tới ai ngoài luật sư phụ trách và admin).
 *
 * Cùng khuôn {@see TaskAlert}: kênh `database`, `e()` mọi giá trị nội suy (tiêu đề việc là chữ người dùng
 * gõ), mang tiêu đề việc và mã vụ, không tên khách. `viewData.reminder_key` là khoá chống trùng của job.
 */
class TaskReminderAlert extends Notification
{
    public function __construct(
        private readonly Task $task,
        private readonly string $kind,
        private readonly string $key,
        private readonly string $detail,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $code = $this->task->matter?->code;
        $lang = 'tasks.reminder.alert.'.str_replace('reminder.', '', $this->kind);
        $danger = in_array($this->kind, [RemindPendingWork::OVERDUE, RemindPendingWork::OVERDUE_ASSIGNER], true);

        return [
            'actions' => [
                Action::make('open')
                    ->label(__('tasks.alert.open'))
                    ->url(TaskResource::getUrl('index', ['tab' => self::tabFor($this->kind)], panel: 'admin'))
                    ->markAsRead()
                    ->toArray(),
            ],
            'body' => __($lang.'.body', [
                'title' => e($this->task->title),
                'who' => e((string) $this->task->assignee?->name),
                'detail' => e($this->detail),
            ]).($code === null ? '' : ' '.__('tasks.alert.on_matter', ['code' => e($code)])),
            'color' => $danger ? 'danger' : 'warning',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => $danger ? 'danger' : 'warning',
            'title' => __($lang.'.title'),
            'view' => null,
            'viewData' => ['task_id' => $this->task->getKey(), 'reminder_key' => $this->key],
            'format' => 'filament',
        ];
    }

    /** Tab của trang "Công việc" mà lời nhắc trỏ tới: việc của tôi, hay việc tôi đã giao. */
    public static function tabFor(string $kind): string
    {
        return match ($kind) {
            RemindPendingWork::AWAITING => 'awaiting',
            RemindPendingWork::DUE_SOON, RemindPendingWork::OVERDUE => 'active',
            default => 'assigned',
        };
    }
}
