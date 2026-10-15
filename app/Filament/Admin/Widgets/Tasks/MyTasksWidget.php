<?php

namespace App\Filament\Admin\Widgets\Tasks;

use App\Filament\Admin\Concerns\ReportsActionFailures;
use App\Filament\Admin\Pages\TeamMember;
use App\Filament\Admin\Resources\Tasks\Concerns\ProvidesTaskActions;
use App\Filament\Admin\Resources\Tasks\Tables\TaskColumns;
use App\Filament\Admin\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Một khối của "Việc của tôi" (làn tk, Task 1) — trang của chính người đang đăng nhập
 * ({@see TeamMember::getHeaderWidgets()}). Ba lớp con, mỗi lớp một nhóm: {@see TasksAwaitingMeWidget},
 * {@see MyActiveTasksWidget}, {@see TasksIAssignedWidget}.
 *
 * # Không tin trang chứa nó
 *
 * Widget là một component Livewire riêng: nó đọc người dùng từ phiên ở MỖI request, không nhận id người
 * nào từ trang (giá trị `subjectId` mà `TeamMember::getWidgetData()` truyền cho mọi widget bị bỏ qua — khối
 * này không có thuộc tính nào nhận nó). Truy vấn luôn là `Task::visibleTo($user)` thu hẹp theo nhóm của
 * chính `$user`: không cách nào đọc việc của người khác qua đây. `canView()` hỏi `TaskPolicy::viewAny`.
 *
 * Không tự hiện trên bảng điều khiển (`$isDiscovered = false`); không tải lười, để ba khối hiện cùng trang.
 */
abstract class MyTasksWidget extends TableWidget
{
    use ProvidesTaskActions;
    use ReportsActionFailures;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    /** Thu hẹp phạm vi `visibleTo($user)` về đúng nhóm của khối. */
    abstract protected function narrow(Builder $query, User $user): Builder;

    /** Khoá `tasks.my.<nhóm>` và tab tương ứng của trang "Công việc". */
    abstract protected function group(): string;

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User && Gate::forUser($user)->allows('viewAny', Task::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('tasks.my.'.$this->group().'.heading'))
            ->description(__('tasks.my.'.$this->group().'.description', ['days' => Task::RECENTLY_COMPLETED_DAYS]))
            ->query(function (): Builder {
                $user = $this->taskActor();

                return TaskColumns::eagerLoad($this->narrow(Task::query()->visibleTo($user), $user))
                    ->orderByRaw('CASE WHEN tasks.due_date IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('tasks.due_date')
                    ->orderBy('tasks.id');
            })
            ->columns(TaskColumns::make())
            ->recordActions($this->taskRecordActions())
            ->headerActions([
                Action::make('openAll')
                    ->label(__('tasks.my.open_all'))
                    ->color('gray')
                    ->url(TaskResource::getUrl('index', ['tab' => $this->group()], panel: 'admin')),
            ])
            ->emptyStateHeading(__('tasks.empty.'.$this->group()))
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5);
    }
}
