<?php

namespace App\Filament\Admin\Resources\Tasks\Concerns;

use App\Actions\Task\AcceptTask;
use App\Actions\Task\CancelTask;
use App\Actions\Task\CompleteTask;
use App\Actions\Task\DeclineTask;
use App\Actions\Task\ReassignTask;
use App\Actions\Task\ReopenTask;
use App\Actions\Task\RestoreTask;
use App\Actions\Task\TaskAssignees;
use App\Actions\Task\UpdateTask;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Filament\Admin\Concerns\ReportsActionFailures;
use App\Models\Task;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Các nút trên MỘT DÒNG việc — dùng chung bởi trang "Công việc", tab "Công việc" của vụ và ba khối "Việc
 * của tôi" (làn tk, Task 1). Lớp dùng trait phải dùng cả {@see ReportsActionFailures}: mọi lời từ chối của
 * Action thành một thông báo tiếng Việt (hay một câu gắn vào đúng ô), không thành trang lỗi.
 *
 * # Không một dòng nghiệp vụ nào ở đây
 *
 * Mỗi nút gọi đúng một Action trong `app/Actions/Task/`, Action tự hỏi lại quyền và trạng thái dưới khoá.
 * `->authorize()` hỏi `Gate` (policy đã gồm trạng thái: "Đã nhận" chỉ hiện trên việc đang chờ, chỉ cho
 * người nhận) để ẨN nút — không phải cổng thật.
 *
 * # Thao tác cho người bận
 *
 * "Đã nhận" là MỘT cú bấm, không hộp xác nhận. "Trả lại" và "Huỷ việc" bắt buộc lý do. "Hoàn thành" mở
 * một khung với ghi chú tuỳ chọn (khung đó cũng là bước xác nhận). Ba nút của người nhận đứng ngoài;
 * các nút của người quản lý (sửa, giao lại, huỷ — và từ làn fa1 "Mở lại" việc đã hoàn thành, "Khôi phục" việc
 * đã huỷ) gom vào một nhóm để dòng không chật.
 *
 * # "Mở lại" và "Khôi phục" trên vụ đã kết thúc (làn fa1)
 *
 * Nút vẫn hiện cho người có quyền, nhưng KHÔNG mở hộp thoại (`modalHidden`): bấm là `ReopenTask`/`RestoreTask`
 * chạy ngay và trả về câu từ chối rõ ràng ("hồ sơ đã kết thúc…") — không bắt người dùng gõ lý do rồi mới nghe
 * từ chối. Một hộp thoại mở TRƯỚC khi vụ kết thúc thì nhận cùng câu đó khi bấm lưu.
 */
trait ProvidesTaskActions
{
    /** @return list<Action|ActionGroup> */
    protected function taskRecordActions(): array
    {
        return [
            $this->acceptTaskAction(),
            $this->declineTaskAction(),
            $this->completeTaskAction(),
            $this->viewTaskAction(),
            ActionGroup::make([
                $this->editTaskAction(),
                $this->reassignTaskAction(),
                $this->cancelTaskAction(),
                $this->reopenTaskAction(),
                $this->restoreTaskAction(),
            ]),
        ];
    }

    /**
     * "Mở lại" một việc đã hoàn thành — `TaskPolicy::reopen` (người giao, luật sư phụ trách, quản lý; chính người
     * đã hoàn thành trong 24 giờ đầu). Lý do bắt buộc trừ khi người bấm là người đã hoàn thành (bấm nhầm).
     */
    protected function reopenTaskAction(): Action
    {
        $own = fn (Task $record): bool => $record->assignee_id === $this->taskActor()->getKey();

        return Action::make('reopen')
            ->label(__('tasks.reopen.action'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->authorize(fn (Task $record): bool => Gate::allows('reopen', $record))
            ->modalHidden(fn (Task $record): bool => self::onClosedMatter($record))
            ->modalHeading(fn (Task $record): string => __($own($record) ? 'tasks.reopen.heading_own' : 'tasks.reopen.heading'))
            ->modalDescription(fn (Task $record): string => __($own($record) ? 'tasks.reopen.description_own' : 'tasks.reopen.description'))
            ->modalSubmitActionLabel(__('tasks.reopen.submit'))
            ->schema(fn (Task $record): array => self::onClosedMatter($record) ? [] : [
                Textarea::make('reason')
                    ->label(__('tasks.fields.reason'))
                    ->helperText(__($own($record) ? 'tasks.reopen.reason_help_own' : 'tasks.reopen.reason_help'))
                    ->required(fn (): bool => ! $own($record))
                    ->maxLength(Task::REASON_MAX_LENGTH)
                    ->rows(3),
            ])
            // Làn fa1, vòng sửa 1 (m2): người giữ không còn giữ được việc thì không ai được báo — câu xong nói "Cần giao lại".
            ->successNotificationTitle(fn (Task $record): string => __(Task::query()->whereKey($record->getKey())->needsReassignment()->exists()
                ? 'tasks.reopen.success_needs_reassignment'
                : 'tasks.reopen.success'))
            ->action(fn (Action $action, Task $record, array $data) => $this->runAction(
                $action,
                fn () => app(ReopenTask::class)->handle($record, $this->taskActor(), $data['reason'] ?? null),
            ));
    }

    /**
     * "Khôi phục" một việc đã huỷ — `TaskPolicy::restoreCancelled` (chính người đã huỷ, luật sư phụ trách, quản lý).
     * Lý do bắt buộc. Câu báo xong nói rõ khi việc về "Bị trả lại" (huỷ lúc đang bị trả lại — làn fa1, vòng sửa 1, m1),
     * khi người nhận cũ không còn nhận được việc (việc hiện "Cần giao lại"), hay khi người bấm là chính người nhận cũ
     * (việc về "Đang làm" cho họ — vòng sửa 2, c4).
     */
    protected function restoreTaskAction(): Action
    {
        return Action::make('restore')
            ->label(__('tasks.restore.action'))
            ->icon(Heroicon::OutlinedArrowUturnUp)
            ->color('warning')
            ->authorize(fn (Task $record): bool => Gate::allows('restoreCancelled', $record))
            ->modalHidden(fn (Task $record): bool => self::onClosedMatter($record))
            ->modalHeading(__('tasks.restore.heading'))
            ->modalDescription(__('tasks.restore.description'))
            ->modalSubmitActionLabel(__('tasks.restore.submit'))
            ->schema(fn (Task $record): array => self::onClosedMatter($record) ? [] : [
                Textarea::make('reason')
                    ->label(__('tasks.fields.reason'))
                    ->helperText(__('tasks.restore.reason_help'))
                    ->required()
                    ->maxLength(Task::REASON_MAX_LENGTH)
                    ->rows(3),
            ])
            ->successNotificationTitle(fn (Task $record): string => __(match (true) {
                Task::query()->find($record->getKey())?->status === TaskStatus::Declined => 'tasks.restore.success_declined',
                Task::query()->whereKey($record->getKey())->needsReassignment()->exists() => 'tasks.restore.success_needs_reassignment',
                // Làn fa1, vòng sửa 2 (c4): người bấm là người nhận cũ — việc về "Đang làm" cho chính họ, không ai được báo.
                Task::query()->find($record->getKey())?->assignee_id === $this->taskActor()->getKey() => 'tasks.restore.success_own',
                default => 'tasks.restore.success',
            }))
            ->action(fn (Action $action, Task $record, array $data) => $this->runAction(
                $action,
                fn () => app(RestoreTask::class)->handle($record, $this->taskActor(), (string) ($data['reason'] ?? '')),
            ));
    }

    /** Việc của một vụ đã kết thúc — xem docblock lớp, mục "Mở lại và Khôi phục trên vụ đã kết thúc". */
    private static function onClosedMatter(Task $record): bool
    {
        return $record->matter_id !== null && $record->matter?->isOpen() !== true;
    }

    protected function acceptTaskAction(): Action
    {
        return Action::make('accept')
            ->label(__('tasks.actions.accept'))
            ->icon(Heroicon::OutlinedHandThumbUp)
            ->color('success')
            ->button()
            ->size('sm')
            ->authorize(fn (Task $record): bool => Gate::allows('accept', $record))
            ->successNotificationTitle(__('tasks.actions.accept_success'))
            ->action(fn (Action $action, Task $record) => $this->runAction(
                $action,
                fn () => app(AcceptTask::class)->handle($record, $this->taskActor()),
            ));
    }

    protected function declineTaskAction(): Action
    {
        return Action::make('decline')
            ->label(__('tasks.actions.decline'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->authorize(fn (Task $record): bool => Gate::allows('decline', $record))
            ->modalHeading(__('tasks.actions.decline_heading'))
            ->modalSubmitActionLabel(__('tasks.actions.decline_submit'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('tasks.fields.reason'))
                    ->helperText(__('tasks.fields.decline_reason_help'))
                    ->required()
                    ->maxLength(Task::REASON_MAX_LENGTH)
                    ->rows(3),
            ])
            ->successNotificationTitle(__('tasks.actions.decline_success'))
            ->action(fn (Action $action, Task $record, array $data) => $this->runAction(
                $action,
                fn () => app(DeclineTask::class)->handle($record, $this->taskActor(), (string) ($data['reason'] ?? '')),
            ));
    }

    protected function completeTaskAction(): Action
    {
        return Action::make('complete')
            ->label(__('tasks.actions.complete'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorize(fn (Task $record): bool => Gate::allows('complete', $record))
            ->modalHeading(__('tasks.actions.complete_heading'))
            ->modalSubmitActionLabel(__('tasks.actions.complete_submit'))
            ->schema([
                Textarea::make('note')
                    ->label(__('tasks.fields.note'))
                    ->helperText(__('tasks.fields.note_help'))
                    ->maxLength(Task::NOTE_MAX_LENGTH)
                    ->rows(3),
            ])
            ->successNotificationTitle(__('tasks.actions.complete_success'))
            ->action(fn (Action $action, Task $record, array $data) => $this->runAction(
                $action,
                fn () => app(CompleteTask::class)->handle($record, $this->taskActor(), $data['note'] ?? null),
            ));
    }

    /**
     * "Xem" — khung chỉ đọc: mô tả, các mốc, lý do/ghi chú. Mã vụ, KHÔNG tên khách. Cổng: `TaskPolicy::view`
     * (mọi dòng của bảng đã qua `visibleTo()`; hỏi lại để nút không bao giờ mở một việc người xem không thấy).
     */
    protected function viewTaskAction(): Action
    {
        return Action::make('view')
            ->label(__('tasks.actions.view'))
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->authorize(fn (Task $record): bool => Gate::allows('view', $record))
            ->modalHeading(fn (Task $record): string => $record->title)
            ->modalContent(fn (Task $record) => view('filament.admin.tasks.task-detail', ['task' => $record]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('tasks.actions.close'));
    }

    /**
     * "Sửa" — tiêu đề, hạn, ưu tiên, mô tả; người nhận không đổi ở đây ({@see self::reassignTaskAction()}).
     * Ô ẩn `mounted_updated_at` mang `updated_at` lúc mở khung: `UpdateTask` từ chối khi dòng đã đổi.
     */
    protected function editTaskAction(): Action
    {
        return Action::make('edit')
            ->label(__('tasks.actions.edit'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->authorize(fn (Task $record): bool => Gate::allows('update', $record))
            ->modalHeading(__('tasks.actions.edit_heading'))
            ->modalSubmitActionLabel(__('tasks.actions.edit_submit'))
            ->fillForm(fn (Task $record): array => [
                'title' => $record->title,
                'due_date' => $record->due_date?->toDateString(),
                'priority' => $record->priority->value,
                'description' => $record->description,
                'mounted_updated_at' => $record->updated_at?->toDateTimeString(),
            ])
            ->schema([
                ...$this->taskDetailFields(),
                Hidden::make('mounted_updated_at'),
            ])
            // Làn fa1, vòng sửa 2 (c2): người giữ không còn giữ được việc thì không ai được báo — câu xong nói "Cần giao lại".
            ->successNotificationTitle(fn (Task $record): string => __(Task::query()->whereKey($record->getKey())->held()->needsReassignment()->exists()
                ? 'tasks.actions.edit_success_needs_reassignment'
                : 'tasks.actions.edit_success'))
            ->action(fn (Action $action, Task $record, array $data) => $this->runAction(
                $action,
                fn () => app(UpdateTask::class)->handle(
                    task: $record,
                    actor: $this->taskActor(),
                    title: (string) ($data['title'] ?? ''),
                    description: $data['description'] ?? null,
                    dueDate: $data['due_date'] ?? null,
                    priority: TaskPriority::tryFrom((string) ($data['priority'] ?? '')) ?? TaskPriority::Normal,
                    expectedUpdatedAt: $data['mounted_updated_at'] ?? null,
                ),
            ));
    }

    /**
     * "Giao lại" — ô chọn bày đúng {@see TaskAssignees::options()} (đội ngũ giữ việc của vụ, hay mọi nhân
     * sự đang làm với việc chung; người đang giữ chỉ còn khi họ đã trả lại). `ReassignTask` hỏi lại dưới khoá.
     *
     * Làn fa1 (phán quyết 7): ô "Hạn" điền sẵn {@see self::suggestedDueDate()} — hạn cũ nếu còn ở hôm nay hay tương
     * lai, một hạn gợi ý nếu đã qua; người giao đổi được (một ngày đã qua chỉ khi đó đúng là hạn cũ).
     */
    protected function reassignTaskAction(): Action
    {
        return Action::make('reassign')
            ->label(__('tasks.actions.reassign'))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('gray')
            ->authorize(fn (Task $record): bool => Gate::allows('reassign', $record))
            ->modalHeading(__('tasks.actions.reassign_heading'))
            ->modalSubmitActionLabel(__('tasks.actions.reassign_submit'))
            ->fillForm(fn (Task $record): array => ['due_date' => self::suggestedDueDate($record)])
            ->schema(fn (Task $record): array => [
                Select::make('assignee_id')
                    ->label(__('tasks.fields.assignee'))
                    ->helperText(self::assigneeHelp($record->matter_id === null ? 'tasks.fields.assignee_help_general' : 'tasks.fields.assignee_help_matter'))
                    ->options(fn (): array => app(TaskAssignees::class)->options($record->matter, $this->taskActor(), $record))
                    ->searchable()
                    ->required()
                    ->native(false),
                DatePicker::make('due_date')
                    ->label(__('tasks.fields.due_date'))
                    ->helperText(fn (): string => $record->due_date !== null && $record->due_date->toDateString() < today()->toDateString()
                        ? __('tasks.reassign.due_past_help', ['date' => $record->due_date->format('d/m/Y')])
                        : __('tasks.fields.due_date_help'))
                    ->native(false)
                    ->displayFormat('d/m/Y'),
            ])
            ->successNotificationTitle(__('tasks.actions.reassign_success'))
            ->action(fn (Action $action, Task $record, array $data) => $this->runAction(
                $action,
                fn () => app(ReassignTask::class)->handle(
                    $record,
                    $this->taskActor(),
                    User::query()->withTrashed()->findOrFail($data['assignee_id'] ?? null),
                    $data['due_date'] ?? null,
                    setDueDate: true,
                ),
            ));
    }

    /**
     * Câu gợi ý của một ô chọn người nhận (`$key` — ai nhận được) cộng câu phạm vi của số việc bên mỗi tên
     * (`tasks.assign.workload_scope` — làn fa1, vòng sửa 1, I2): nhãn của {@see TaskAssignees::options()} chỉ đếm việc
     * người giao xem được, nên người giao phải đọc được giới hạn đó ngay trên màn hình. Chung cho "Giao việc" (trang
     * Công việc, tab "Công việc" của vụ) và "Giao lại".
     */
    protected static function assigneeHelp(string $key): string
    {
        return __($key).' '.__('tasks.assign.workload_scope');
    }

    /**
     * Hạn điền sẵn khi "Giao lại" (làn fa1): không hạn thì không hạn; hạn còn ở hôm nay hay tương lai thì giữ; hạn
     * đã qua thì gợi ý hôm nay cộng đúng số ngày người giữ cũ có (từ lúc giao tới hạn cũ, ít nhất một ngày) — người
     * mới có cùng khoảng thời gian để làm.
     */
    private static function suggestedDueDate(Task $record): ?string
    {
        $due = $record->due_date;

        if ($due === null || $due->toDateString() >= today()->toDateString()) {
            return $due?->toDateString();
        }

        $given = $record->assigned_at === null
            ? 1
            : (int) $record->assigned_at->copy()->setTimezone((string) config('app.timezone'))->startOfDay()->diffInDays($due->copy()->startOfDay());

        return today()->addDays(max(1, $given))->toDateString();
    }

    protected function cancelTaskAction(): Action
    {
        return Action::make('cancel')
            ->label(__('tasks.actions.cancel'))
            ->icon(Heroicon::OutlinedXMark)
            ->color('danger')
            ->authorize(fn (Task $record): bool => Gate::allows('cancel', $record))
            ->modalHeading(__('tasks.actions.cancel_heading'))
            ->modalSubmitActionLabel(__('tasks.actions.cancel_submit'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('tasks.fields.reason'))
                    ->helperText(__('tasks.fields.cancel_reason_help'))
                    ->required()
                    ->maxLength(Task::REASON_MAX_LENGTH)
                    ->rows(3),
            ])
            ->successNotificationTitle(__('tasks.actions.cancel_success'))
            ->action(fn (Action $action, Task $record, array $data) => $this->runAction(
                $action,
                fn () => app(CancelTask::class)->handle($record, $this->taskActor(), (string) ($data['reason'] ?? '')),
            ));
    }

    /**
     * Bốn ô nội dung của một việc, chung cho "Giao việc" và "Sửa": độ dài = độ dài cột (MariaDB strict).
     * Mặc định người dùng chỉ phải gõ TIÊU ĐỀ — ưu tiên "Thường", không hạn.
     *
     * @return list<TextInput|DatePicker|Select|Textarea>
     */
    protected function taskDetailFields(): array
    {
        return [
            TextInput::make('title')
                ->label(__('tasks.fields.title'))
                ->helperText(__('tasks.fields.title_help'))
                ->required()
                ->maxLength(Task::TITLE_MAX_LENGTH)
                ->columnSpanFull(),
            DatePicker::make('due_date')
                ->label(__('tasks.fields.due_date'))
                ->helperText(__('tasks.fields.due_date_help'))
                ->native(false)
                ->displayFormat('d/m/Y'),
            Select::make('priority')
                ->label(__('tasks.fields.priority'))
                ->options(TaskPriority::options())
                ->default(TaskPriority::Normal->value)
                ->required()
                ->native(false),
            Textarea::make('description')
                ->label(__('tasks.fields.description'))
                ->maxLength(Task::DESCRIPTION_MAX_LENGTH)
                ->rows(3)
                ->columnSpanFull(),
        ];
    }

    protected function taskActor(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
