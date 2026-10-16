<?php

namespace App\Filament\Admin\Resources\Tasks\Concerns;

use App\Actions\Task\AcceptTask;
use App\Actions\Task\CancelTask;
use App\Actions\Task\CompleteTask;
use App\Actions\Task\DeclineTask;
use App\Actions\Task\ReassignTask;
use App\Actions\Task\TaskAssignees;
use App\Actions\Task\UpdateTask;
use App\Enums\TaskPriority;
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
 * ba nút của người quản lý (sửa, giao lại, huỷ) gom vào một nhóm để dòng không chật.
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
            ]),
        ];
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
            ->successNotificationTitle(__('tasks.actions.edit_success'))
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
            ->schema(fn (Task $record): array => [
                Select::make('assignee_id')
                    ->label(__('tasks.fields.assignee'))
                    ->options(fn (): array => app(TaskAssignees::class)->options($record->matter, $this->taskActor(), $record))
                    ->searchable()
                    ->required()
                    ->native(false),
            ])
            ->successNotificationTitle(__('tasks.actions.reassign_success'))
            ->action(fn (Action $action, Task $record, array $data) => $this->runAction(
                $action,
                fn () => app(ReassignTask::class)->handle(
                    $record,
                    $this->taskActor(),
                    User::query()->withTrashed()->findOrFail($data['assignee_id'] ?? null),
                ),
            ));
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
