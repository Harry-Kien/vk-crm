<?php

namespace App\Filament\Admin\Pages;

use App\Actions\Performance\BuildPerformanceReport;
use App\Enums\Permission;
use App\Models\User;
use App\Support\Audit;
use App\Support\Billing\Money;
use App\Support\Performance\PerformancePeriod;
use App\Support\Performance\PerformanceReport;
use App\Support\Performance\PerformanceRow;
use App\Support\Performance\Ratio;
use App\Support\Performance\ResponseTime;
use App\Support\Performance\TeamRoster;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * Trang "Hiệu suất theo kỳ" (`/performance`, M13 R1): TRONG một kỳ, mỗi người đã làm đúng hạn tới
 * đâu. Mỗi người được theo dõi một dòng, cột P1–P7, P9, P10 và "Lĩnh vực chính" của
 * {@see BuildPerformanceReport}; trang không tự đếm gì.
 *
 * # Cổng: `matter.view` HOẶC `performance.viewAny`, hỏi ở mọi request, từ chối là 404
 *
 * Người có `performance.viewAny` (admin, quản lý) xem dòng của mọi người được theo dõi, cộng dòng "Chung";
 * người có `matter.view` (luật sư, trợ lý) xem dòng của chính mình (R2). Kế toán không có cả hai quyền:
 * 404. {@see self::boot()} hỏi lại ở đầu lần mount và ở mọi request cập nhật Livewire, trước
 * `hydrateCanAuthorizeAccess()` của Filament (lý do ở docblock {@see TeamOverview}).
 *
 * # Tập người: tính lại từ người xem ở MỖI request (R2, R3)
 *
 * `TeamRoster::subjectsForPeriod($viewer, $period, công tắc)` — chỉ phụ thuộc vai trò và kỳ, không bao giờ
 * "người có vụ mà người xem thấy". Không thuộc tính công khai nào mang id người hay id vụ: trạng thái
 * Livewire chỉ có form kỳ (`$data`), kỳ đang hiện (`$appliedPeriod`, khoá), công tắc "Gồm người đã nghỉ
 * việc" và cột sắp xếp. Một id nhét vào bộ lọc hay form không được ai đọc.
 *
 * # Kỳ (R16): form → `applyPeriod()` → thuộc tính khoá
 *
 * Ô chọn kỳ và hai ô ngày (chỉ hiện với "Tuỳ chọn") là form `$data`; bấm "Xem số liệu" gọi
 * {@see self::applyPeriod()}, nơi {@see PerformancePeriod::fromFilters()} — cổng duy nhất của R16 — nhận
 * hoặc từ chối (lỗi tiếng Việt trên đúng ô). Chỉ kỳ đã nhận mới vào `$appliedPeriod`, thuộc tính
 * `#[Locked]`: không request nào đặt thẳng được một kỳ chưa qua cổng đó. Mỗi request đọc lại kỳ qua
 * `fromFilters($appliedPeriod)`, nên "tháng trước" luôn tính từ hôm nay.
 *
 * # Bảng: `Table::records()`, không xếp hạng (R8)
 *
 * Dòng là mảng thuộc tính của {@see PerformanceRow}; dòng "Chung" (khoá {@see self::REFERENCE_KEY}) luôn
 * đứng đầu. KHÔNG cột nào sắp xếp được ngoài tên: một lời gọi `sortTable` lên cột số (Livewire nhận tên cột
 * bất kỳ) giữ nguyên thứ tự theo tên. Tỉ lệ không bao giờ tô màu. Phân rã R7 in ngay dưới tỉ lệ
 * ("12/15 mốc · 8/9 yêu cầu"). Cột doanh thu chỉ có khi `PerformanceReport::$revenueVisible` (hỏi qua
 * {@see self::revenueVisible()}, cùng luật, không dựng báo cáo — mỗi request dựng báo cáo đúng một lần). Cột P8 (Task 7)
 * in mốc quá hạn và vụ quá hạn cập nhật của ngày đầu kỳ → ngày cuối kỳ (không muộn hơn hôm qua) từ ảnh chụp
 * hằng ngày; ngày không có ảnh chụp là "—", không phải 0; phần N4 là "Không áp dụng" với người không đứng tên
 * phụ trách vụ (R6); dòng "Chung" không có cột này.
 *
 * # Nhật ký (R14)
 *
 * Một dòng `performance_viewed` (chủ thể rỗng, `properties` mang kỳ) khi người có `performance.viewAny`
 * mở trang ({@see self::mount()}) và mỗi lần kỳ đang hiện THẬT SỰ đổi ({@see self::applyPeriod()}).
 * Không ghi khi xem số của chính mình, không ghi khi bật công tắc hay sắp xếp.
 */
class Performance extends Page implements HasTable
{
    use InteractsWithTable;

    /** Khoá của dòng "Chung" (R8) trong bảng; dòng người mang id người. */
    public const REFERENCE_KEY = 'reference';

    /**
     * Mã các câu giải thích của khối "Cách tính các con số" (khoá `performance.explain.<mã>`, R6), theo thứ
     * tự cột. `p7` chỉ khi cột doanh thu có trên trang; `reference` chỉ khi có dòng "Chung".
     *
     * @var list<string>
     */
    public const EXPLAINED_CODES = ['main_areas', 'p1', 'p2', 'p3', 'p10', 'p4', 'p5', 'p6', 'p7', 'p9', 'p8', 'reference', 'closed_period', 'not_applicable'];

    /** @var array<string, mixed>|null trạng thái form kỳ đang soạn — chưa phải kỳ đang hiện */
    public ?array $data = [];

    /** @var array<string, string> kỳ đang hiện, dạng `PerformancePeriod::toFilters()`; chỉ `applyPeriod()` đổi được */
    #[Locked]
    public array $appliedPeriod = [];

    protected string $view = 'filament.admin.pages.performance';

    protected static ?string $slug = 'performance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    /** Báo cáo của request này, theo (kỳ, công tắc) — trang đọc nó nhiều lần trong một lần vẽ. */
    private ?string $reportKey = null;

    private ?PerformanceReport $report = null;

    /** Người của request này, theo (kỳ, công tắc) — {@see self::subjects()}. */
    private ?string $subjectsKey = null;

    /** @var Collection<int, User>|null */
    private ?Collection $subjects = null;

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

    public function mount(): void
    {
        $period = PerformancePeriod::fromFilters(null);

        $this->appliedPeriod = $period->toFilters();
        $this->form->fill(['period' => $period->key, 'date_from' => null, 'date_to' => null]);

        $this->recordViewed($period);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(3)
            ->components([
                Select::make('period')
                    ->label(__('performance.period.field'))
                    ->options(array_combine(
                        PerformancePeriod::CHOICES,
                        array_map(fn (string $key): string => __("performance.period.options.{$key}"), PerformancePeriod::CHOICES),
                    ))
                    ->native(false)
                    ->selectablePlaceholder(false)
                    ->live()
                    ->required(),
                DatePicker::make('date_from')
                    ->label(__('performance.period.date_from'))
                    ->maxDate(fn () => today())
                    ->visible(fn (Get $get): bool => $get('period') === PerformancePeriod::CUSTOM)
                    ->required(),
                DatePicker::make('date_to')
                    ->label(__('performance.period.date_to'))
                    ->maxDate(fn () => today())
                    ->visible(fn (Get $get): bool => $get('period') === PerformancePeriod::CUSTOM)
                    ->required(),
            ]);
    }

    /**
     * Đổi kỳ đang hiện sang kỳ của form, qua {@see PerformancePeriod::fromFilters()}; lỗi của nó hiện trên
     * đúng ô. Kỳ thật sự đổi thì ghi `performance_viewed` (R14).
     */
    public function applyPeriod(): void
    {
        $filters = $this->form->getState();

        try {
            $period = PerformancePeriod::fromFilters($filters);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => ["data.{$field}" => $messages])
                ->all());
        }

        if ($period->toFilters() === $this->appliedPeriod) {
            return;
        }

        $this->appliedPeriod = $period->toFilters();
        $this->flushCachedTableRecords();
        $this->recordViewed($period);
    }

    /** Kỳ đang hiện. */
    public function period(): PerformancePeriod
    {
        return PerformancePeriod::fromFilters($this->appliedPeriod);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?string $sortColumn, ?string $sortDirection): array => $this->records($sortColumn, $sortDirection))
            ->columns([
                TextColumn::make('name')
                    ->label(__('performance.columns.name'))
                    ->url(fn (array $record): ?string => $record['userId'] === null ? null : TeamMember::getUrl(['user' => $record['userId']]))
                    ->description(fn (array $record): ?string => $record['isActive'] ? null : __('performance.period_page.inactive'))
                    ->sortable(),
                TextColumn::make('mainPracticeAreas')
                    ->label(__('performance.columns.main_areas'))
                    ->state(fn (array $record): ?string => self::areasLabel($record['mainPracticeAreas']))
                    ->placeholder('—'),
                TextColumn::make('onTimeRatio')
                    ->label(__('performance.columns.p1'))
                    ->state(fn (array $record): string => $record['onTimeRatio']->label())
                    ->description(fn (array $record): string => __('performance.period_page.p1_breakdown', [
                        'on_time' => $record['deadlinesOnTime'],
                        'late' => $record['deadlinesLate'],
                        'missed' => $record['deadlinesMissed'],
                    ])),
                TextColumn::make('deadlinesRemoved')
                    ->label(__('performance.columns.p2')),
                TextColumn::make('requestsAnswered')
                    ->label(__('performance.columns.p3'))
                    ->state(fn (array $record): string => __('performance.period_page.p3_state', [
                        'answered' => $record['requestsAnswered'],
                        'received' => $record['requestsReceived'],
                    ]))
                    ->description(fn (array $record): ?string => self::responseLabel($record)),
                TextColumn::make('requestsClosedUnanswered')
                    ->label(__('performance.columns.p10')),
                TextColumn::make('stageEntries')
                    ->label(__('performance.columns.p4'))
                    ->state(fn (array $record): ?string => self::stageLabel($record))
                    ->placeholder(__('performance.not_applicable')),
                TextColumn::make('mattersClosed')
                    ->label(__('performance.columns.p5'))
                    ->placeholder(__('performance.not_applicable')),
                TextColumn::make('itemsReviewed')
                    ->label(__('performance.columns.p6')),
                TextColumn::make('revenueCollected')
                    ->label(__('performance.columns.p7'))
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->placeholder(__('performance.not_applicable'))
                    ->visible(fn (): bool => $this->revenueVisible()),
                TextColumn::make('completionRatio')
                    ->label(__('performance.columns.p9'))
                    ->state(fn (array $record): string => $record['completionRatio']->label())
                    ->description(fn (array $record): string => self::completionBreakdown($record)),
                // P8 (Task 7): đầu kỳ → cuối kỳ từ ảnh chụp; dòng "Chung" không có (docblock PerformanceRow).
                TextColumn::make('trend')
                    ->label(__('performance.columns.p8'))
                    ->state(fn (array $record): ?string => $record['userId'] === null ? null : __('performance.period_page.p8_overdue', [
                        'start' => self::snapshotValue($record['overdueStart']),
                        'end' => self::snapshotValue($record['overdueEnd']),
                    ]))
                    ->description(fn (array $record): ?string => self::staleTrendLabel($record))
                    ->placeholder('—'),
            ])
            ->filters([
                Filter::make('include_inactive')
                    ->label(__('performance.period_page.include_inactive'))
                    ->toggle(),
            ])
            ->filtersLayout(FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->paginated(false)
            ->emptyStateHeading(__('performance.period_page.empty'));
    }

    /**
     * Câu giải thích của mọi con số trên trang, theo thứ tự cột (R6): khối thu gọn "Cách tính các con số"
     * của view in đủ danh sách này. `p7` chỉ khi có cột doanh thu; `reference` chỉ khi có dòng "Chung".
     *
     * @return list<array{label: string, sentence: string}>
     */
    public function explanations(): array
    {
        $report = $this->report();

        $codes = array_filter(self::EXPLAINED_CODES, fn (string $code): bool => match ($code) {
            'p7' => $report->revenueVisible,
            'reference' => $report->reference !== null,
            default => true,
        });

        return array_values(array_map(fn (string $code): array => [
            'label' => match ($code) {
                'not_applicable' => __('performance.not_applicable'),
                'reference' => __('performance.period_page.reference_name'),
                'closed_period' => __('performance.period_page.closed_period_label'),
                default => __("performance.columns.{$code}"),
            },
            'sentence' => __("performance.explain.{$code}"),
        ], $codes));
    }

    /**
     * Báo cáo của kỳ đang hiện và công tắc đang bật — {@see BuildPerformanceReport} trên người của
     * `TeamRoster::subjectsForPeriod()`, tính MỘT lần cho mỗi (kỳ, công tắc) trong một request.
     */
    public function report(): PerformanceReport
    {
        $key = $this->stateKey();

        if ($this->report === null || $this->reportKey !== $key) {
            /** @var User $viewer */
            $viewer = Auth::user();

            $this->report = app(BuildPerformanceReport::class)->handle($viewer, $this->subjects(), $this->period());
            $this->reportKey = $key;
        }

        return $this->report;
    }

    /**
     * Cột doanh thu có hiện không — {@see BuildPerformanceReport::revenueVisible()}, cùng luật mà báo cáo
     * mang trong `revenueVisible`. Không dựng báo cáo để trả lời: Filament hỏi `visible()` của cột ngay lúc
     * dựng bảng, cả khi hydrate một request đổi kỳ (lúc đó kỳ còn là kỳ CŨ), và mỗi lần dựng báo cáo một quý
     * trên vài nghìn vụ là hàng trăm mili giây (Task 8, số đo R11).
     */
    public function revenueVisible(): bool
    {
        if ($this->report !== null && $this->reportKey === $this->stateKey()) {
            return $this->report->revenueVisible;
        }

        /** @var User $viewer */
        $viewer = Auth::user();

        return BuildPerformanceReport::revenueVisible($viewer, $this->subjects());
    }

    /**
     * Người của kỳ đang hiện và công tắc đang bật (`TeamRoster::subjectsForPeriod()`), nạp MỘT lần cho
     * mỗi (kỳ, công tắc) trong một request.
     *
     * @return Collection<int, User>
     */
    private function subjects(): Collection
    {
        $key = $this->stateKey();

        if ($this->subjects === null || $this->subjectsKey !== $key) {
            /** @var User $viewer */
            $viewer = Auth::user();
            $includeInactive = (bool) ($this->tableFilters['include_inactive']['isActive'] ?? false);

            $this->subjects = TeamRoster::subjectsForPeriod($viewer, $this->period(), $includeInactive);
            $this->subjectsKey = $key;
        }

        return $this->subjects;
    }

    /** (kỳ, công tắc) — khoá của báo cáo và tập người đã nạp trong request này. */
    private function stateKey(): string
    {
        $period = $this->period();

        return serialize([$period->key, $period->bounds(), (bool) ($this->tableFilters['include_inactive']['isActive'] ?? false)]);
    }

    /**
     * Dòng của bảng: dòng "Chung" (nếu có) đứng đầu, rồi mỗi người theo tên (thứ tự của `TeamRoster`);
     * chỉ sắp xếp theo tên, ngược chiều khi được hỏi (R8).
     *
     * @return array<int|string, array<string, mixed>>
     */
    private function records(?string $sortColumn, ?string $sortDirection): array
    {
        $report = $this->report();

        $people = array_map(fn (PerformanceRow $row): array => get_object_vars($row), $report->rows);

        if ($sortColumn === 'name' && $sortDirection === 'desc') {
            $people = array_reverse($people, preserve_keys: true);
        }

        return $report->reference === null
            ? $people
            : [self::REFERENCE_KEY => get_object_vars($report->reference)] + $people;
    }

    /** Ghi `performance_viewed` cho người có `performance.viewAny` (R14); không ghi khi xem số của chính mình. */
    private function recordViewed(PerformancePeriod $period): void
    {
        /** @var User $viewer */
        $viewer = Auth::user();

        if (! Gate::forUser($viewer)->allows(Permission::PerformanceViewAny->value)) {
            return;
        }

        Audit::record('performance_viewed', null, [
            'page' => 'performance',
            'period' => $period->key,
            'from' => $period->from->toDateString(),
            'to' => $period->to->toDateString(),
        ], causer: $viewer);
    }

    /** @param  list<array{name: string, matters: int}>  $areas */
    private static function areasLabel(array $areas): ?string
    {
        if ($areas === []) {
            return null;
        }

        return implode(' · ', array_map(fn (array $area): string => "{$area['name']} ({$area['matters']})", $areas));
    }

    /** P3: "Trung vị 3,5 giờ · trung bình 5 giờ (giờ làm việc, R17)"; không gì khi chưa luồng nào được trả lời. @param  array<string, mixed>  $record */
    private static function responseLabel(array $record): ?string
    {
        if ($record['responseMedianHours'] === null) {
            return null;
        }

        return __('performance.period_page.p3_response', [
            'median' => ResponseTime::label($record['responseMedianHours']),
            'mean' => ResponseTime::label($record['responseMeanHours']),
        ]);
    }

    /** P4: "12 lần · 7 vụ"; `null` ("Không áp dụng") khi người này không phụ trách vụ. @param  array<string, mixed>  $record */
    private static function stageLabel(array $record): ?string
    {
        if ($record['stageEntries'] === null) {
            return null;
        }

        return __('performance.period_page.p4_state', ['entries' => $record['stageEntries'], 'matters' => $record['mattersMoved']]);
    }

    /** Một số của ảnh chụp (P8): "—" khi ngày đó không có ảnh chụp, không bao giờ 0. */
    private static function snapshotValue(?int $value): string
    {
        return $value === null ? '—' : (string) $value;
    }

    /**
     * Phần N4 của P8: "Quá hạn cập nhật: 3 → 1"; "Không áp dụng" với người không đứng tên phụ trách vụ
     * (R6 — ảnh chụp ghi 0 cho họ). Dòng "Chung" không cần nhánh riêng: ô của nó rỗng (placeholder "—"), và
     * Filament không in mô tả dưới một ô rỗng.
     *
     * @param  array<string, mixed>  $record
     */
    private static function staleTrendLabel(array $record): string
    {
        if (! $record['leadsMatters']) {
            return __('performance.period_page.p8_stale_not_applicable');
        }

        return __('performance.period_page.p8_stale', [
            'start' => self::snapshotValue($record['staleStart']),
            'end' => self::snapshotValue($record['staleEnd']),
        ]);
    }

    /** Phân rã R7 in cạnh tỉ lệ P9: "12/15 mốc · 8/9 yêu cầu". @param  array<string, mixed>  $record */
    private static function completionBreakdown(array $record): string
    {
        /** @var Ratio $onTime */
        $onTime = $record['onTimeRatio'];

        return __('performance.period_page.p9_breakdown', [
            'deadlines_done' => $record['deadlinesOnTime'] + $record['deadlinesLate'],
            'deadlines' => $onTime->denominator,
            'answered' => $record['requestsAnswered'],
            'received' => $record['requestsReceived'],
        ]);
    }
}
