<?php

namespace App\Filament\Admin\Pages;

use App\Enums\NavigationGroup;
use App\Enums\Permission;
use App\Filament\Admin\Widgets\Revenue\ClosedWithBalanceWidget;
use App\Filament\Admin\Widgets\Revenue\LoadPerLawyerWidget;
use App\Filament\Admin\Widgets\Revenue\MatterMixByPracticeAreaWidget;
use App\Filament\Admin\Widgets\Revenue\ReceivablesDonutWidget;
use App\Filament\Admin\Widgets\Revenue\RevenueByStageWidget;
use App\Filament\Admin\Widgets\Revenue\RevenueKpiWidget;
use App\Filament\Admin\Widgets\Revenue\RevenueOverTimeWidget;
use App\Models\MatterType;
use App\Models\User;
use App\Support\Billing\RevenueFilters;
use App\Support\Scopes\ClientPortalScope;
use App\Support\Search\LetterD;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Trang doanh thu (M9 Task 9) — bức tranh tiền của cả văn phòng trên MỘT trang, lọc được theo kỳ,
 * luật sư và lĩnh vực. `Filament\Pages\Dashboard` RIÊNG, không phải trang chủ §7.1
 * (`AdminPanelProvider` đăng ký trang chủ tường minh bằng `\Filament\Pages\Dashboard::class`; lớp
 * này là MỘT trang khác, tự chọn slug mặc định `revenue-dashboard` qua `HasRoutes::getDefaultSlug()`
 * — kebab của tên lớp).
 *
 * # Vì sao KHÔNG có ô đổi kiểu biểu đồ
 *
 * **Cố ý không có, không phải thiếu sót.** Một ô chọn kiểu biểu đồ cho người dùng vẽ ra một biểu
 * đồ SAI — 12 lĩnh vực thành một hình tròn, một chuỗi thời gian thành một hình tròn — RỒI TIN NÓ.
 * Kế hoạch M9 ("Trang doanh thu — lập luận đã chốt") giao mỗi câu hỏi cho ĐÚNG MỘT dạng biểu đồ,
 * và điều đó không phải một lựa chọn thẩm mỹ để người xem đổi lại.
 *
 * # Bốn bộ lọc, một nơi diễn dịch — {@see RevenueFilters}
 *
 * `period` (phím tắt tháng này/quý này/năm nay/tuỳ chọn) + `date_from`/`date_to` (chỉ hiện khi
 * `period = custom`), `lawyer_id`, `practice_area_id`, `by_count` (công tắc số vụ/số tiền — CHỈ
 * `MatterMixByPracticeAreaWidget` đọc nó; "đổi thứ được đo, không thêm trục thứ hai"). Bốn trường
 * này KHÔNG tự giới hạn theo người đang xem — đó là việc của `Matter::scopeListableBy($user)` ở
 * TẦNG TRUY VẤN của từng widget (P3), không phải của form lọc.
 *
 * # Khoảng thời gian lọc CÁI GÌ, và bộ lọc luật sư mang HAI NGHĨA — mỗi widget tự in lên chính nó
 *
 * `RevenueFilters` chỉ mang một khoảng ngày trung lập; MỖI widget tự diễn dịch nó theo đúng cột
 * của MÌNH (`payments.paid_on` "tiền về trong kỳ", hay `contracts.signed_at` "việc đã ký trong
 * kỳ") và tự in nghĩa đó lên chính nó (từ làn rv: `getExplanation()`, nút ⓘ cạnh tiêu đề; dòng phụ
 * `getDescription()` chỉ còn khoảng ngày) — không nơi nào khác
 * "biết hộ" ý nghĩa của một widget khác. Tương tự, bộ lọc luật sư mang HAI nghĩa
 * (`payments.attributed_lawyer_id` "lúc thu" hay `matters.lead_lawyer_id` "hiện tại", kế hoạch
 * M9 P2) và mỗi widget in đúng nghĩa nó dùng.
 *
 * # Phân quyền — MỘT định nghĩa
 *
 * Trang mở cho ai có `billing.view` (kể cả luật sư — họ thấy số liệu CỦA MÌNH nhưng không thấy
 * hai widget toàn văn phòng, vốn tự đòi thêm `revenue.viewAny` ở `canView()` riêng). Mọi widget
 * lấy tập vụ việc gốc từ `Matter::scopeListableBy($user)` — không widget nào tự viết lại điều kiện
 * "vụ này ai được xem tiền".
 *
 * # Không in số vụ bị loại (SPEC §10.10)
 *
 * `getSubheading()` chỉ in câu chung "Số liệu gồm các vụ việc anh/chị được xem." (cùng chuỗi với
 * trang "Công nợ", `billing.receivables.scope_note`) — không một widget nào được thêm "(đã ẩn N
 * vụ hạn chế)" hay bất kỳ con số nào tiết lộ RẰNG có vụ bị loại.
 *
 * # Làn rv (2026-10-15) — gọn như một CRM chuyên nghiệp
 *
 * Chủ văn phòng: "nhiều chữ nhìn rối quá". (1) Hàng ô số `RevenueKpiWidget` đứng ĐẦU, hết bề ngang.
 * (2) Mỗi biểu đồ một tiêu đề ngắn và một dòng phụ; cách tính giấu sau nút ⓘ (`getExplanation()` của
 * từng widget, view `chart-with-table`). (3) Trang dùng hết bề ngang (`$maxContentWidth = Full`), lưới hai
 * cột từ `lg`, một cột trên điện thoại. (4) Bộ lọc trên MỘT hàng (bốn cột từ `lg`): Kỳ, Luật sư phụ trách,
 * Lĩnh vực, công tắc "Đếm theo số vụ" (nhãn ở trên như ba ô kia, giải thích sau ⓘ); hai ô ngày của "Tuỳ
 * chọn" xuống hàng sau, để hàng đầu không xô lệch khi bật/tắt chúng. (Mục "không có ô đổi kiểu biểu đồ"
 * ở trên đã được thay từ 2026-10-10 — `HasSwitchableChartKind`, mỗi widget chỉ mời dạng không nói sai.)
 */
class RevenueDashboard extends Dashboard
{
    use HasFiltersForm;

    protected static string $routePath = '/revenue-dashboard';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Finance;

    protected static ?int $navigationSort = 10;

    /** Làn rv: dashboard dùng hết bề ngang — hai bên không còn khoảng trống. */
    protected Width|string|null $maxContentWidth = Width::Full;

    public static function getNavigationLabel(): string
    {
        return __('widgets.revenue_dashboard.navigation_label');
    }

    public function getTitle(): string|Htmlable
    {
        return __('widgets.revenue_dashboard.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('billing.receivables.scope_note');
    }

    /**
     * Cổng của cả TRANG. Chỉ `billing.view` — KHÁC `Receivables::canAccess()` (đòi thêm
     * `revenue.viewAny`): luật sư có `billing.view` nhưng không có `revenue.viewAny` phải VÀO
     * ĐƯỢC trang này (họ thấy số liệu của vụ mình), chỉ không thấy hai widget toàn văn phòng.
     * Trợ lý không có `billing.view` nhận 404 qua `AnswerDeniedPanelRequestsWithNotFound`.
     */
    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && Gate::forUser($user)->allows(Permission::BillingView->value);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /**
     * Tuỳ chọn của ô "Luật sư" (luật đầy đủ ở chú thích của ô trong {@see self::filtersForm()}): luật sư phụ
     * trách ít nhất một vụ người xem liệt kê được, hoặc được ghi doanh thu trên một khoản thu chưa huỷ của
     * một vụ người xem liệt kê được (kể cả vụ đã huỷ). Fix round 1 (m3, làn fa3): tách thành hàm để ô chọn
     * tìm trên máy chủ ({@see LetterD::searchOptions()}) trên ĐÚNG danh sách này.
     *
     * @return array<int, string>
     */
    public static function lawyerOptions(): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return [];
        }

        return User::query()
            ->where(fn ($q) => $q
                ->whereHas('leadMatters', fn ($matters) => $matters->listableBy($user))
                ->orWhereHas('attributedPayments', fn ($payments) => $payments
                    ->withoutGlobalScope(ClientPortalScope::class)
                    ->whereNull('voided_at')
                    ->whereHas('instalment.contract.matter', fn ($matters) => $matters->withTrashed()->listableBy($user))))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function filtersForm(Schema $schema): Schema
    {
        // Làn rv: một hàng bốn ô từ `lg` (hai ô từ `sm`, một ô trên điện thoại). Ghi đủ mọi mốc: `columns()`
        // GỘP với mốc sẵn có của `HasFiltersForm` (md 2, xl 3, 2xl 4), nên chỉ ghi `lg` thì từ `xl` lại còn 3.
        return $schema->columns(['default' => 1, 'sm' => 2, 'md' => 2, 'lg' => 4, 'xl' => 4, '2xl' => 4])->components([
            Select::make('period')
                ->label(__('widgets.revenue_dashboard.filters.period'))
                ->options([
                    'this_month' => __('widgets.revenue_dashboard.filters.period_options.this_month'),
                    'this_quarter' => __('widgets.revenue_dashboard.filters.period_options.this_quarter'),
                    'this_year' => __('widgets.revenue_dashboard.filters.period_options.this_year'),
                    'custom' => __('widgets.revenue_dashboard.filters.period_options.custom'),
                ])
                ->default('this_month')
                ->native(false)
                ->live(),
            Select::make('lawyer_id')
                ->label(__('widgets.revenue_dashboard.filters.lawyer'))
                // Fix round 1, minor: options chỉ liệt kê luật sư đang phụ trách ÍT NHẤT một vụ
                // mà NGƯỜI ĐANG XEM được thấy (`Matter::scopeListableBy()`) — không phải MỌI luật
                // sư có leadMatters trong hệ thống. Một kế toán/quản lý (không thấy vụ `restricted`)
                // không bao giờ được đưa cho một cái tên luật sư chỉ phụ trách toàn vụ `restricted`
                // để chọn — chọn cái tên đó rồi mọi widget ra 0 dòng sẽ ngầm xác nhận "có một luật
                // sư như vậy tồn tại", đúng kiểu rò rỉ SỰ TỒN TẠI mà SPEC §10.10 cấm.
                //
                // M9 Task 13 (minor rà soát Task 9): cộng luật sư được GHI DOANH THU trên một khoản
                // thu chưa huỷ của một vụ người xem thấy (P2 — "đã thu" lọc theo luật sư LÚC THU).
                // Trước đó người cũ của một vụ đã bàn giao không chọn được, nên doanh thu họ đã mang
                // về không lọc ra được. Cùng cổng `listableBy`, nên vụ `restricted` vẫn không đưa một
                // cái tên nào tới người không thấy vụ đó.
                //
                // Làn fa3 (2026-10-16, mục 1): nhánh khoản thu đọc cả vụ đã huỷ (`withTrashed()`), cùng tập
                // khoản thu với `CollectedRevenue` — tiền đã về trên một hồ sơ huỷ từ trước luật của
                // `CancelMatter` vẫn đếm ở "Đã thu", nên luật sư được ghi số tiền đó vẫn lọc ra được.
                // `listableBy()` giữ nguyên: vụ hạn chế đã huỷ không đưa tên nào tới người không thấy nó.
                ->options(fn (): array => static::lawyerOptions())
                ->native(false)
                ->searchable()
                // Fix round 1 (m3, làn fa3): tìm trên máy chủ trong ĐÚNG danh sách trên ("dinh" ra "Đinh").
                ->getSearchResultsUsing(LetterD::searchOptions(fn (): array => static::lawyerOptions()))
                ->searchDebounce(LetterD::SELECT_SEARCH_DEBOUNCE_MS),
            Select::make('practice_area_id')
                ->label(__('widgets.revenue_dashboard.filters.practice_area'))
                ->options(fn () => MatterType::query()
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->pluck('name', 'id')
                    ->all())
                ->native(false)
                ->searchable(),
            // Làn rv: nhãn ở trên (`inline(false)`) cho thẳng hàng với ba ô chọn; đoạn giải thích sau ⓘ.
            Toggle::make('by_count')
                ->label(__('widgets.revenue_dashboard.filters.by_count'))
                ->inline(false)
                ->hint(new HtmlString(Blade::render('<x-info-tip :text="$text" :label="$label" />', [
                    'text' => __('widgets.revenue_dashboard.filters.by_count_help'),
                    'label' => __('widgets.revenue_dashboard.filters.by_count'),
                ]))),
            DatePicker::make('date_from')
                ->label(__('widgets.revenue_dashboard.filters.date_from'))
                ->native(false)
                ->visible(fn ($get) => $get('period') === 'custom'),
            DatePicker::make('date_to')
                ->label(__('widgets.revenue_dashboard.filters.date_to'))
                ->native(false)
                ->visible(fn ($get) => $get('period') === 'custom'),
        ]);
    }

    public function getColumns(): int|array
    {
        return 2;
    }

    /**
     * Bảy widget doanh thu (hàng ô số của làn rv đứng đầu), đăng ký TƯỜNG MINH ở đây — KHÔNG `Filament::getWidgets()` (điều 1
     * của kế hoạch M9 Task 9): mỗi widget đặt `$isDiscovered = false` nên panel không tự gom
     * chúng vào trang chủ §7.1 lẫn vào đây; trang này là nơi DUY NHẤT liệt kê chúng.
     *
     * @return array<class-string<Widget>>
     */
    public function getWidgets(): array
    {
        return [
            RevenueKpiWidget::class,
            ReceivablesDonutWidget::class,
            RevenueOverTimeWidget::class,
            RevenueByStageWidget::class,
            MatterMixByPracticeAreaWidget::class,
            LoadPerLawyerWidget::class,
            ClosedWithBalanceWidget::class,
        ];
    }
}
