<?php

namespace App\Notifications\Staff;

use App\Filament\Admin\Resources\Tasks\TaskResource;
use App\Jobs\SendTaskNotice;
use App\Models\Task;
use Filament\Actions\Action;
use Illuminate\Notifications\Notification;

/**
 * Chuông TRONG HỆ THỐNG của luồng giao việc nội bộ (làn tk, Task 1) — một lớp cho năm loại lời báo
 * (hằng số dưới đây). Chỉ {@see SendTaskNotice} gửi nó, SAU khi đã lọc người nhận (đang hoạt động, còn
 * xem được việc — `ResolveStaffRecipients::forTask()`).
 *
 * Cùng khuôn `IntakeAssigned`/`NewClientRequestAlert`: kênh `database`, không `ShouldQueue` (một câu
 * INSERT, chạy trong job đã nằm sau commit), mảng đúng hình dạng Filament đọc được. Thêm một nút "Mở"
 * trỏ về đúng tab của trang "Công việc".
 *
 * **`e()` mọi giá trị nội suy.** Tiêu đề việc, lý do trả lại/huỷ là chữ người dùng gõ; Filament vẽ thân
 * thông báo qua `sanitizeHtml()`, vẫn giữ `<a href>`/`<img>`/`style` — không escape thì một tiêu đề
 * dàn dựng biến chuông thành một lớp phủ bấm được (cùng lý do `NewClientRequestAlert`).
 *
 * Không mang tên khách: chỉ tiêu đề việc và mã vụ — người nhận xem được vụ ở lúc báo.
 */
class TaskAlert extends Notification
{
    public const ASSIGNED = 'assigned';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    /**
     * @param  string  $kind  một trong các hằng số của lớp
     * @param  int  $eventId  id dòng nhật ký của lần chuyển — khoá chống trùng của job
     */
    public function __construct(
        private readonly Task $task,
        private readonly string $kind,
        private readonly string $actorName,
        private readonly int $eventId,
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

        return [
            'actions' => [
                Action::make('open')
                    ->label(__('tasks.alert.open'))
                    ->url(TaskResource::getUrl('index', ['tab' => self::tabFor($this->kind)], panel: 'admin'))
                    ->markAsRead()
                    ->toArray(),
            ],
            'body' => __('tasks.alert.'.$this->kind.'.body', [
                'title' => e($this->task->title),
                'who' => e($this->actorName),
                'reason' => e((string) ($this->kind === self::CANCELLED ? $this->task->cancel_reason : $this->task->decline_reason)),
            ]).($code === null ? '' : ' '.__('tasks.alert.on_matter', ['code' => e($code)])),
            'color' => $this->kind === self::DECLINED || $this->kind === self::CANCELLED ? 'warning' : 'info',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => $this->kind === self::DECLINED || $this->kind === self::CANCELLED ? 'warning' : 'info',
            'title' => __('tasks.alert.'.$this->kind.'.title'),
            'view' => null,
            'viewData' => ['task_id' => $this->task->getKey(), 'task_event_id' => $this->eventId],
            'format' => 'filament',
        ];
    }

    /** Tab của trang "Công việc" mà lời báo trỏ tới: việc giao cho tôi, hay việc tôi đã giao. */
    public static function tabFor(string $kind): string
    {
        return match ($kind) {
            self::ASSIGNED => 'awaiting',
            self::CANCELLED => 'all',
            default => 'assigned',
        };
    }
}
