<?php

namespace App\Filament\Admin\Pages;

use App\Enums\Permission;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Trang "Hiệu suất theo kỳ" (`/performance`, M13 R1): TRONG một kỳ, mỗi người đã làm đúng hạn tới
 * đâu. Task 1 dựng KHUNG (cổng, điều hướng, câu phạm vi R4); kỳ, bảng P1–P10, dòng "Chung" và nhật
 * ký `performance_viewed` do Task 6 lấp.
 *
 * # Cổng: `matter.view` HOẶC `performance.viewAny`, hỏi ở mọi request, từ chối là 404
 *
 * Người có `performance.viewAny` (admin, quản lý) xem dòng của mọi người được theo dõi; người có
 * `matter.view` (luật sư, trợ lý) xem dòng của chính mình (R2) — trang chỉ mở cửa, còn "dòng của
 * ai" là việc của `TeamRoster::subjectsFor()` ở Task 6. Kế toán không có cả hai quyền: 404.
 * {@see self::boot()} hỏi lại ở đầu lần mount và ở mọi request cập nhật Livewire, trước
 * `hydrateCanAuthorizeAccess()` của Filament (lý do ở docblock {@see TeamOverview}).
 */
class Performance extends Page
{
    protected string $view = 'filament.admin.pages.performance';

    protected static ?string $slug = 'performance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    public static function getNavigationLabel(): string
    {
        return __('performance.pages.performance.navigation_label');
    }

    public function getTitle(): string
    {
        return __('performance.pages.performance.title');
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return false;
        }

        $gate = Gate::forUser($user);

        return $gate->allows(Permission::MatterView->value)
            || $gate->allows(Permission::PerformanceViewAny->value);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /** Xem docblock lớp — 404 ở lần mount VÀ ở mọi request cập nhật Livewire. */
    public function boot(): void
    {
        abort_unless(static::canAccess(), 404);
    }
}
