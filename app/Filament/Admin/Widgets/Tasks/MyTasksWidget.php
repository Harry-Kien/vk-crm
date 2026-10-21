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
 * chính `$user`: không cách nào đọc việc của người khác qua đây. `canView()` hỏi `TaskPolicy::viewAny`, và
 * `boot()` hỏi lại ở mọi request (không đạt là 404).
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

    /**
     * Cổng tường minh ở MỌI request của component (vòng sửa cuối 1, F6 — luật của làn: trang/widget tự hỏi
     * Gate rồi `abort(404)`): cùng điều kiện {@see self::canView()}.
     */
    public function boot(): void
    {
        abort_unless(static::canView(), 404);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('tasks.my.'.$this->group().'.heading'))
            ->description(__('tasks.my.'.$this->group().'.description', ['days' => Task::RECENTLY_COMPLETED_DAYS]))
            ->query(function (): Builder {
                $user = $this->taskActor();

                return TaskColumns::eagerLoad($this->narrow(Task::query()->visibleTo($user), $user));
            })
            // Kiểm toán màn hình 2026-10-21 (mục 4): cùng thứ tự với trang Công việc — việc còn mở trước; trước đó khối
            // "Tôi đã giao" xếp theo hạn trần nên một việc đã xong (hạn sớm) đứng trên việc còn phải theo dõi.
            ->defaultSort(fn (Builder $query): Builder => $query->orderedForWork())
            ->recordAction('view')
            // Cột luôn là chính người xem thì ẩn (mục 2): người nhận ở "Chờ tôi nhận"/"Đang làm", người giao ở "Tôi đã giao".
            ->columns(TaskColumns::make(
                hideAssignee: fn (): bool => in_array($this->group(), ['awaiting', 'active'], true),
                hideAssigner: fn (): bool => $this->group() === 'assigned',
            ))
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
