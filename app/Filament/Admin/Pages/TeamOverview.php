<?php

namespace App\Filament\Admin\Pages;

use App\Enums\Permission;
use App\Models\User;
use App\Support\Audit;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Trang "Theo dõi đội ngũ" (`/team`, M13 R1): BÂY GIỜ ai đang giữ gì, cái gì đang nguy hiểm — hàng
 * đợi hành động của trưởng phòng. Task 1 dựng KHUNG (cổng, điều hướng, nhật ký, câu phạm vi R4);
 * bảng N1–N11 do Task 4 lấp, qua Action đọc ở `app/Actions/Performance/`.
 *
 * # Cổng: `performance.viewAny`, hỏi ở MỌI request, từ chối là 404
 *
 * {@see self::canAccess()} = `Gate::forUser()->allows('performance.viewAny')` (admin, quản lý; SPEC
 * §5 bổ sung 2026-10-04). Luật sư, trợ lý xem số của chính mình ở `TeamMember`, không ở đây; kế
 * toán không gì cả (R2). Hỏi ở:
 *  - {@see self::canAccess()} — panel gọi lúc tải trang (lời từ chối thành 404 qua
 *    `AnswerDeniedPanelRequestsWithNotFound`) và để quyết định mục điều hướng;
 *  - {@see self::boot()} — Livewire gọi `boot()` ở ĐẦU lần mount (trước `mount()`, xem
 *    `Livewire\Features\SupportLifecycleHooks\SupportLifecycleHooks::mount()`) VÀ ở mọi request cập
 *    nhật, trước `hydrateCanAuthorizeAccess()` của Filament (hook đó trả 403, mà 403 bên trong vòng
 *    đời component không qua được middleware 404). Người mất quyền khi trang còn mở nhận 404 ở
 *    request kế tiếp. Vì `boot()` luôn chạy trước `mount()`, `mount()` không hỏi lại lần thứ ba:
 *    một lần hỏi không đường nào tới được là một điều kiện không mutation probe nào chứng minh được.
 *
 * # Nhật ký (R14)
 *
 * {@see self::mount()} ghi MỘT dòng `performance_viewed` (chủ thể rỗng, `page = team_overview`):
 * trang hiện số "bây giờ" của MỌI người được theo dõi, nên mở nó là xem số của người khác. Chỉ ở
 * `mount()` — request Livewire (bật công tắc, sắp xếp) không ghi thêm. `mount()` chạy sau `boot()`,
 * nên không ai bị từ chối để lại dòng nào.
 */
class TeamOverview extends Page
{
    protected string $view = 'filament.admin.pages.team-overview';

    protected static ?string $slug = 'team';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    public static function getNavigationLabel(): string
    {
        return __('performance.pages.team_overview.navigation_label');
    }

    public function getTitle(): string
    {
        return __('performance.pages.team_overview.title');
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && Gate::forUser($user)->allows(Permission::PerformanceViewAny->value);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /** Xem docblock lớp, mục "Cổng" — 404 ở lần mount VÀ ở mọi request cập nhật Livewire. */
    public function boot(): void
    {
        abort_unless(static::canAccess(), 404);
    }

    public function mount(): void
    {
        /** @var User $viewer */
        $viewer = Auth::user();

        Audit::record('performance_viewed', null, ['page' => 'team_overview'], causer: $viewer);
    }
}
