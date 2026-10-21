<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Permission;
use App\Enums\TaskStatus;
use App\Filament\Admin\Pages\DocumentLibrary;
use App\Filament\Admin\Pages\TeamMember;
use App\Filament\Admin\Resources\Tasks\TaskResource;
use App\Filament\Admin\Widgets\Tasks\MatterAssignmentsAwaitingMeWidget;
use App\Models\Task;
use App\Models\User;
use App\Support\Tasks\SeatInbox;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * "Việc cần xử lý" trên bảng điều khiển (làn tk, Task 2) — của CHÍNH người đang đăng nhập: tối đa bốn ô số,
 * mỗi ô một đường tắt tới đúng chỗ bấm:
 *  - việc chờ tôi nhận → tab "Chờ tôi nhận" của trang Công việc;
 *  - việc tôi đang giữ (chờ nhận hay đang làm) đã quá hạn, trên vụ còn mở hay việc chung
 *    (`Task::scopeOverdueToHandle()` — vòng sửa polish 1, rà soát Task 2 m8: cùng định nghĩa với lịch nhắc
 *    quá hạn, việc của vụ đã kết thúc không vào số) → tab "Tất cả" lọc sẵn người nhận là tôi, trạng thái
 *    "Chờ nhận"/"Đang làm" và "Quá hạn" (vòng sửa cuối 1, F5: tab "Đang làm" bỏ sót việc quá hạn chưa nhận);
 *  - hồ sơ chờ tôi nhận → "Việc của tôi" (khối "Hồ sơ chờ tôi nhận") — CHỈ cho người mở được trang đó
 *    ({@see SeatInbox::onMyWork()}; vòng sửa cuối 1, F2): quản trị viên, kế toán, nhân viên kinh doanh mở
 *    trang đó là 404, nên ô này không có mặt và không vào số của họ — quản trị viên nhận hồ sơ trên tab
 *    "Đội ngũ" của vụ, nơi chuông và thư nhắc của họ trỏ tới;
 *  - việc tôi giao bị trả lại → tab "Tôi đã giao" lọc sẵn trạng thái "Bị trả lại" (tab đó còn liệt kê việc
 *    đang mở và việc vừa xong);
 *  - văn bản chờ tôi duyệt (gộp integ-fa, rà soát chéo 2026-10-18, mục 17) → trang "Tài liệu" lọc sẵn "Chờ tôi duyệt"
 *    (`DocumentLibrary::awaitingApprovalIds()` — MỘT định nghĩa cho ô và bộ lọc: văn bản nhóm B đang chờ duyệt mà người
 *    xem qua cổng `DocumentPolicy::publish` của nút duyệt). CHỈ cho người có `document.publish` (người duyệt được) — ô
 *    không có mặt với người khác.
 *
 * Mỗi con số đếm ĐÚNG tập dòng mà đường dẫn của ô mở ra: cùng `Task::visibleTo()` thu hẹp theo người xem,
 * cùng scope của tab và bộ lọc trong đường dẫn ({@see self::countsFor()}, {@see self::links()} —
 * `MyWorkQueueWidgetTest` mở từng đường dẫn và so dòng). Ô quá hạn bỏ việc của vụ đã kết thúc hay đã huỷ —
 * bộ lọc "Chỉ việc quá hạn" mà ô mở ra cũng bỏ chúng; việc chờ nhận của một vụ đã kết thúc vẫn ở ô "chờ tôi
 * nhận", như ở tab đó. Một vụ hạn chế người xem không mở được không bao giờ vào số.
 *
 * Chỉ hiện khi có ít nhất một việc cần xử lý ({@see self::canView()}): một bảng điều khiển không có gì phải
 * làm không cần thêm một hàng số 0. Đứng ngay trước biểu đồ "Vụ việc theo giai đoạn" (thống kê nhanh) —
 * sau mọi danh sách việc của văn phòng mà SPEC §7.1 đánh số (`DashboardWidgetOrderTest`).
 *
 * Cổng: tài khoản nhân sự có `TaskPolicy::viewAny` VÀ có ít nhất một việc — hỏi ở `canView()`, ở lần dựng
 * ({@see self::mount()}: không có quyền là 404; có quyền mà hết việc thì các ô hiện 0) và lại ở mỗi lần dựng số
 * ({@see self::getStats()}).
 */
class MyWorkQueueWidget extends StatsOverviewWidget
{
    /** Màu của một ô khi số khác không. */
    private const COLORS = ['awaiting' => 'warning', 'overdue' => 'danger', 'seats' => 'warning', 'returned' => 'danger', 'approvals' => 'warning'];

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    /** Không thăm dò 5 giây một lần (mặc định của `CanPoll`): số đổi khi người xem tải lại trang. */
    protected ?string $pollingInterval = null;

    /** @var array<string, int>|null */
    private ?array $counts = null;

    public static function canView(): bool
    {
        $user = self::viewer();

        return $user !== null && array_sum(self::countsFor($user)) > 0;
    }

    /**
     * Cổng của lần dựng là QUYỀN (`TaskPolicy::viewAny`), không phải "còn việc": khối tải trễ, nên số có thể về
     * 0 giữa lúc trang chủ vẽ và lúc khối tải (người xem vừa nhận việc cuối ở tab khác) — khi đó các ô hiện 0,
     * không 404 (404 của một lần tải trễ là hộp báo lỗi giữa trang chủ). Gộp làn tk, 2026-10-16.
     */
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
        $links = self::links($user);

        return array_map(fn (string $key): Stat => Stat::make(__('tasks.dashboard.'.$key), (string) $counts[$key])
            ->description(__('tasks.dashboard.'.$key.'_hint'))
            ->color($counts[$key] > 0 ? self::COLORS[$key] : 'gray')
            ->url($links[$key]), array_keys($counts));
    }

    /**
     * Các con số của `$user`, theo thứ tự ô — xem docblock lớp. Khoá `seats` chỉ có khi `$user` mở được
     * "Việc của tôi" ({@see SeatInbox::onMyWork()}); khoá `approvals` chỉ khi `$user` duyệt được văn bản
     * ({@see self::approves()}).
     *
     * @return array<string, int>
     */
    public static function countsFor(User $user): array
    {
        $mine = fn () => Task::query()->visibleTo($user);

        return [
            'awaiting' => $mine()->awaitingAcceptanceBy($user)->count(),
            'overdue' => $mine()->where('tasks.assignee_id', $user->getKey())->overdueToHandle()->count(),
            ...(SeatInbox::onMyWork($user) ? ['seats' => MatterAssignmentsAwaitingMeWidget::seats($user)->count()] : []),
            // Cùng scope của tab "Tôi đã giao" mà ô mở ra (`Task::scopeAssignedBy()` — gồm việc bị trả lại mà người giao
            // cũng là người nhận; gộp integ-fa, rà soát lại 2026-10-18), nên số bằng số dòng.
            'returned' => $mine()->assignedBy($user)->returned()->count(),
            ...(self::approves($user) ? ['approvals' => count(DocumentLibrary::awaitingApprovalIds($user))] : []),
        ];
    }

    /** Người duyệt được văn bản nhóm B và mở được trang "Tài liệu" — ô "Văn bản chờ tôi duyệt" có mặt (mục 17). */
    private static function approves(User $user): bool
    {
        return DocumentLibrary::canBeOpenedBy($user) && Gate::forUser($user)->allows(Permission::DocumentPublish->value);
    }

    /**
     * Đường dẫn của từng ô — tab và bộ lọc (`?filters[…]`, cùng tên bộ lọc của `ListTasks::table()`) để
     * trang mở ra đúng tập dòng của con số (docblock lớp).
     *
     * @return array{awaiting: string, overdue: string, seats: string, returned: string, approvals: string}
     */
    public static function links(User $user): array
    {
        return [
            'awaiting' => TaskResource::getUrl('index', ['tab' => 'awaiting'], panel: 'admin'),
            'overdue' => TaskResource::getUrl('index', [
                'tab' => 'all',
                'filters' => [
                    'assignee_id' => ['value' => $user->getKey()],
                    'status' => ['values' => [TaskStatus::Pending->value, TaskStatus::Accepted->value]],
                    'overdue' => ['isActive' => true],
                ],
            ], panel: 'admin'),
            'seats' => TeamMember::getUrl(['user' => $user->getKey()], panel: 'admin'),
            'returned' => TaskResource::getUrl('index', [
                'tab' => 'assigned',
                'filters' => ['status' => ['values' => [TaskStatus::Declined->value]]],
            ], panel: 'admin'),
            'approvals' => DocumentLibrary::getUrl([
                'filters' => [DocumentLibrary::AWAITING_MY_APPROVAL => ['isActive' => true]],
            ], panel: 'admin'),
        ];
    }

    private static function viewer(): ?User
    {
        $user = Auth::user();

        return $user instanceof User && Gate::forUser($user)->allows('viewAny', Task::class) ? $user : null;
    }
}
