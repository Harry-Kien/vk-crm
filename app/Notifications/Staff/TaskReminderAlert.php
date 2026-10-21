<?php

namespace App\Notifications\Staff;

use App\Actions\Schedule\RemindPendingWork;
use App\Enums\TaskStatus;
use App\Filament\Admin\Resources\Tasks\TaskResource;
use App\Jobs\SendWorkReminder;
use App\Models\Task;
use App\Models\User;
use App\Support\Tasks\StandIn;
use Filament\Actions\Action;
use Illuminate\Notifications\Notification;

/**
 * Chuông của lịch nhắc VIỆC giao nội bộ (làn tk, Task 2) — sáu loại nhắc của {@see RemindPendingWork}
 * (chờ nhận quá lâu, báo người giao chưa ai nhận, sắp đến hạn, quá hạn, báo người giao việc quá hạn; từ làn fa1
 * vòng sửa 1 báo người giao việc sắp đến hạn mà người nhận không còn làm được). Chỉ
 * {@see SendWorkReminder} gửi nó, SAU khi đã lọc người nhận qua `ResolveStaffRecipients::forTask()` (còn
 * xem được việc — một việc của vụ hạn chế không bao giờ tới ai ngoài luật sư phụ trách và admin).
 *
 * Cùng khuôn {@see TaskAlert}: kênh `database`, `e()` mọi giá trị nội suy (tiêu đề việc là chữ người dùng
 * gõ), mang tiêu đề việc và mã vụ, không tên khách. `viewData.reminder_key` là khoá chống trùng của job.
 *
 * Người nhận thay người giao (vòng sửa 1, I1): các loại báo người giao ({@see RemindPendingWork::
 * isAssignerKind()}) tới luật sư phụ trách hiện tại khi người giao đã rời vụ hay nghỉ việc mang
 * `$onBehalfOf` = tên người giao — câu `*_on_behalf` ("do :assigner giao") và nút về tab "Công việc" của vụ
 * (`TaskAlert::onBehalfUrl()`), không "việc anh/chị giao" và tab "Tôi đã giao" nơi việc đó không có.
 *
 * Làn fa1 (phán quyết 5): lời báo người giao về một việc mà người nhận không còn làm được (`$needsReassignment`)
 * thêm câu `tasks.reminder_needs_reassignment` — "… không còn giữ được việc này — hãy giao lại".
 *
 * Gộp (kiểm toán 2026-10-21, Q3 — `SendTaskReminderDigest`): `$batch` khác rỗng là MỘT chuông cho nhiều việc của cùng
 * một người nhận trong một lượt — tiêu đề "n việc …" (`tasks.reminder_digest.alert.<loại>`), thân liệt kê tối đa
 * {@see self::BATCH_SHOWN} việc (tiêu đề, mã hồ sơ, hạn; người giữ với các loại báo người giao), nút về danh sách đã lọc
 * ({@see self::digestUrl()}). `$task` là việc đầu, chỉ để mang `viewData.task_id`.
 */
class TaskReminderAlert extends Notification
{
    /** Số việc tối đa liệt kê trong thân một chuông gộp (cùng ý `MatterAssignmentAlert::BATCH_CODES_SHOWN`). */
    public const BATCH_SHOWN = 10;

    /**
     * @param  string|null  $onBehalfOf  tên người giao khi người nhận nhận THAY họ (docblock lớp); `null`:
     *                                   người nhận là người nhận việc hay chính người giao
     * @param  bool  $needsReassignment  việc hiện "Cần giao lại" vì người nhận (docblock lớp; chỉ lời báo người giao)
     * @param  list<array<string, mixed>>  $batch  dòng của từng việc trong một chuông gộp (docblock lớp;
     *                                             `SendTaskReminderDigest::line()`)
     */
    public function __construct(
        private readonly Task $task,
        private readonly string $kind,
        private readonly string $key,
        private readonly string $detail,
        private readonly ?string $onBehalfOf = null,
        private readonly bool $needsReassignment = false,
        private readonly array $batch = [],
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        if ($this->batch !== []) {
            return $this->digest($notifiable);
        }

        $code = $this->task->matter?->code;
        // Kiểm toán 2026-10-21 (N1, A7): câu theo vai người nhận thay — `StandIn`.
        $standIn = self::standInRole($this->kind, $this->task, $this->onBehalfOf, $notifiable);
        $kind = str_replace('reminder.', '', $this->kind);
        $lang = StandIn::managedKey('reminder.alert', $kind, $standIn, '.title')
            ?? 'tasks.reminder.alert.'.$kind.($standIn !== null ? '_on_behalf' : '');
        $danger = in_array($this->kind, [RemindPendingWork::OVERDUE, RemindPendingWork::OVERDUE_ASSIGNER], true);

        return [
            'actions' => [
                Action::make('open')
                    ->label(__('tasks.alert.open'))
                    ->url(self::urlFor($this->kind, $this->task, $this->onBehalfOf, $notifiable))
                    ->markAsRead()
                    ->toArray(),
            ],
            'body' => __($lang.'.body', [
                'title' => e($this->task->title),
                'who' => e((string) $this->task->assignee?->name),
                'detail' => e($this->detail),
                'assigner' => e((string) $this->onBehalfOf),
            ]).($code === null ? '' : ' '.__('tasks.alert.on_matter', ['code' => e($code)]))
                .($this->needsReassignment && RemindPendingWork::isAssignerKind($this->kind)
                    ? ' '.__('tasks.reminder_needs_reassignment', ['who' => e((string) $this->task->assignee?->name)])
                    : ''),
            'color' => $danger ? 'danger' : 'warning',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => $danger ? 'danger' : 'warning',
            'title' => __($lang.'.title', ['code' => e((string) $code)]),
            'view' => null,
            'viewData' => ['task_id' => $this->task->getKey(), 'reminder_key' => $this->key],
            'format' => 'filament',
        ];
    }

    /**
     * Chuông gộp — xem docblock lớp, đoạn "Gộp". `e()` mọi phần chữ của từng dòng.
     *
     * @return array<string, mixed>
     */
    private function digest(object $notifiable): array
    {
        $kind = str_replace('reminder.', '', $this->kind);
        $danger = in_array($this->kind, [RemindPendingWork::OVERDUE, RemindPendingWork::OVERDUE_ASSIGNER], true);
        $shown = array_slice($this->batch, 0, self::BATCH_SHOWN);
        $list = implode('; ', array_map(fn (array $row): string => self::digestItem($this->kind, $row, escape: true), $shown))
            .(count($this->batch) > count($shown) ? '; …' : '');

        return [
            'actions' => [
                Action::make('open')
                    ->label(__('tasks.alert.open'))
                    ->url(self::digestUrl($this->kind, $notifiable instanceof User ? $notifiable : null, $this->batch))
                    ->markAsRead()
                    ->toArray(),
            ],
            'body' => __('tasks.reminder_digest.alert.'.$kind.'.body', ['count' => count($this->batch), 'list' => $list]),
            'color' => $danger ? 'danger' : 'warning',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => $danger ? 'danger' : 'warning',
            'title' => __('tasks.reminder_digest.alert.'.$kind.'.title', ['count' => count($this->batch)]),
            'view' => null,
            'viewData' => ['task_id' => $this->task->getKey(), 'reminder_key' => $this->key],
            'format' => 'filament',
        ];
    }

    /**
     * Một việc trong chuông gộp: `"tiêu đề" (mã hồ sơ · hạn … · người giữ · do … giao · cần giao lại)` — một định nghĩa
     * cho chuông (`$escape`) và thư gộp (`TaskReminder`; Blade tự escape).
     *
     * @param  array{title: string, code: ?string, due: ?string, assignee: string, detail: string, assigner: ?string, reassign: bool}  $row
     */
    public static function digestItem(string $kind, array $row, bool $escape = false): string
    {
        $text = fn (string $value): string => $escape ? e($value) : $value;
        $parts = array_values(array_filter([
            $row['code'] === null ? null : $text($row['code']),
            $row['due'] === null ? __('tasks.reminder_digest.no_due') : __('tasks.reminder_digest.due', ['date' => $text($row['due'])]),
            in_array($kind, [RemindPendingWork::AWAITING, RemindPendingWork::UNACCEPTED, RemindPendingWork::OVERDUE, RemindPendingWork::OVERDUE_ASSIGNER], true)
                ? __('tasks.reminder_digest.detail.'.str_replace('reminder.', '', $kind), ['detail' => $text($row['detail'])])
                : null,
            RemindPendingWork::isAssignerKind($kind) ? $text($row['assignee']) : null,
            $row['assigner'] === null ? null : __('tasks.reminder_digest.by', ['assigner' => $text($row['assigner'])]),
            $row['reassign'] ? __('tasks.reminder_digest.reassign') : null,
        ]));

        return '"'.$text($row['title']).'" ('.implode(' · ', $parts).')';
    }

    /**
     * Đường dẫn của chuông và thư gộp: lời nhắc người giữ — tab "Chờ tôi nhận", hay tab "Tất cả" lọc sẵn việc tôi giữ
     * (cùng bộ lọc ô quá hạn của bảng điều khiển, `MyWorkQueueWidget::links()`); lời báo người giao — tab "Tôi đã giao"
     * khi mọi việc do chính người nhận giao, không thì tab "Tất cả" (việc nhận thay không nằm ở "Tôi đã giao" của họ).
     *
     * @param  list<array{assigner: ?string}>  $batch
     */
    public static function digestUrl(string $kind, ?User $recipient, array $batch): string
    {
        $mine = fn (bool $overdue): array => [
            'assignee_id' => ['value' => $recipient?->getKey()],
            'status' => ['values' => [TaskStatus::Pending->value, TaskStatus::Accepted->value]],
            ...($overdue ? ['overdue' => ['isActive' => true]] : []),
        ];

        return match (true) {
            $kind === RemindPendingWork::AWAITING => TaskResource::getUrl('index', ['tab' => 'awaiting'], panel: 'admin'),
            $kind === RemindPendingWork::DUE_SOON, $kind === RemindPendingWork::OVERDUE => TaskResource::getUrl('index', [
                'tab' => 'all', 'filters' => $mine($kind === RemindPendingWork::OVERDUE),
            ], panel: 'admin'),
            collect($batch)->every(fn (array $row): bool => $row['assigner'] === null) => TaskResource::getUrl('index', ['tab' => 'assigned'], panel: 'admin'),
            default => TaskResource::getUrl('index', ['tab' => 'all'], panel: 'admin'),
        };
    }

    /**
     * Tab của trang "Công việc" mà lời nhắc trỏ tới: việc của tôi, hay việc tôi đã giao. Nhắc sắp hạn/quá hạn
     * cho người nhận trỏ tab của TRẠNG THÁI việc (vòng sửa cuối 1, F5): việc chưa nhận nằm ở "Chờ tôi nhận",
     * không ở "Đang làm".
     */
    public static function tabFor(string $kind, Task $task): string
    {
        return match ($kind) {
            RemindPendingWork::AWAITING => 'awaiting',
            RemindPendingWork::DUE_SOON, RemindPendingWork::OVERDUE => $task->status === TaskStatus::Pending ? 'awaiting' : 'active',
            default => 'assigned',
        };
    }

    /**
     * Vai người nhận THAY người giao (docblock lớp; kiểm toán 2026-10-21, N1/A7 — `StandIn::role()`) của một loại báo người
     * giao; `null`: người nhận là người nhận việc hay chính người giao. Một định nghĩa cho chuông và thư (`TaskReminder`).
     */
    public static function standInRole(string $kind, Task $task, ?string $onBehalfOf, ?object $recipient): ?string
    {
        return RemindPendingWork::isAssignerKind($kind) ? StandIn::role($task, $onBehalfOf, $recipient) : null;
    }

    /**
     * Đường dẫn của lời nhắc: trang của người nhận thay (`StandIn::url()` — tab "Công việc" của hồ sơ, hay trang Công việc
     * lọc sẵn người giao với việc chung), không thì tab của {@see self::tabFor()}.
     */
    public static function urlFor(string $kind, Task $task, ?string $onBehalfOf, ?object $recipient = null): string
    {
        $standIn = self::standInRole($kind, $task, $onBehalfOf, $recipient);

        return $standIn !== null
            ? StandIn::url($task, $standIn)
            : TaskResource::getUrl('index', ['tab' => self::tabFor($kind, $task)], panel: 'admin');
    }
}
