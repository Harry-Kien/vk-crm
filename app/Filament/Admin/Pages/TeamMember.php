<?php

namespace App\Filament\Admin\Pages;

use App\Actions\Performance\BuildMatterTypeMix;
use App\Actions\Performance\BuildTeamWorkload;
use App\Enums\Permission;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\RelationManagers\DeadlinesRelationManager;
use App\Filament\Admin\Widgets\PendingChecklistReviewsWidget;
use App\Filament\Admin\Widgets\UpcomingDeadlinesWidget;
use App\Models\ClientRequest;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Support\Audit;
use App\Support\MatterStaleness;
use App\Support\Performance\TeamRoster;
use App\Support\Performance\TeamWorkloadRow;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

/**
 * "Trang của một người" (`/team/{user}`, M13 R1) — chỗ đi sâu từ "Theo dõi đội ngũ" và "Hiệu suất
 * theo kỳ". Với người không có `performance.viewAny` (luật sư, trợ lý) đây là mục "Việc của tôi",
 * trỏ tới trang của chính họ. Task 1 dựng khung (cổng, điều hướng, câu phạm vi R4); Task 5 lấp nội
 * dung (bên dưới); Task 7 thêm hai widget xu hướng.
 *
 * # Hai cổng, cả hai hỏi ở mọi request, từ chối là MỘT 404
 *
 * 1. Cổng trang, {@see self::canAccess()}: `matter.view` HOẶC `performance.viewAny`. Kế toán không
 *    có cả hai: 404, kể cả trang "của mình".
 * 2. Cổng người, {@see self::authorizedSubject()}: người dùng CHƯA xoá mềm mang đúng id đó (đang
 *    hoạt động hay đã nghỉ việc đều được), rồi `Gate::forUser($viewer)->allows('viewPerformance',
 *    $subject)` ({@see UserPolicy::viewPerformance()}: `performance.viewAny`, hoặc chính mình
 *    với `matter.view`; và người đó thuộc danh sách {@see TeamRoster}). Id không phải số nguyên
 *    dương, id không tồn tại, người đã xoá mềm, admin, kế toán và đồng nghiệp của một luật sư đều đi
 *    cùng MỘT `abort(404)` — cùng một response từng byte (SPEC §10.10). Chuỗi kiểu `5abc` bị chặn
 *    trước truy vấn: MariaDB ép nó thành số 5 khi so với cột số.
 *
 * Cổng người MẠNH HƠN cổng trang: `viewPerformance` đòi `performance.viewAny` hoặc `matter.view`, tức
 * đã kéo theo `canAccess()`. Vì vậy:
 *  - {@see self::mount()} hỏi cổng người; ngay sau đó `mountCanAuthorizeAccess()` của Filament hỏi
 *    `canAccess()` (403, đổi thành 404 bởi middleware của panel trên lần tải trang);
 *  - {@see self::boot()} hỏi lại cổng người ở MỌI request cập nhật Livewire (lọc, sắp xếp, phân trang
 *    bảng "Vụ việc"), TRƯỚC `hydrateCanAuthorizeAccess()` của Filament (403 bên trong vòng đời
 *    component không qua được middleware 404) — đọc lại người dùng từ CSDL mỗi lần, không tin request
 *    trước: người xem mất quyền, người được xem rời danh sách hay bị xoá mềm khi trang còn mở thì
 *    request kế tiếp là 404. `boot()` không hỏi thêm `canAccess()`: câu đó bị cổng người bao trọn,
 *    nên một lần hỏi như vậy không mutation probe nào chứng minh được (khác `TeamOverview`/
 *    `Performance`, không có chủ thể).
 *
 * # Id người là `#[Locked]`, và là thuộc tính công khai DUY NHẤT
 *
 * {@see self::$subjectId} được đặt đúng một lần ở `mount()`, từ URL; trình duyệt không đặt lại được
 * (Livewire ném `CannotUpdateLockedPropertyException`). Khoá không thay cho việc gác: `boot()` vẫn
 * hỏi lại Gate trên id đó ở mỗi request. Không thuộc tính công khai nào khác mang id người hay id vụ:
 * trạng thái Livewire của bảng chỉ mang bộ lọc và cột sắp xếp; mọi thứ khác đến view qua
 * {@see self::getViewData()} (protected, không gọi được từ trình duyệt), tính lại ở mỗi lần vẽ.
 *
 * # Nội dung (Task 5) — trang không tự đếm, không tự viết điều kiện nghiệp vụ
 *
 * `NoSecondDefinitionTest` quét tệp này. Mỗi phần gọi lại đúng nguồn sự thật:
 *  - **Đầu trang:** tên, chức danh (`UserPosition::label()`), trạng thái (kể cả "Đã nghỉ việc", R3);
 *    ĐÚNG {@see TeamWorkloadRow} của người đó — {@see BuildTeamWorkload} gọi với một người và hỏi N11
 *    (phán quyết N11 của Task 4: N11 chỉ ở trang này, tính cho một người). Cột của người phụ trách
 *    vụ in "Không áp dụng" khi trường `null` (R6), mốc quá hạn và vụ quá hạn cập nhật > 0 tô
 *    `var(--danger-600)` qua {@see TeamOverview::dangerWhenPositive()} — in như Task 4.
 *  - **Cơ cấu lĩnh vực:** {@see BuildMatterTypeMix} — tập N1 theo loại vụ việc, hoặc tập N2 với
 *    người không đứng tên phụ trách vụ (trợ lý); tiêu đề nói tập nào.
 *  - **Bảng "Vụ việc"** (bảng Filament trên Eloquent): `Matter::listableBy($viewer)->workedOnBy($subject)`;
 *    lọc "đang mở/đã kết thúc" bằng `open()`/`closed()`, "phụ trách/tham gia" bằng `ledBy()`/
 *    `supportedBy()`. Vai của người đó đọc từ ghế của họ trong đội ngũ (quan hệ `team` nạp sẵn, chỉ
 *    ghế của người đó — `Matter::booted()` và `ReassignMatter` giữ ghế `lead` khớp `lead_lawyer_id`);
 *    "cập nhật gần nhất cho khách" tô màu bằng `MatterStaleness::color()`, như `MattersTable`. Mỗi dòng
 *    mở trang xem vụ việc; trang đó tự kiểm quyền.
 *  - **Ba danh sách ngắn** (tối đa {@see self::LIST_LIMIT} việc gấp nhất mỗi danh sách, kèm tổng số —
 *    chính con số đầu trang), mỗi danh sách là truy vấn của widget trang chủ cộng một scope người, và
 *    chỉ hiện với người thấy được widget đó (`canView()` của chính widget; danh sách yêu cầu không có
 *    widget nên hỏi `matter.view`, quyền đọc nội dung hồ sơ):
 *    mốc = `UpcomingDeadlinesWidget::rowsFor($viewer)->heldBy($subject)` (đúng các mốc N5 + N6);
 *    yêu cầu = `ClientRequest::awaitingOffice()->heldBy($subject)` trên vụ `open()->listableBy()`
 *    (đúng các luồng N9 — người được giao đã xoá mềm nhường cho luật sư phụ trách, như đường thông
 *    báo); giấy tờ = `PendingChecklistReviewsWidget::rowsFor($viewer)` trên vụ `ledBy($subject)`
 *    (đúng các đầu mục N8; "Không áp dụng" với người không đứng tên phụ trách vụ, R6).
 *  - **Cột tiêu đề** chỉ hiện với người có `matter.view`, như `MattersTable`: một người có
 *    `performance.viewAny` mà không có `matter.view` đọc số, không đọc nội dung hồ sơ.
 *
 * Mọi tập đều nằm trong `listableBy($viewer)` (R4): vụ `restricted` của X chỉ hiện với X và admin, ở
 * bảng, ở danh sách và trong số đầu trang — `RestrictedLeakSweepTest` so cả trang của trưởng phòng
 * khi có và không có các vụ đó.
 *
 * # Nhật ký (R14)
 *
 * {@see self::mount()} ghi MỘT dòng `performance_viewed` (chủ thể là người được xem) mỗi lần mở trang
 * của NGƯỜI KHÁC — không khi xem chính mình, không ở request Livewire (lọc, sắp xếp). `mount()` hỏi
 * cổng người trước khi ghi, nên người bị từ chối không để lại dòng nào.
 *
 * # Xu hướng (Task 7)
 *
 * Hai widget xu hướng sẽ vào {@see self::getFooterWidgets()}; trang truyền `subjectId` qua
 * {@see self::getWidgetData()}. Widget là một component Livewire riêng: nó không tin giá trị đó, tự
 * khoá `#[Locked]` và tự hỏi `viewPerformance` ở `mount()` và `boot()`.
 */
class TeamMember extends Page implements HasTable
{
    use InteractsWithTable;

    /**
     * Mã các câu giải thích của khối "Cách tính các con số" (khoá `performance.explain.<mã>`, R6),
     * theo thứ tự in ở đầu trang. Cơ cấu lĩnh vực và ba danh sách có câu riêng ({@see self::explanations()}).
     *
     * @var list<string>
     */
    public const EXPLAINED_CODES = ['n1', 'n2', 'n3', 'n4', 'n5', 'n6', 'n7', 'n8', 'n9', 'n10', 'n11', 'not_applicable'];

    /**
     * "Ba danh sách ngắn": mỗi danh sách in tối đa chừng này việc gấp nhất — mốc quá hạn lâu nhất trước,
     * luồng chờ lâu nhất trước, giấy tờ nộp sớm nhất trước (thứ tự của widget trang chủ) — và nói tổng
     * số. Tổng là chính con số đầu trang (N5 + N6, N9, N8): cùng tập, `TeamMemberPageTest` ghim. Không
     * giới hạn thì một người giữ vài trăm việc làm trang vượt ngân sách 200 ms của R11 (benchmark).
     */
    public const LIST_LIMIT = 10;

    /** Định dạng thời điểm trên trang — như `PendingChecklistReviewsWidget`. */
    private const MOMENT_FORMAT = 'H:i d/m/Y';

    protected string $view = 'filament.admin.pages.team-member';

    protected static ?string $slug = 'team-member';

    /** "Việc của tôi". Không trùng icon nào đã dùng trong `app/` (`OutlinedUserCircle` là icon của hành động "Đổi người phụ trách" trên tab Mốc thời hạn). */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    #[Locked]
    public int $subjectId;

    /** Bộ nhớ đệm TRONG một request (thuộc tính `private` không được Livewire serialize). */
    private ?User $subject = null;

    /** Bộ nhớ đệm TRONG một request của dòng {@see BuildTeamWorkload}. */
    private ?TeamWorkloadRow $workload = null;

    /**
     * Đường dẫn `/team/{user}`: nối dưới slug của {@see TeamOverview}. Slug riêng (`team-member`) giữ
     * tên route khác (`filament.admin.pages.team-member`), vì `getRelativeRouteName()` dựng tên route
     * từ slug — cùng hình dạng với `MyRequests::getRoutePath()` của cổng khách.
     */
    public static function getRoutePath(Panel $panel): string
    {
        return '/'.TeamOverview::getSlug($panel).'/{user}';
    }

    public static function getNavigationLabel(): string
    {
        return __('performance.pages.team_member.navigation_label');
    }

    /** "Việc của tôi" trỏ tới trang của chính người đang đăng nhập (`Page::getNavigationUrl()` mặc định là `getUrl()` không tham số). */
    public static function getNavigationUrl(): string
    {
        return static::getUrl(['user' => Auth::id()]);
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

    /**
     * Mục "Việc của tôi" chỉ cho người KHÔNG có `performance.viewAny` (họ đã có "Theo dõi đội ngũ")
     * và chỉ khi trang của chính họ mở được — không bao giờ một liên kết dẫn tới 404.
     */
    public static function shouldRegisterNavigation(): bool
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return false;
        }

        $gate = Gate::forUser($user);

        return ! $gate->allows(Permission::PerformanceViewAny->value)
            && $gate->allows('viewPerformance', $user);
    }

    /**
     * Xem docblock lớp, mục "Hai cổng" — 404 ở mọi request cập nhật Livewire. Lần mount: id chưa có
     * (`boot()` chạy trước `mount()`, nơi id được đặt và cổng người được hỏi lần đầu). Request cập
     * nhật: id đã hydrate từ snapshot trước khi `boot()` chạy.
     */
    public function boot(): void
    {
        if (isset($this->subjectId)) {
            $this->subject = $this->authorizedSubject($this->subjectId);
        }
    }

    /** Cổng người, rồi đúng một dòng `performance_viewed` khi người xem không phải người được xem (R14). */
    public function mount(int|string $user): void
    {
        $this->subject = $this->authorizedSubject($user);
        $this->subjectId = $this->subject->getKey();

        $viewer = $this->viewer();

        if (! $this->subject->is($viewer)) {
            Audit::record('performance_viewed', $this->subject, [], causer: $viewer);
        }
    }

    public function getTitle(): string
    {
        $subject = $this->subject();

        return $subject->is(Auth::user())
            ? __('performance.pages.team_member.title_self')
            : __('performance.pages.team_member.title', ['name' => $subject->name]);
    }

    /**
     * Giá trị khởi đầu cho widget nhúng trong trang (Task 7: hai widget xu hướng). Widget không tin
     * giá trị này — xem docblock lớp, mục "Xu hướng".
     *
     * @return array{subjectId: int}
     */
    public function getWidgetData(): array
    {
        return ['subjectId' => $this->subjectId];
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('performance.team_member.matters.heading'))
            ->query(fn (): Builder => $this->mattersQuery())
            ->columns([
                TextColumn::make('code')
                    ->label(__('matters.fields.code'))
                    ->sortable(),
                TextColumn::make('client.name')
                    ->label(__('matters.fields.client')),
                TextColumn::make('title')
                    ->label(__('matters.fields.title'))
                    ->wrap()
                    ->visible(fn (): bool => Gate::forUser($this->viewer())->allows(Permission::MatterView->value)),
                TextColumn::make('stage')
                    ->label(__('matters.fields.stage'))
                    ->badge()
                    ->formatStateUsing(fn (Matter $record): string => $record->currentStage()?->label ?? $record->stage)
                    ->color(fn (Matter $record): string => $record->currentStage()?->is_terminal ? 'success' : 'info'),
                TextColumn::make('subject_role')
                    ->label(__('performance.team_member.matters.role'))
                    ->state(fn (Matter $record): ?string => $record->team->first()?->pivot?->role_in_matter?->label())
                    ->placeholder('—'),
                // Như cột cùng tên của `MattersTable`: chưa từng cập nhật mà đã quá hạn thì hiện câu
                // "Chưa cập nhật lần nào" (Filament không tô màu một ô trống).
                TextColumn::make('last_client_update_at')
                    ->label(__('matters.fields.last_client_update_at'))
                    ->state(fn (Matter $record): ?string => $record->last_client_update_at?->diffForHumans()
                        ?? (MatterStaleness::color($record) !== null ? __('matters.fields.never_updated') : null))
                    ->color(fn (Matter $record): ?string => MatterStaleness::color($record))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label(__('performance.team_member.matters.filters.state'))
                    ->options([
                        'open' => __('performance.team_member.matters.filters.open'),
                        'closed' => __('performance.team_member.matters.filters.closed'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'open' => $query->open(),
                        'closed' => $query->closed(),
                        default => $query,
                    }),
                SelectFilter::make('role')
                    ->label(__('performance.team_member.matters.filters.role'))
                    ->options([
                        'lead' => __('performance.team_member.matters.filters.lead'),
                        'supporting' => __('performance.team_member.matters.filters.supporting'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'lead' => $query->ledBy($this->subject()),
                        'supporting' => $query->supportedBy($this->subject()),
                        default => $query,
                    }),
            ])
            ->filtersLayout(FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->recordUrl(fn (Matter $record): string => MatterResource::getUrl('view', ['record' => $record], panel: 'admin'))
            ->defaultSort('code')
            ->emptyStateHeading(__('performance.team_member.matters.empty'));
    }

    protected function subject(): User
    {
        return $this->subject ??= $this->authorizedSubject($this->subjectId);
    }

    /**
     * Mọi thứ view vẽ ngoài bảng — tính lại ở mỗi lần vẽ (lần mở trang và mỗi request Livewire, sau
     * `boot()`), không bao giờ nằm trong trạng thái công khai của component.
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $viewer = $this->viewer();
        $subject = $this->subject();
        $row = $this->workload();

        return [
            'member' => [
                'name' => $subject->name,
                'position' => $subject->position?->label(),
                'isActive' => $row->isActive,
            ],
            'metrics' => self::metrics($row),
            'mix' => app(BuildMatterTypeMix::class)->handle($viewer, $subject),
            'lists' => $this->lists($viewer, $subject, $row),
            'explanations' => $this->explanations(),
        ];
    }

    /**
     * Câu giải thích của mọi con số trên trang (R6): N1–N11 và "Không áp dụng" theo thứ tự đầu trang,
     * rồi cơ cấu lĩnh vực và ba danh sách.
     *
     * @return list<array{label: string, sentence: string}>
     */
    private function explanations(): array
    {
        return [
            ...array_map(fn (string $code): array => [
                'label' => $code === 'not_applicable' ? __('performance.not_applicable') : __("performance.columns.{$code}"),
                'sentence' => __("performance.explain.{$code}"),
            ], self::EXPLAINED_CODES),
            ['label' => __('performance.team_member.mix.label'), 'sentence' => __('performance.team_member.mix.explain')],
            ['label' => __('performance.team_member.lists.label'), 'sentence' => __('performance.team_member.lists.explain', ['limit' => self::LIST_LIMIT])],
        ];
    }

    /** Dòng của người này — {@see BuildTeamWorkload} với một người, có N11 (xem docblock lớp). */
    private function workload(): TeamWorkloadRow
    {
        $subject = $this->subject();

        return $this->workload ??= app(BuildTeamWorkload::class)
            ->handle($this->viewer(), collect([$subject]), withLastMatterActivity: true)[$subject->getKey()];
    }

    /**
     * Các con số đầu trang theo thứ tự cột N1–N11, mỗi con số dưới nhãn `performance.columns.<mã>`.
     * `value` rỗng = "Không áp dụng" (R6); `note` là số đi kèm của N4 ("chưa bật cổng").
     *
     * @return list<array{key: string, label: string, value: string|Htmlable|null, note: ?string}>
     */
    private static function metrics(TeamWorkloadRow $row): array
    {
        $count = fn (?int $value): ?string => $value === null ? null : (string) $value;
        $metric = fn (string $key, string $code, string|Htmlable|null $value, ?string $note = null): array => [
            'key' => $key,
            'label' => __("performance.columns.{$code}"),
            'value' => $value,
            'note' => $note,
        ];

        return [
            $metric('leadOpen', 'n1', $count($row->leadOpen)),
            $metric('teamOpen', 'n2', $count($row->teamOpen)),
            $metric('leadClosed', 'n3', $count($row->leadClosed)),
            $metric(
                'stale',
                'n4',
                $row->stale === null ? null : TeamOverview::dangerWhenPositive($row->stale),
                $row->notMeasurable === null ? null : __('performance.team_overview.not_measurable', ['count' => $row->notMeasurable]),
            ),
            $metric('overdueDeadlines', 'n5', TeamOverview::dangerWhenPositive($row->overdueDeadlines)),
            $metric('deadlinesDueSoon', 'n6', $count($row->deadlinesDueSoon)),
            $metric(
                'awaitingClientMatters',
                'n7',
                $row->awaitingClientMatters === null ? null : "{$row->awaitingClientMatters} ({$row->awaitingClientStuck})",
            ),
            $metric('awaitingReviewItems', 'n8', $count($row->awaitingReviewItems)),
            $metric('awaitingOfficeRequests', 'n9', $count($row->awaitingOfficeRequests)),
            $metric('checklist', 'n10', $row->checklistTotal === null ? null : "{$row->checklistSettled}/{$row->checklistTotal}"),
            $metric(
                'lastMatterActivityAt',
                'n11',
                $row->lastMatterActivityAt?->format(self::MOMENT_FORMAT) ?? __('performance.team_member.no_activity'),
            ),
        ];
    }

    /**
     * Ba danh sách ngắn — xem docblock lớp, mục "Nội dung". Khoá chỉ có mặt khi người xem thấy được
     * widget trang chủ tương ứng; `applicable` sai = "Không áp dụng" (giấy tờ chờ duyệt của người
     * không đứng tên phụ trách vụ, R6). `rows` là tối đa {@see self::LIST_LIMIT} việc gấp nhất; `total`
     * là con số đầu trang của cùng tập (N5 + N6, N9, N8 — rỗng thì 0), để view nói "hiện … trên tổng …".
     *
     * @return array<string, array{applicable: bool, total: int, rows: list<array<string, mixed>>}>
     */
    private function lists(User $viewer, User $subject, TeamWorkloadRow $row): array
    {
        $lists = [];
        $leadsMatters = $row->leadsMatters;
        $matterUrl = fn (int $matterId): string => MatterResource::getUrl('view', ['record' => $matterId], panel: 'admin');

        if (UpcomingDeadlinesWidget::canView()) {
            $lists['deadlines'] = [
                'applicable' => true,
                'total' => $row->overdueDeadlines + $row->deadlinesDueSoon,
                'rows' => UpcomingDeadlinesWidget::rowsFor($viewer)
                    ->heldBy($subject)
                    ->orderBy('deadlines.due_date')
                    ->orderBy('deadlines.id')
                    ->limit(self::LIST_LIMIT)
                    ->get()
                    ->map(fn (Deadline $deadline): array => [
                        'id' => $deadline->getKey(),
                        'url' => $matterUrl($deadline->matter_id),
                        'code' => $deadline->matter->code,
                        'due' => DeadlinesRelationManager::renderDueDate($deadline),
                        'name' => $deadline->name,
                        'severity' => UpcomingDeadlinesWidget::renderSeverity($deadline->severity),
                    ])
                    ->all(),
            ];
        }

        if (Gate::forUser($viewer)->allows(Permission::MatterView->value)) {
            $lists['requests'] = [
                'applicable' => true,
                'total' => $row->awaitingOfficeRequests,
                'rows' => ClientRequest::query()
                    ->awaitingOffice()
                    ->whereHas('matter', fn (Builder $matter): Builder => $matter->open()->listableBy($viewer))
                    ->heldBy($subject)
                    ->with('matter')
                    ->orderBy('client_requests.created_at')
                    ->orderBy('client_requests.id')
                    ->limit(self::LIST_LIMIT)
                    ->get()
                    ->map(fn (ClientRequest $request): array => [
                        'id' => $request->getKey(),
                        'url' => $matterUrl($request->matter_id),
                        'code' => $request->matter->code,
                        'subject' => $request->subject,
                        'status' => $request->status->label(),
                        'sentAt' => $request->created_at->format(self::MOMENT_FORMAT),
                    ])
                    ->all(),
            ];
        }

        if (PendingChecklistReviewsWidget::canView()) {
            $lists['reviews'] = [
                'applicable' => $leadsMatters,
                'total' => (int) $row->awaitingReviewItems,
                'rows' => ! $leadsMatters ? [] : PendingChecklistReviewsWidget::rowsFor($viewer)
                    ->whereHas('matter', fn (Builder $matter): Builder => $matter->ledBy($subject))
                    ->orderBy(PendingChecklistReviewsWidget::SUBMITTED_AT_ALIAS)
                    ->orderBy('matter_checklist_items.id')
                    ->limit(self::LIST_LIMIT)
                    ->get()
                    ->map(fn (MatterChecklistItem $item): array => [
                        'id' => $item->getKey(),
                        'url' => $matterUrl($item->matter_id),
                        'code' => $item->matter->code,
                        'client' => $item->matter->client?->name,
                        'name' => $item->name,
                        'submittedAt' => ($submitted = $item->getAttribute(PendingChecklistReviewsWidget::SUBMITTED_AT_ALIAS)) === null
                            ? __('widgets.pending_checklist_reviews.never_submitted')
                            : CarbonImmutable::parse($submitted)->format(self::MOMENT_FORMAT),
                    ])
                    ->all(),
            ];
        }

        return $lists;
    }

    /**
     * Bảng "Vụ việc": phần giao `listableBy($viewer)` ∩ việc của người này (R4), nạp sẵn khách, loại vụ
     * cùng giai đoạn (nhãn giai đoạn) và ĐÚNG ghế của người này trong đội ngũ (cột vai).
     *
     * @return Builder<Matter>
     */
    private function mattersQuery(): Builder
    {
        $subject = $this->subject();

        return Matter::query()
            ->listableBy($this->viewer())
            ->workedOnBy($subject)
            ->with([
                'client',
                'matterType.stages',
                'team' => fn (BelongsToMany $team): BelongsToMany => $team->whereKey($subject->getKey()),
            ]);
    }

    private function viewer(): User
    {
        $viewer = Auth::user();

        abort_unless($viewer instanceof User, 404);

        return $viewer;
    }

    /** Xem docblock lớp, mục "Hai cổng" — mọi lời từ chối là cùng một `abort(404)`. */
    private function authorizedSubject(int|string $id): User
    {
        $viewer = Auth::user();
        $key = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $subject = $key === false ? null : User::query()->with(['roles.permissions', 'permissions'])->find($key);

        abort_unless(
            $viewer instanceof User
                && $subject instanceof User
                && Gate::forUser($viewer)->allows('viewPerformance', $subject),
            404,
        );

        return $subject;
    }
}
