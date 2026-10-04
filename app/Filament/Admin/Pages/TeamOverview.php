<?php

namespace App\Filament\Admin\Pages;

use App\Actions\Performance\BuildTeamWorkload;
use App\Enums\Permission;
use App\Models\User;
use App\Support\Audit;
use App\Support\Performance\TeamRoster;
use App\Support\Performance\TeamWorkloadRow;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;

/**
 * Trang "Theo dõi đội ngũ" (`/team`, M13 R1): BÂY GIỜ ai đang giữ gì, cái gì đang nguy hiểm — hàng
 * đợi hành động của trưởng phòng. Mỗi người được theo dõi một dòng, cột N1–N10 của
 * {@see BuildTeamWorkload}; trang không tự đếm gì.
 *
 * **Không có cột N11** ("Thao tác hồ sơ gần nhất"): phán quyết N11 của Task 4 (docblock
 * `BuildTeamWorkload`) — truy vấn gộp cho mọi người vượt ngưỡng 150 ms của kế hoạch trên dữ liệu
 * benchmark, nên N11 chỉ còn trên trang của một người, tính cho một người. Trang này gọi Action không
 * hỏi N11.
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
 *    nhật (bật công tắc, sắp xếp), trước `hydrateCanAuthorizeAccess()` của Filament (hook đó trả 403,
 *    mà 403 bên trong vòng đời component không qua được middleware 404). Người mất quyền khi trang
 *    còn mở nhận 404 ở request kế tiếp. Vì `boot()` luôn chạy trước `mount()`, `mount()` không hỏi
 *    lại lần thứ ba: một lần hỏi không đường nào tới được là một điều kiện không mutation probe nào
 *    chứng minh được.
 *
 * # Bảng: `Table::records()`, sắp xếp bằng PHP (R8, R11)
 *
 * Dòng là mảng thuộc tính của {@see TeamWorkloadRow}, khoá là id người. Danh sách người là
 * `TeamRoster::subjectsFor()` — chỉ phụ thuộc vai trò (R3), không bao giờ "người có vụ mà người xem
 * thấy" — xếp theo tên. Trạng thái Livewire chỉ mang bộ lọc (công tắc) và cột sắp xếp; không thuộc
 * tính công khai nào mang danh sách id người hay id vụ.
 *  - Chỉ các cột ĐẾM VIỆC ĐANG TỒN sắp xếp được ({@see self::SORTABLE_COLUMNS}, cộng tên): sắp xếp để
 *    biết phải gọi ai trước, không để phán xét. Cột tỉ lệ `X/Y` (N10) và "Vụ đã kết thúc" (N3) không
 *    sắp xếp được; một lời gọi `sortTable` lên chúng hay lên một tên bất kỳ (Livewire nhận tên cột bất
 *    kỳ) giữ nguyên thứ tự theo tên.
 *  - "Không áp dụng" (`null`) xếp như giá trị rỗng: SAU mọi số, ở cả hai chiều. Bằng nhau thì giữ
 *    thứ tự theo tên (`uasort` của PHP 8 ổn định).
 *  - Màu chỉ theo ngưỡng tuyệt đối đã có (R8): mốc quá hạn > 0 và vụ quá hạn cập nhật > 0 tô
 *    `var(--danger-600)` — tiền lệ `->color('danger')` của `StaleMattersWidget`, nói bằng style nội
 *    tuyến vì dự án không có bước dựng CSS. Tỉ lệ không bao giờ tô màu.
 *
 * # Nhật ký (R14)
 *
 * {@see self::mount()} ghi MỘT dòng `performance_viewed` (chủ thể rỗng, `page = team_overview`):
 * trang hiện số "bây giờ" của MỌI người được theo dõi, nên mở nó là xem số của người khác. Chỉ ở
 * `mount()` — request Livewire (bật công tắc, sắp xếp) không ghi thêm. `mount()` chạy sau `boot()`,
 * nên không ai bị từ chối để lại dòng nào.
 */
class TeamOverview extends Page implements HasTable
{
    use InteractsWithTable;

    /**
     * Cột đếm việc đang tồn — những cột duy nhất (ngoài tên) sắp xếp được (R8), theo thứ tự cột.
     *
     * @var list<string>
     */
    public const SORTABLE_COLUMNS = [
        'leadOpen', 'teamOpen', 'stale', 'overdueDeadlines', 'deadlinesDueSoon',
        'awaitingClientMatters', 'awaitingReviewItems', 'awaitingOfficeRequests',
    ];

    /**
     * Mã các câu giải thích của khối "Cách tính các con số" (khoá `performance.explain.<mã>`, R6),
     * theo thứ tự cột.
     *
     * @var list<string>
     */
    public const EXPLAINED_CODES = ['n1', 'n2', 'n3', 'n4', 'n5', 'n6', 'n7', 'n8', 'n9', 'n10', 'not_applicable'];

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

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?array $filters, ?string $sortColumn, ?string $sortDirection): array => $this->workloadRecords(
                (bool) ($filters['include_inactive']['isActive'] ?? false),
                $sortColumn,
                $sortDirection,
            ))
            ->columns([
                TextColumn::make('name')
                    ->label(__('performance.columns.name'))
                    ->url(fn (array $record): string => TeamMember::getUrl(['user' => $record['userId']]))
                    ->description(fn (array $record): ?string => $record['isActive'] ? null : __('performance.team_overview.inactive'))
                    ->sortable(),
                self::countColumn('leadOpen', 'n1'),
                self::countColumn('teamOpen', 'n2'),
                self::countColumn('leadClosed', 'n3'),
                self::countColumn('stale', 'n4', danger: true)
                    ->description(fn (array $record): ?string => $record['notMeasurable'] === null
                        ? null
                        : __('performance.team_overview.not_measurable', ['count' => $record['notMeasurable']])),
                self::countColumn('overdueDeadlines', 'n5', danger: true),
                self::countColumn('deadlinesDueSoon', 'n6'),
                self::countColumn('awaitingClientMatters', 'n7')
                    ->formatStateUsing(fn (int $state, array $record): string => "{$state} ({$record['awaitingClientStuck']})"),
                self::countColumn('awaitingReviewItems', 'n8'),
                self::countColumn('awaitingOfficeRequests', 'n9'),
                TextColumn::make('checklist')
                    ->label(__('performance.columns.n10'))
                    ->state(fn (array $record): ?string => $record['checklistTotal'] === null
                        ? null
                        : "{$record['checklistSettled']}/{$record['checklistTotal']}")
                    ->placeholder(__('performance.not_applicable')),
            ])
            ->filters([
                Filter::make('include_inactive')
                    ->label(__('performance.team_overview.include_inactive'))
                    ->toggle(),
            ])
            ->filtersLayout(FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->paginated(false)
            ->emptyStateHeading(__('performance.team_overview.empty'));
    }

    /**
     * Câu giải thích của mọi con số trên trang, theo thứ tự cột (R6): khối thu gọn "Cách tính các
     * con số" của view in đủ danh sách này.
     *
     * @return list<array{label: string, sentence: string}>
     */
    public function explanations(): array
    {
        return array_map(fn (string $code): array => [
            'label' => $code === 'not_applicable' ? __('performance.not_applicable') : __("performance.columns.{$code}"),
            'sentence' => __("performance.explain.{$code}"),
        ], self::EXPLAINED_CODES);
    }

    /**
     * Cột một con số đếm — công khai để trang của một người (Task 5) in cùng cách. `null` in "Không áp dụng" (R6);
     * `$danger` tô số > 0 bằng `var(--danger-600)` (R8, xem docblock lớp). Sắp xếp được khi và chỉ
     * khi cột nằm trong {@see self::SORTABLE_COLUMNS}.
     */
    public static function countColumn(string $name, string $code, bool $danger = false): TextColumn
    {
        $column = TextColumn::make($name)
            ->label(__("performance.columns.{$code}"))
            ->placeholder(__('performance.not_applicable'))
            ->sortable(in_array($name, self::SORTABLE_COLUMNS, true));

        if ($danger) {
            $column->formatStateUsing(fn (int $state): string|Htmlable => self::dangerWhenPositive($state));
        }

        return $column;
    }

    /** Số > 0 tô `var(--danger-600)` (R8); 0 giữ màu chữ thường. */
    public static function dangerWhenPositive(int $count): string|Htmlable
    {
        return $count > 0
            ? new HtmlString('<span style="color:var(--danger-600);font-weight:600">'.$count.'</span>')
            : (string) $count;
    }

    /**
     * Dòng của bảng: {@see BuildTeamWorkload} trên người của `TeamRoster::subjectsFor()`, mỗi dòng
     * là mảng thuộc tính của `TeamWorkloadRow`, rồi sắp xếp ({@see self::sorted()}).
     *
     * @return array<int, array<string, mixed>> khoá là id người, theo thứ tự hiện
     */
    private function workloadRecords(bool $includeInactive, ?string $sortColumn, ?string $sortDirection): array
    {
        /** @var User $viewer */
        $viewer = Auth::user();

        $rows = app(BuildTeamWorkload::class)->handle($viewer, TeamRoster::subjectsFor($viewer, $includeInactive));

        return self::sorted(
            array_map(fn (TeamWorkloadRow $row): array => get_object_vars($row), $rows),
            $sortColumn,
            $sortDirection,
        );
    }

    /**
     * Thứ tự hiện (R8, xem docblock lớp): theo tên (thứ tự của `TeamRoster`) trừ khi cột sắp xếp là
     * tên hay một cột trong {@see self::SORTABLE_COLUMNS}; "Không áp dụng" sau mọi số ở cả hai chiều.
     *
     * @param  array<int, array<string, mixed>>  $records
     * @return array<int, array<string, mixed>>
     */
    private static function sorted(array $records, ?string $column, ?string $direction): array
    {
        $descending = $direction === 'desc';

        if ($column === 'name') {
            return $descending ? array_reverse($records, preserve_keys: true) : $records;
        }

        if (! in_array($column, self::SORTABLE_COLUMNS, true)) {
            return $records;
        }

        uasort($records, function (array $a, array $b) use ($column, $descending): int {
            if ($a[$column] === null || $b[$column] === null) {
                return ($a[$column] === null) <=> ($b[$column] === null);
            }

            return $descending ? $b[$column] <=> $a[$column] : $a[$column] <=> $b[$column];
        });

        return $records;
    }
}
