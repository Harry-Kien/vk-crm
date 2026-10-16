<?php

namespace App\Filament\Admin\Resources\Matters\RelationManagers;

use App\Actions\Task\AssignTask;
use App\Actions\Task\TaskAssignees;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Filament\Admin\Concerns\ReportsActionFailures;
use App\Filament\Admin\Resources\Tasks\Concerns\ProvidesTaskActions;
use App\Filament\Admin\Resources\Tasks\Tables\TaskColumns;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Tab "Công việc" của một vụ (làn tk, Task 1) — giao việc cho người trong đội ngũ và theo dõi nó ngay
 * trong hồ sơ.
 *
 * # Hai cổng
 *
 *  - Cả TAB: `MatterPolicy::view` trên vụ chủ (như mọi tab nội dung hồ sơ); kế toán không có `matter.view`
 *    nên không có tab.
 *  - Mỗi DÒNG: `Task::visibleTo()` ghép lên quan hệ `tasks` — trong một vụ, người nhận/người giao chỉ thấy
 *    việc của mình, luật sư phụ trách và quản lý/quản trị thấy mọi việc (docblock `App\Models\Task`).
 *
 * "Giao việc" (nút tiêu đề): `TaskPolicy::assignOnMatter` — luật sư phụ trách/cộng sự của vụ, quản lý,
 * quản trị; vụ đã kết thúc thì không. Ô người nhận bày đúng {@see TaskAssignees::options()}: đội ngũ GIỮ
 * VIỆC của vụ, đang làm, mở được vụ, trừ chính người giao. Người dùng chỉ phải chọn người và gõ tiêu đề —
 * ưu tiên "Thường", không hạn là mặc định. Không một dòng nghiệp vụ nào ở đây: {@see AssignTask} và các
 * Action của nút trên dòng ({@see ProvidesTaskActions}) tự hỏi lại tất cả.
 */
class TasksRelationManager extends RelationManager
{
    use ProvidesTaskActions;
    use ReportsActionFailures;

    protected static string $relationship = 'tasks';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('tasks.title');
    }

    protected static function getModelLabel(): ?string
    {
        return __('tasks.label');
    }

    protected static function getPluralModelLabel(): ?string
    {
        return __('tasks.plural_label');
    }

    /** Cổng của cả tab — xem docblock lớp. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('view', $ownerRecord);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns(TaskColumns::make(withMatter: false))
            ->modifyQueryUsing(fn (Builder $query): Builder => TaskColumns::eagerLoad($query->visibleTo($this->taskActor()))->orderedForWork())
            ->filters([
                SelectFilter::make('status')
                    ->label(__('tasks.filters.status'))
                    ->multiple()
                    ->options(collect(TaskStatus::cases())->mapWithKeys(fn (TaskStatus $status): array => [$status->value => $status->label()])->all()),
                SelectFilter::make('assignee_id')
                    ->label(__('tasks.filters.assignee'))
                    // Người nhận của chính những việc người xem thấy trong vụ này (kể cả người đã rời đội ngũ).
                    ->options(fn (): array => User::query()
                        ->withTrashed()
                        ->whereIn('id', $this->matter()->tasks()->visibleTo($this->taskActor())->select('assignee_id'))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),
            ])
            ->headerActions([$this->assignAction()])
            ->recordActions($this->taskRecordActions())
            ->emptyStateHeading(__('tasks.empty.matter'))
            ->emptyStateDescription(__('tasks.empty.matter_hint'));
    }

    private function assignAction(): Action
    {
        return Action::make('assign')
            ->label(__('tasks.actions.assign'))
            ->icon(Heroicon::OutlinedUserPlus)
            ->authorize(fn (): bool => Gate::allows('assignOnMatter', [Task::class, $this->matter()]))
            ->modalHeading(__('tasks.actions.assign_heading'))
            ->modalSubmitActionLabel(__('tasks.actions.assign_submit'))
            // Làn fa1 (phán quyết 4): mã một lần của hộp thoại — chống bấm trùng của `AssignTask`, không theo tiêu đề.
            ->fillForm(fn (): array => ['submission_key' => (string) Str::uuid(), 'priority' => TaskPriority::Normal->value])
            ->schema(fn (): array => [
                Select::make('assignee_id')
                    ->label(__('tasks.fields.assignee'))
                    ->helperText(__('tasks.fields.assignee_help_matter'))
                    ->options(fn (): array => app(TaskAssignees::class)->options($this->matter(), $this->taskActor()))
                    ->searchable()
                    ->required()
                    ->native(false),
                ...$this->taskDetailFields(),
                Hidden::make('submission_key'),
            ])
            ->successNotificationTitle(__('tasks.actions.assign_success'))
            ->action(fn (Action $action, array $data) => $this->runAction(
                $action,
                fn () => app(AssignTask::class)->handle(
                    actor: $this->taskActor(),
                    matter: $this->matter(),
                    assignee: User::query()->withTrashed()->findOrFail($data['assignee_id'] ?? null),
                    title: (string) ($data['title'] ?? ''),
                    description: $data['description'] ?? null,
                    dueDate: $data['due_date'] ?? null,
                    priority: TaskPriority::tryFrom((string) ($data['priority'] ?? '')) ?? TaskPriority::Normal,
                    submissionKey: isset($data['submission_key']) ? (string) $data['submission_key'] : null,
                ),
            ));
    }

    private function matter(): Matter
    {
        /** @var Matter $matter */
        $matter = $this->getOwnerRecord();

        return $matter;
    }
}
