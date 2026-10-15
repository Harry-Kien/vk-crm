<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Pages\TeamMember;
use App\Filament\Admin\Resources\Tasks\TaskResource;
use App\Filament\Admin\Widgets\Tasks\MatterAssignmentsAwaitingMeWidget;
use App\Models\Task;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * "Việc cần xử lý" trên bảng điều khiển (làn tk, Task 2) — của CHÍNH người đang đăng nhập: bốn ô số, mỗi ô
 * một đường tắt tới đúng chỗ bấm:
 *  - việc chờ tôi nhận → tab "Chờ tôi nhận" của trang Công việc;
 *  - việc tôi đang giữ đã quá hạn → tab "Đang làm";
 *  - hồ sơ chờ tôi nhận → "Việc của tôi" (khối "Hồ sơ chờ tôi nhận");
 *  - việc tôi giao bị trả lại, chờ tôi giao lại → tab "Tôi đã giao".
 *
 * Mọi con số đọc qua đúng định nghĩa của danh sách mà ô trỏ tới (`Task::visibleTo()` thu hẹp theo người
 * xem; `MatterAssignmentsAwaitingMeWidget::seats()`), nên số trên ô và số dòng trên trang không lệch nhau,
 * và một vụ hạn chế người xem không mở được không bao giờ vào số.
 *
 * Chỉ hiện khi có ít nhất một việc cần xử lý ({@see self::canView()}): một bảng điều khiển không có gì phải
 * làm không cần thêm một hàng số 0. Đứng ngay trước biểu đồ "Vụ việc theo giai đoạn" (thống kê nhanh) —
 * sau mọi danh sách việc của văn phòng mà SPEC §7.1 đánh số (`DashboardWidgetOrderTest`).
 *
 * Cổng: tài khoản nhân sự có `TaskPolicy::viewAny` — hỏi ở `canView()` và lại ở mỗi lần dựng
 * ({@see self::getStats()}); tài khoản không đạt nhận 404 ({@see self::mount()}).
 */
class MyWorkQueueWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    /** Không thăm dò 5 giây một lần (mặc định của `CanPoll`): số đổi khi người xem tải lại trang. */
    protected ?string $pollingInterval = null;

    /** @var array{awaiting: int, overdue: int, seats: int, returned: int}|null */
    private ?array $counts = null;

    public static function canView(): bool
    {
        $user = self::viewer();

        return $user !== null && array_sum(self::countsFor($user)) > 0;
    }

    public function mount(): void
    {
        abort_unless(self::viewer() !== null, 404);
    }

    protected function getHeading(): ?string
    {
        return __('tasks.dashboard.heading');
    }

    protected function getDescription(): ?string
    {
        return __('tasks.dashboard.description');
    }

    /** @return list<Stat> */
    protected function getStats(): array
    {
        $user = self::viewer();

        if ($user === null) {
            return [];
        }

        $counts = $this->counts ??= self::countsFor($user);

        return [
            Stat::make(__('tasks.dashboard.awaiting'), (string) $counts['awaiting'])
                ->description(__('tasks.dashboard.awaiting_hint'))
                ->color($counts['awaiting'] > 0 ? 'warning' : 'gray')
                ->url(TaskResource::getUrl('index', ['tab' => 'awaiting'], panel: 'admin')),
            Stat::make(__('tasks.dashboard.overdue'), (string) $counts['overdue'])
                ->description(__('tasks.dashboard.overdue_hint'))
                ->color($counts['overdue'] > 0 ? 'danger' : 'gray')
                ->url(TaskResource::getUrl('index', ['tab' => 'active'], panel: 'admin')),
            Stat::make(__('tasks.dashboard.seats'), (string) $counts['seats'])
                ->description(__('tasks.dashboard.seats_hint'))
                ->color($counts['seats'] > 0 ? 'warning' : 'gray')
                ->url(TeamMember::getUrl(['user' => $user->getKey()], panel: 'admin')),
            Stat::make(__('tasks.dashboard.returned'), (string) $counts['returned'])
                ->description(__('tasks.dashboard.returned_hint'))
                ->color($counts['returned'] > 0 ? 'danger' : 'gray')
                ->url(TaskResource::getUrl('index', ['tab' => 'assigned'], panel: 'admin')),
        ];
    }

    /**
     * Bốn con số của `$user` — xem docblock lớp.
     *
     * @return array{awaiting: int, overdue: int, seats: int, returned: int}
     */
    public static function countsFor(User $user): array
    {
        $mine = fn () => Task::query()->visibleTo($user);

        return [
            'awaiting' => $mine()->awaitingAcceptanceBy($user)->count(),
            'overdue' => $mine()->where('tasks.assignee_id', $user->getKey())->held()->overdue()->count(),
            'seats' => MatterAssignmentsAwaitingMeWidget::seats($user)->count(),
            'returned' => $mine()->where('tasks.assigned_by', $user->getKey())->returned()->count(),
        ];
    }

    private static function viewer(): ?User
    {
        $user = Auth::user();

        return $user instanceof User && Gate::forUser($user)->allows('viewAny', Task::class) ? $user : null;
    }
}
