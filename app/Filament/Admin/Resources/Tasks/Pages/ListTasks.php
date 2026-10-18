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
use App\Filament\Admin\Widgets\Tasks\MatterAssignmentsAwaitingMeWidget;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use App\Support\Search\LetterD;
use App\Support\Tasks\SeatInbox;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

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
 * Mọi tab cùng một thứ tự (`Task::scopeOrderedForWork()`): việc còn mở trước theo hạn, việc đã đóng sau.
 * Bộ lọc "Chỉ việc quá hạn" là việc còn mở đã qua hạn trên vụ còn mở hay việc chung, mà người nhận còn GIỮ ĐƯỢC
 * (`overdue()` + `onLiveMatter()` + `heldBySeatedAssignee()` — làn fa1, phán quyết 5) — cùng định nghĩa với ô quá
 * hạn của bảng điều khiển và lịch nhắc (`Task::scopeOverdueToHandle()`): việc của vụ đã kết thúc chỉ còn huỷ,
 * không còn "quá hạn" để đuổi theo; việc của người không còn giữ được nằm ở bộ lọc "Cần giao lại".
 *
 * Bộ lọc "Vụ việc" chỉ bày mã của những vụ có ít nhất một việc người xem THẤY ĐƯỢC
 * ({@see self::matterOptions()}): mã một vụ `restricted` không bao giờ vào ô chọn của người không xem
 * được nó; một id gửi tay lên bộ lọc vẫn chỉ lọc bên trong `visibleTo()`, nên không ra dòng nào.
 *
 * "Giao việc" (nút tiêu đề, tên `create` = `TaskPolicy::create` — làn fa1: mọi nhân sự có `matter.view`): một
 * việc chung, hay — chọn ô "Vụ việc" — một việc của một vụ người giao được giao việc ({@see self::assignTaskAction()});
 * tab "Công việc" của từng vụ vẫn giao được như trước. Dòng mô tả đầu trang ({@see self::getSubheading()}) nói
 * trang chứa gì và — với người nhận hồ sơ ở "Việc của tôi" — hồ sơ chờ nhận nằm ở đó; tab "Chờ tôi nhận" rỗng mà
 * còn hồ sơ chờ nhận thì câu rỗng chỉ đúng chỗ đó ({@see self::awaitingEmptyHint()}).
 */
class ListTasks extends ListRecords
{
    use ProvidesTaskActions;
    use ReportsActionFailures;

    protected static string $resource = TaskResource::class;

    /**
     * Kết quả {@see TaskAssignees::assignableMatters()} của request này (làn fa1, vòng sửa 1 — rà soát Task 1, m8): ô
     * "Vụ việc" (options, visible) và {@see self::assignMatter()} (ô người nhận, lần lưu) cùng đọc nó, mỗi lần đọc là
     * một cổng `assignOnMatter` cho từng vụ đang mở (quản trị viên: mọi vụ). Thuộc tính `protected` — Livewire không
     * đưa nó vào trạng thái của component, nên mỗi request tính lại đúng một lần (quyền đổi giữa hai request vẫn thấy).
     *
     * @var array<int, string>|null
     */
    protected ?array $assignableMattersCache = null;

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
            ->modifyQueryUsing(fn (Builder $query): Builder => TaskColumns::eagerLoad($query)->orderedForWork())
            ->filters([
                SelectFilter::make('status')
                    ->label(__('tasks.filters.status'))
                    ->multiple()
                    ->options(collect(TaskStatus::cases())->mapWithKeys(fn (TaskStatus $status): array => [$status->value => $status->label()])->all()),
                // Gộp integ-fa (làn fa3): ba ô lọc tìm trên máy chủ trong ĐÚNG danh sách của `->options()` ("dinh" ra
                // "Đinh") — tầm nhìn không đổi; SelectFilter không có searchDebounce(), đặt trên ô chọn nó dựng.
                SelectFilter::make('matter_id')
                    ->label(__('tasks.filters.matter'))
                    ->options(fn (): array => $this->matterOptions())
                    ->searchable()
                    ->getSearchResultsUsing(LetterD::searchOptions(fn (): array => $this->matterOptions()))
                    ->modifyFormFieldUsing(fn (Select $field): Select => $field->searchDebounce(LetterD::SELECT_SEARCH_DEBOUNCE_MS)),
                SelectFilter::make('assignee_id')
                    ->label(__('tasks.filters.assignee'))
                    ->options(fn (): array => self::staffOptions())
                    ->searchable()
                    ->getSearchResultsUsing(LetterD::searchOptions(fn (): array => self::staffOptions()))
                    ->modifyFormFieldUsing(fn (Select $field): Select => $field->searchDebounce(LetterD::SELECT_SEARCH_DEBOUNCE_MS)),
                SelectFilter::make('assigned_by')
                    ->label(__('tasks.filters.assigner'))
                    ->options(fn (): array => self::staffOptions())
                    ->searchable()
                    ->getSearchResultsUsing(LetterD::searchOptions(fn (): array => self::staffOptions()))
                    ->modifyFormFieldUsing(fn (Select $field): Select => $field->searchDebounce(LetterD::SELECT_SEARCH_DEBOUNCE_MS)),
                SelectFilter::make('priority')
                    ->label(__('tasks.filters.priority'))
                    ->options(TaskPriority::options()),
                Filter::make('overdue')
                    ->label(__('tasks.filters.overdue'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->overdue()->onLiveMatter()->heldBySeatedAssignee()),
                Filter::make('needs_reassignment')
                    ->label(__('tasks.filters.needs_reassignment'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->needsReassignment()),
            ])
            ->filtersLayout(FiltersLayout::AboveContentCollapsible)
            ->recordActions($this->taskRecordActions())
            ->emptyStateHeading(fn (): string => __('tasks.empty.'.($this->activeTab ?? 'all')))
            ->emptyStateDescription(fn (): ?string => $this->awaitingEmptyHint())
            ->paginated([10, 25, 50]);
    }

    /**
     * Làn fa1 (phán quyết 7): một dòng mô tả đầu trang — trang này chứa gì, hồ sơ chờ nhận nằm ở đâu. Vế "hồ sơ chờ
     * nhận ở mục Việc của tôi" chỉ cho người nhận hồ sơ ở đó ({@see SeatInbox::onMyWork()} — luật sư, trợ lý, trưởng
     * phòng; vòng sửa 1, m3): quản trị viên nhận hồ sơ ở tab "Đội ngũ", kế toán và kinh doanh không có mục đó.
     */
    public function getSubheading(): string|Htmlable|null
    {
        return __(SeatInbox::onMyWork($this->taskActor()) ? 'tasks.page.subheading' : 'tasks.page.subheading_short');
    }

    /**
     * {@see TaskAssignees::assignableMatters()} một lần mỗi request — xem {@see self::$assignableMattersCache}.
     *
     * @return array<int, string>
     */
    private function assignableMatters(): array
    {
        return $this->assignableMattersCache ??= app(TaskAssignees::class)->assignableMatters($this->taskActor());
    }

    /**
     * Câu thêm dưới câu rỗng của tab "Chờ tôi nhận" (làn fa1, phán quyết 7): người xem không có việc nào chờ nhận
     * nhưng có HỒ SƠ chờ nhận (ghế trong đội ngũ vụ — `MatterAssignmentsAwaitingMeWidget::seats()`, cùng tập của ô
     * "Hồ sơ chờ tôi nhận") thì chỉ đúng chỗ bấm "Đã nhận hồ sơ" ({@see SeatInbox::whereKey()}). Tab khác: không.
     */
    private function awaitingEmptyHint(): ?string
    {
        if (($this->activeTab ?? 'all') !== 'awaiting') {
            return null;
        }

        $user = $this->taskActor();
        $seats = MatterAssignmentsAwaitingMeWidget::seats($user)->count();

        return $seats === 0 ? null : __('tasks.page.awaiting_empty_seats', ['count' => $seats, 'where' => __(SeatInbox::whereKey($user))]);
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [$this->assignTaskAction()];
    }

    /**
     * "Giao việc" — tên `create` (`TaskPolicy::create`: làn fa1, mọi nhân sự có `matter.view`). Ô "Vụ việc" để
     * trống là một việc chung; chọn một vụ thì là việc của vụ đó — ô chỉ bày các vụ người giao được giao việc
     * ({@see TaskAssignees::assignableMatters()}), và ô người nhận đổi theo vụ ({@see TaskAssignees::options()}:
     * đội ngũ giữ việc của vụ, hay mọi nhân sự đang làm với việc chung; nhãn kèm số việc đang giữ / quá hạn của
     * mỗi người, chỉ trong các việc người giao xem được). Ô ẩn `submission_key` mang mã một lần sinh khi MỞ hộp
     * thoại — chống bấm trùng của `AssignTask` (phán quyết 4). `AssignTask` hỏi lại mọi thứ dưới khoá.
     */
    protected function assignTaskAction(): Action
    {
        return Action::make('create')
            ->label(__('tasks.actions.assign'))
            ->icon(Heroicon::OutlinedPlus)
            ->authorize(fn (): bool => Gate::allows('create', Task::class))
            ->modalHeading(__('tasks.actions.assign_heading'))
            ->modalSubmitActionLabel(__('tasks.actions.assign_submit'))
            ->fillForm(fn (): array => ['submission_key' => (string) Str::uuid(), 'priority' => TaskPriority::Normal->value])
            ->schema(fn (): array => [
                Select::make('matter_id')
                    ->label(__('tasks.assign.matter'))
                    ->placeholder(__('tasks.assign.matter_none'))
                    ->helperText(__('tasks.assign.matter_help'))
                    ->options(fn (): array => $this->assignableMatters())
                    ->visible(fn (): bool => $this->assignableMatters() !== [])
                    ->searchable()
                    // Gộp integ-fa (làn fa3): "dat dai" ra "đất đai" trong ĐÚNG danh sách trên (`AssignTask` hỏi lại).
                    ->getSearchResultsUsing(LetterD::searchOptions(fn (): array => $this->assignableMatters()))
                    ->searchDebounce(LetterD::SELECT_SEARCH_DEBOUNCE_MS)
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(fn (Set $set): mixed => $set('assignee_id', null)),
                Select::make('assignee_id')
                    ->label(__('tasks.fields.assignee'))
                    ->helperText(fn (Get $get): string => self::assigneeHelp(filled($get('matter_id')) ? 'tasks.fields.assignee_help_matter' : 'tasks.fields.assignee_help_general'))
                    ->options(fn (Get $get): array => app(TaskAssignees::class)->options($this->assignMatter($get('matter_id')), $this->taskActor()))
                    ->searchable()
                    ->getSearchResultsUsing(fn (?string $search, Get $get): array => TaskAssignees::searchOptions(
                        app(TaskAssignees::class)->options($this->assignMatter($get('matter_id')), $this->taskActor()),
                        $search,
                    ))
                    ->searchDebounce(LetterD::SELECT_SEARCH_DEBOUNCE_MS)
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
                    matter: $this->assignMatter($data['matter_id'] ?? null),
                    assignee: User::query()->withTrashed()->findOrFail($data['assignee_id'] ?? null),
                    title: (string) ($data['title'] ?? ''),
                    description: $data['description'] ?? null,
                    dueDate: $data['due_date'] ?? null,
                    priority: TaskPriority::tryFrom((string) ($data['priority'] ?? '')) ?? TaskPriority::Normal,
                    submissionKey: isset($data['submission_key']) ? (string) $data['submission_key'] : null,
                ),
            ));
    }

    /**
     * Vụ của ô "Vụ việc" — chỉ trong {@see TaskAssignees::assignableMatters()}: một id ngoài danh sách đó (gửi tay)
     * đã bị luật "in:" của ô chặn trước khi tới đây; ở đây không bao giờ đọc một vụ ngoài danh sách. Rỗng hay
     * ngoài danh sách: `null` (việc chung — với id gửi tay, form đã từ chối).
     */
    private function assignMatter(mixed $matterId): ?Matter
    {
        if (blank($matterId) || ! array_key_exists((int) $matterId, $this->assignableMatters())) {
            return null;
        }

        return Matter::query()->find((int) $matterId);
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
