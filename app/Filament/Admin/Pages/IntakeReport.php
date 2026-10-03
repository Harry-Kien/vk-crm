<?php

namespace App\Filament\Admin\Pages;

use App\Enums\Permission;
use App\Filament\Admin\Widgets\IntakeReport\IntakeConversionWidget;
use App\Filament\Admin\Widgets\IntakeReport\IntakeOutcomesWidget;
use App\Filament\Admin\Widgets\IntakeReport\IntakeResponseTimeWidget;
use App\Filament\Admin\Widgets\IntakeReport\IntakesBySourceWidget;
use App\Models\IntakeRequest;
use App\Models\User;
use App\Support\Intake\IntakeReportFilters;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Bức tranh đầu vào (M10 Task 6) — trang báo cáo tiếp nhận: số liên hệ theo nguồn, tỉ lệ chuyển thành
 * vụ việc, thời gian phản hồi lần đầu (trung vị), lý do không thành theo nhóm; lọc theo kỳ và người
 * tiếp nhận.
 *
 * **Dùng lại khuôn M9 Task 9, không dựng bộ thứ hai** ({@see RevenueDashboard}): một
 * `Filament\Pages\Dashboard` RIÊNG (không phải trang chủ §7.1) với `HasFiltersForm`; bốn widget
 * `ChartWidget` + `getType()`, `$isDiscovered = false`, view dùng chung có bảng số
 * (`filament.admin.widgets.revenue.chart-with-table`), không tooltip callback, không `RawJs` (phán
 * quyết CSP của M8 R4). Khoảng thời gian diễn dịch ở đúng một chỗ của cả hai trang
 * ({@see IntakeReportFilters} giao `period`/`date_from`/`date_to` cho `RevenueFilters`).
 *
 * # Cổng — `intake.viewAny`, 404 cho mọi người khác
 *
 * `canAccess()` hỏi QUYỀN `intake.viewAny` (bảng R9: "báo cáo đầu vào") qua `Gate::forUser()` —
 * admin và quản lý; luật sư, trợ lý, kế toán không có. `boot()` trả 404 trước mọi thứ khác, ở lần
 * mount và ở mọi request Livewire sau đó — không dựa riêng vào middleware
 * `AnswerDeniedPanelRequestsWithNotFound` đổi 403 của Filament thành 404. Trang không resolve bản ghi
 * nào từ URL. Mỗi widget tự hỏi lại cùng quyền (`ReadsIntakeReport`).
 *
 * # Thấy được gì
 *
 * Mọi con số đếm trên `IntakeRequest::scopeVisibleTo()` — một định nghĩa "thấy được" của R9: bản ghi
 * đã thành vụ `restricted` mà người xem không xem được vụ thì không có trong số liệu của họ.
 * `getSubheading()` chỉ in câu chung về phạm vi — không con số nào tiết lộ RẰNG có bản ghi bị loại
 * (SPEC §10.10). Không tên, SĐT, câu chuyện hay lý do chữ nào của người liên hệ lên trang: bộ lọc
 * "người tiếp nhận" chỉ liệt kê NHÂN SỰ ({@see self::receiverOptions()}).
 */
class IntakeReport extends Dashboard
{
    use HasFiltersForm;

    protected static string $routePath = '/intake-report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFunnel;

    public static function getNavigationLabel(): string
    {
        return __('intake_report.navigation_label');
    }

    public function getTitle(): string|Htmlable
    {
        return __('intake_report.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('intake_report.scope_note');
    }

    /**
     * Cũng là cổng của mục điều hướng: `Page::registerNavigationItems()` của Filament không đăng ký mục
     * của trang mà `canAccess()` từ chối, nên trang này không ghi đè `shouldRegisterNavigation()` (một
     * bản trả về đúng `canAccess()` là mã thừa — probe đột biến của Task 6 cho thấy nó không đổi được gì).
     */
    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && Gate::forUser($user)->allows(Permission::IntakeViewAny->value);
    }

    /** Hook `boot` của Livewire: chạy trước `mount()` và trước mọi request sau đó — xem docblock lớp. */
    public function boot(): void
    {
        abort_unless(static::canAccess(), 404);
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('period')
                ->label(__('intake_report.filters.period'))
                ->options([
                    'this_month' => __('intake_report.filters.period_options.this_month'),
                    'this_quarter' => __('intake_report.filters.period_options.this_quarter'),
                    'this_year' => __('intake_report.filters.period_options.this_year'),
                    'custom' => __('intake_report.filters.period_options.custom'),
                ])
                ->default('this_month')
                ->native(false)
                ->live(),
            DatePicker::make('date_from')
                ->label(__('intake_report.filters.date_from'))
                ->native(false)
                ->visible(fn ($get) => $get('period') === 'custom'),
            DatePicker::make('date_to')
                ->label(__('intake_report.filters.date_to'))
                ->native(false)
                ->visible(fn ($get) => $get('period') === 'custom'),
            Select::make('receiver_id')
                ->label(__('intake_report.filters.receiver'))
                ->helperText(__('intake_report.filters.receiver_help'))
                ->options(function (): array {
                    /** @var User $viewer `boot()` đã bảo đảm. */
                    $viewer = Auth::user();

                    return static::receiverOptions($viewer);
                })
                ->native(false)
                ->searchable(),
        ]);
    }

    /**
     * Nhân sự đã GHI ít nhất một bản ghi tiếp nhận mà người xem thấy được (`created_by`, kể cả người
     * đã nghỉ — báo cáo nhìn cả quá khứ), theo tên. Không bao giờ là người liên hệ. Lọc qua
     * `scopeVisibleTo()` để không đưa ra tên một nhân sự chỉ ghi bản ghi đã thành vụ `restricted` mà
     * người xem không xem được — chọn tên đó rồi mọi widget ra 0 sẽ ngầm xác nhận có một bản ghi như
     * vậy (cùng lý do bộ lọc luật sư của trang doanh thu, SPEC §10.10).
     *
     * @return array<int, string>
     */
    public static function receiverOptions(User $viewer): array
    {
        return User::query()
            ->whereIn('id', IntakeRequest::query()->visibleTo($viewer)->select('created_by'))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function getColumns(): int|array
    {
        return 2;
    }

    /**
     * Bốn widget, đăng ký TƯỜNG MINH ở đây — không `Filament::getWidgets()`: mỗi widget đặt
     * `$isDiscovered = false`, nên trang chủ không có chúng và trang này là nơi DUY NHẤT liệt kê chúng.
     *
     * @return array<class-string<Widget>>
     */
    public function getWidgets(): array
    {
        return [
            IntakesBySourceWidget::class,
            IntakeConversionWidget::class,
            IntakeResponseTimeWidget::class,
            IntakeOutcomesWidget::class,
        ];
    }
}
