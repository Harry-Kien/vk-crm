<?php

namespace App\Filament\Admin\Resources\Tasks\Pages;

use App\Actions\Task\AssignTask;
use App\Actions\Task\TaskAssignees;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Filament\Admin\Concerns\ReportsActionFailures;
use App\Filament\Admin\Resources\Tasks\Concerns\ProvidesTaskActions;
use App\Filament\Admin\Resources\Tasks\Tables\TaskColumns;
use App\Filament\Admin\Resources\Tasks\TaskResource;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Trang "Công việc" — xem docblock {@see TaskResource}. Bốn tab trên CÙNG một phạm vi
 * (`TaskResource::getEloquentQuery()`), mỗi tab chỉ thu hẹp bằng một scope của `Task`:
 *
 *  - "Chờ tôi nhận" (`awaitingAcceptanceBy`): việc giao cho tôi, chưa nhận — nút "Đã nhận" ngay trên dòng;
 *  - "Đang làm" (`inProgressFor`): việc tôi đã nhận — hạn tô màu theo độ gấp;
 *  - "Tôi đã giao" (`assignedBy`): việc tôi giao còn mở, kể cả việc bị trả lại (lý do ngay dưới trạng
 *    thái) để giao lại hoặc huỷ, và việc hoàn thành trong `Task::RECENTLY_COMPLETED_DAYS` ngày qua (ghi
 *    chú kết quả ngay dưới trạng thái);
 *  - "Tất cả": mọi việc tôi được thấy — với quản lý/quản trị là danh sách theo dõi toàn văn phòng (trong
 *    phạm vi vụ họ xem được), có bộ lọc vụ việc, người nhận, người giao, quá hạn, cần giao lại (`Task::scopeNeedsReassignment()`).
 *
 * Bộ lọc "Vụ việc" chỉ bày mã của những vụ có ít nhất một việc người xem THẤY ĐƯỢC
 * ({@see self::matterOptions()}): mã một vụ `restricted` không bao giờ vào ô chọn của người không xem
 * được nó; một id gửi tay lên bộ lọc vẫn chỉ lọc bên trong `visibleTo()`, nên không ra dòng nào.
 *
 * "Giao việc chung" (nút tiêu đề, tên `create` = `TaskPolicy::create`, chỉ quản lý/quản trị); việc của
 * một vụ được giao ở tab "Công việc" của vụ đó, nơi danh sách người nhận là đội ngũ của vụ.
 */
class ListTasks extends ListRecords
{
    use ProvidesTaskActions;
    use ReportsActionFailures;

    protected static string $resource = TaskResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('tasks.title');
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $user = $this->taskActor();

        return [
            'awaiting' => Tab::make(__('tasks.tabs.awaiting'))
                ->badge(fn (): ?int => ($count = Task::query()->visibleTo($user)->awaitingAcceptanceBy($user)->count()) > 0 ? $count : null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->awaitingAcceptanceBy($user)),
            'active' => Tab::make(__('tasks.tabs.active'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->inProgressFor($user)),
            'assigned' => Tab::make(__('tasks.tabs.assigned'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->assignedBy($user)),
            'all' => Tab::make(__('tasks.tabs.all')),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns(TaskColumns::make())
            ->modifyQueryUsing(fn (Builder $query): Builder => TaskColumns::eagerLoad($query)
                ->orderByRaw('CASE WHEN tasks.due_date IS NULL THEN 1 ELSE 0 END')
                ->orderBy('tasks.due_date')
                ->orderByDesc('tasks.id'))
            ->filters([
                SelectFilter::make('status')
                    ->label(__('tasks.filters.status'))
                    ->multiple()
                    ->options(collect(TaskStatus::cases())->mapWithKeys(fn (TaskStatus $status): array => [$status->value => $status->label()])->all()),
                SelectFilter::make('matter_id')
                    ->label(__('tasks.filters.matter'))
                    ->options(fn (): array => $this->matterOptions())
                    ->searchable(),
                SelectFilter::make('assignee_id')
                    ->label(__('tasks.filters.assignee'))
                    ->options(fn (): array => self::staffOptions())
                    ->searchable(),
                SelectFilter::make('assigned_by')
                    ->label(__('tasks.filters.assigner'))
                    ->options(fn (): array => self::staffOptions())
                    ->searchable(),
                SelectFilter::make('priority')
                    ->label(__('tasks.filters.priority'))
                    ->options(TaskPriority::options()),
                Filter::make('overdue')
                    ->label(__('tasks.filters.overdue'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->overdue()),
                Filter::make('needs_reassignment')
                    ->label(__('tasks.filters.needs_reassignment'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->needsReassignment()),
            ])
            ->filtersLayout(FiltersLayout::AboveContentCollapsible)
            ->recordActions($this->taskRecordActions())
            ->emptyStateHeading(fn (): string => __('tasks.empty.'.($this->activeTab ?? 'all')))
            ->paginated([10, 25, 50]);
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [$this->assignGeneralTaskAction()];
    }

    /**
     * "Giao việc chung" — tên `create` (`TaskPolicy::create`: quản lý, quản trị). Người nhận: mọi nhân sự
     * đang làm ({@see TaskAssignees::options()} với vụ rỗng), trừ chính người giao. `AssignTask` hỏi lại
     * dưới khoá.
     */
    protected function assignGeneralTaskAction(): Action
    {
        return Action::make('create')
            ->label(__('tasks.actions.assign'))
            ->icon(Heroicon::OutlinedPlus)
            ->authorize(fn (): bool => Gate::allows('create', Task::class))
            ->modalHeading(__('tasks.actions.assign_general_heading'))
            ->modalSubmitActionLabel(__('tasks.actions.assign_submit'))
            ->schema(fn (): array => [
                Select::make('assignee_id')
                    ->label(__('tasks.fields.assignee'))
                    ->helperText(__('tasks.fields.assignee_help_general'))
                    ->options(fn (): array => app(TaskAssignees::class)->options(null, $this->taskActor()))
                    ->searchable()
                    ->required()
                    ->native(false),
                ...$this->taskDetailFields(),
            ])
            ->successNotificationTitle(__('tasks.actions.assign_success'))
            ->action(fn (Action $action, array $data) => $this->runAction(
                $action,
                fn () => app(AssignTask::class)->handle(
                    actor: $this->taskActor(),
                    matter: null,
                    assignee: User::query()->withTrashed()->findOrFail($data['assignee_id'] ?? null),
                    title: (string) ($data['title'] ?? ''),
                    description: $data['description'] ?? null,
                    dueDate: $data['due_date'] ?? null,
                    priority: TaskPriority::tryFrom((string) ($data['priority'] ?? '')) ?? TaskPriority::Normal,
                ),
            ));
    }

    /**
     * `id => mã` của các vụ có ít nhất một việc người xem thấy được (`Task::visibleTo()` — cùng luật của
     * danh sách, nên vụ `restricted` ngoài tầm không bao giờ có mặt), theo mã. Chỉ mã: không tiêu đề vụ,
     * không tên khách.
     *
     * @return array<int, string>
     */
    private function matterOptions(): array
    {
        return Matter::query()
            ->whereIn('matters.id', Task::query()->visibleTo($this->taskActor())->whereNotNull('tasks.matter_id')->select('tasks.matter_id'))
            ->orderBy('matters.code')
            ->pluck('matters.code', 'matters.id')
            ->all();
    }

    /**
     * Mọi nhân sự (kể cả đã nghỉ — việc cũ của họ vẫn lọc được), tên và id. Tên đồng nghiệp không phải dữ
     * liệu hồ sơ; danh sách VIỆC vẫn chỉ trong `visibleTo()`.
     *
     * @return array<int, string>
     */
    private static function staffOptions(): array
    {
        return User::query()->withTrashed()->orderBy('name')->pluck('name', 'id')->all();
    }
}
