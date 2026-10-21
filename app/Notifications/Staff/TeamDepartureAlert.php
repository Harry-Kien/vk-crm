<?php

namespace App\Notifications\Staff;

use App\Filament\Admin\Resources\Tasks\Tables\TaskColumns;
use App\Filament\Admin\Resources\Tasks\TaskResource;
use App\Jobs\SendTeamDepartureNotices;
use Filament\Actions\Action;
use Illuminate\Notifications\Notification;

/**
 * Chuông của lần gỡ một thành viên khỏi đội ngũ hồ sơ (làn fa2, phán quyết 1) — chỉ {@see SendTeamDepartureNotices}
 * gửi. Hai loại:
 *
 *  - {@see self::MEMBER_LEFT}: gửi người BỊ GỠ — "anh/chị đã rời đội ngũ hồ sơ … — n việc đã chuyển cho X / chờ giao
 *    lại". Không tiêu đề việc (họ không còn xem được các việc đó). Mã hồ sơ chỉ hiện khi job cho phép (`$code`
 *    rỗng: "một hồ sơ") — một vụ hạn chế người đó không mở được không bao giờ hiện mã. Nút "Mở" về tab "Đang làm" của
 *    trang "Công việc" (những việc họ còn giữ), cùng chỗ của `TaskAlert::REASSIGNED_AWAY`.
 *  - {@see self::TASKS_HANDLED}: gửi người giao của các việc đó (hay người nhận thay họ —
 *    `ResolveStaffRecipients::forTaskAssigner()`, đã lọc người mở được việc) — n việc đã chuyển cho X, hay đang "Cần
 *    giao lại". Nút "Mở" về tab "Công việc" của hồ sơ. Gộp integ-fa: người nhận thay là chính người bấm (`$takenOver`
 *    — `ReassignTask` `takeOver`) thì câu `body_taken`: việc đã sang "Đang làm" của họ, không phải "Chờ nhận".
 *
 * Kênh `database`, không `ShouldQueue`, `e()` mọi giá trị nội suy. Không tên khách, không tiêu đề hồ sơ.
 * `viewData.team_departure_key` là khoá chống trùng của job.
 */
class TeamDepartureAlert extends Notification
{
    public const MEMBER_LEFT = 'member_left';

    public const TASKS_HANDLED = 'tasks_handled';

    /**
     * Sửa sau kiểm toán 2026-10-21 (mục 3): gửi luật sư phụ trách — các việc người bị gỡ đã giao trên hồ sơ nay do họ
     * theo dõi (`$moved` = số việc). Nút "Mở" về tab "Công việc" của hồ sơ.
     */
    public const TASKS_FOLLOWED = 'tasks_followed';

    /**
     * @param  string|null  $code  mã hồ sơ được phép hiện; `null`: câu chung "một hồ sơ"
     * @param  int  $moved  số việc đã chuyển cho `$to`
     * @param  int  $left  số việc để "Cần giao lại"
     * @param  bool  $takenOver  gộp integ-fa: người nhận thay chính là người bấm (việc sang "Đang làm")
     */
    public function __construct(
        private readonly string $kind,
        private readonly int $matterId,
        private readonly ?string $code,
        private readonly string $memberName,
        private readonly string $actorName,
        private readonly int $moved,
        private readonly int $left,
        private readonly string $to,
        private readonly string $key,
        private readonly bool $takenOver = false,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $variant = match (true) {
            $this->moved > 0 => $this->takenOver && $this->kind === self::TASKS_HANDLED ? 'taken' : 'moved',
            $this->left > 0 => 'left',
            default => 'plain',
        };
        $matter = $this->code === null
            ? __('internal_flow.team_removal.matter_hidden')
            : __('internal_flow.team_removal.matter_named', ['code' => e($this->code)]);
        $toMember = $this->kind === self::MEMBER_LEFT;

        return [
            'actions' => [
                Action::make('open')
                    ->label(__('internal_flow.team_removal.open'))
                    ->url($toMember
                        ? TaskResource::getUrl('index', ['tab' => 'active'], panel: 'admin')
                        : TaskColumns::matterTabUrl($this->matterId))
                    ->markAsRead()
                    ->toArray(),
            ],
            'body' => __("internal_flow.team_removal.{$this->kind}.body_{$variant}", [
                'matter' => $matter,
                'member' => e($this->memberName),
                'who' => e($this->actorName),
                'to' => e($this->to),
                'count' => max($this->moved, $this->left),
            ]),
            'color' => $variant === 'left' ? 'warning' : 'info',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => $variant === 'left' ? 'warning' : 'info',
            'title' => __("internal_flow.team_removal.{$this->kind}.title"),
            'view' => null,
            'viewData' => ['team_departure_key' => $this->key],
            'format' => 'filament',
        ];
    }
}
