<?php

namespace App\Notifications\Staff;

use App\Filament\Admin\Resources\Matters\RelationManagers\DeadlinesRelationManager;
use App\Jobs\SendDeadlineAssignmentNotice;
use App\Models\Deadline;
use App\Support\MatterTab;
use Filament\Actions\Action;
use Illuminate\Notifications\Notification;

/**
 * Chuông "anh/chị được giao một mốc thời hạn" (làn fa2, phán quyết 6) — chỉ {@see SendDeadlineAssignmentNotice}
 * gửi, sau khi đã lọc người nhận (đang hoạt động, còn mở được hồ sơ — `ResolveStaffRecipients::qualifies()`),
 * nên tên mốc và mã hồ sơ được phép hiện. Không tên khách. Kèm hạn và số ngày còn lại tính LÚC GỬI
 * ({@see self::dueLine()}): lời nhắc theo bậc đã gửi cho người giữ cũ không gửi lại, nên lời báo này mang
 * đúng điều bậc gần nhất nói.
 *
 * Kênh `database`, không `ShouldQueue`, `e()` mọi giá trị nội suy (tên mốc là chữ người dùng gõ).
 * `viewData.deadline_assignment_key` là khoá chống trùng của job.
 */
class DeadlineAssignedAlert extends Notification
{
    public function __construct(
        private readonly Deadline $deadline,
        private readonly string $actorName,
        private readonly string $key,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** "hạn 20/10/2026 — còn 3 ngày" / "hôm nay" / "đã quá hạn 2 ngày" — một định nghĩa cho chuông và thư. */
    public static function dueLine(Deadline $deadline): string
    {
        $days = (int) today()->diffInDays($deadline->due_date, false);
        $date = $deadline->due_date->format('d/m/Y');

        return match (true) {
            $days > 0 => __('internal_flow.deadline_assigned.due_in', ['date' => $date, 'days' => $days]),
            $days === 0 => __('internal_flow.deadline_assigned.due_today', ['date' => $date]),
            default => __('internal_flow.deadline_assigned.overdue', ['date' => $date, 'days' => -$days]),
        };
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $overdue = today()->greaterThan($this->deadline->due_date);

        return [
            'actions' => [
                Action::make('open')
                    ->label(__('internal_flow.deadline_assigned.open'))
                    ->url(MatterTab::url((int) $this->deadline->matter_id, DeadlinesRelationManager::class))
                    ->markAsRead()
                    ->toArray(),
            ],
            'body' => __('internal_flow.deadline_assigned.body', [
                'name' => e($this->deadline->name),
                'code' => e((string) $this->deadline->matter?->code),
                'due' => e(self::dueLine($this->deadline)),
                'who' => e($this->actorName),
            ]),
            'color' => $overdue ? 'warning' : 'info',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => $overdue ? 'warning' : 'info',
            'title' => __('internal_flow.deadline_assigned.title'),
            'view' => null,
            'viewData' => ['deadline_id' => $this->deadline->getKey(), 'deadline_assignment_key' => $this->key],
            'format' => 'filament',
        ];
    }
}
