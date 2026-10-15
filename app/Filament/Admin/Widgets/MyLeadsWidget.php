<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\IntakeStatus;
use App\Enums\Role;
use App\Models\IntakeRequest;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * "Lead của tôi" (làn kd, 2026-10-14) — hàng ô số đầu trang chủ của NHÂN VIÊN KINH DOANH, ngay dưới dải
 * "Tình trạng hệ thống" (dải cảnh báo luôn đứng trên): số lead theo trạng thái, mỗi trạng thái một ô, trừ "Đã gộp" (bản trùng đã gộp vào bản khác không phải một lead nữa).
 *
 * **Lead nào:** đúng một định nghĩa "thấy được" — `IntakeRequest::scopeVisibleTo()`: lead người này ghi,
 * được giao, hay được chuyển tới làm luật sư tư vấn. Chỉ ĐẾM: không tên, SĐT hay câu chuyện nào.
 *
 * **Ai thấy:** chỉ vai `Role::Sales`, và chỉ khi còn qua cổng `viewAny` của tiếp nhận (Gate) — trang chủ
 * của các vai khác giữ nguyên như trước (họ đã có widget vụ việc; quản lý có trang "Bức tranh đầu vào").
 * `boot()` trả 404 cho người khác, kể cả khi widget được mount thẳng (SPEC §10.10).
 */
class MyLeadsWidget extends StatsOverviewWidget
{
    // Ngay dưới dải "Tình trạng hệ thống" (-6, mọi nhân sự thấy, kể cả nhân viên kinh doanh — cảnh báo
    // đứng trên thông tin), trên "Lead đến hẹn liên hệ lại" (-4) và ô tài khoản (-3). Cùng số -5 với hàng
    // ô số vụ việc ở trang chủ các vai khác: hai hàng không bao giờ cùng hiện với một người (hàng vụ việc
    // cần `matter.view` hoặc `matter.viewAny`, hàng này chỉ vai `sales` — mà vai đó không có quyền nào ấy).
    protected static ?int $sort = -5;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && $user->hasRole(Role::Sales->value)
            && Gate::forUser($user)->allows('viewAny', IntakeRequest::class);
    }

    /** Hook `boot` của Livewire: chạy trước `mount()` và trước MỌI request sau đó — 404 cho người không qua `canView()`, kể cả khi widget được mount thẳng. */
    public function boot(): void
    {
        abort_unless(static::canView(), 404);
    }

    protected function getHeading(): ?string
    {
        return __('leads.widgets.my_leads.heading');
    }

    protected function getDescription(): ?string
    {
        return __('leads.widgets.my_leads.hint');
    }

    protected function getStats(): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return [];
        }

        $counts = IntakeRequest::query()
            ->visibleTo($user)
            ->toBase()
            ->groupBy('status')
            ->selectRaw('status, count(*) as total')
            ->pluck('total', 'status');

        return collect(IntakeStatus::cases())
            ->reject(fn (IntakeStatus $status): bool => $status === IntakeStatus::Merged)
            ->map(fn (IntakeStatus $status): Stat => Stat::make($status->label(), (string) (int) ($counts[$status->value] ?? 0))
                ->color($status === IntakeStatus::New ? 'warning' : 'gray'))
            ->values()
            ->all();
    }
}
