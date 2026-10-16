<?php

namespace App\Filament\Admin\Pages;

use App\Actions\Mcp\AiConnection;
use App\Actions\Mcp\AiSetupSummary;
use App\Actions\Mcp\DisconnectAiConnections;
use App\Actions\Mcp\ListAiConnections;
use App\Actions\Mcp\ListMcpAuditEntries;
use App\Actions\Mcp\McpActivityReport;
use App\Actions\Mcp\McpAuditEntry;
use App\Actions\Mcp\SetAiAccessForMany;
use App\Actions\Mcp\SetUserAiAccess;
use App\Actions\Mcp\StaffAiSummary;
use App\Actions\Mcp\SummarizeAiSetup;
use App\Actions\Mcp\SummarizeMcpActivity;
use App\Actions\Mcp\UpdateAiSettings;
use App\Enums\AiAccessMode;
use App\Enums\AiSetupStep;
use App\Enums\Permission;
use App\Filament\Admin\Concerns\ReportsActionFailures;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Models\User;
use App\Support\ActivityPeople;
use App\Support\Mcp\McpAccess;
use App\Support\Mcp\McpSwitches;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * Trang "Kết nối AI" của quản trị (M11 Task 15; R2, R8, R12 mục 3). Tên lớp tiếng Anh (CLAUDE.md:
 * định danh code tiếng Anh; kế hoạch gọi nó `KetNoiAi` — khoảng lệch D9); nhãn, tiêu đề, slug hiển
 * thị tiếng Việt.
 *
 * Năm khối, mỗi khối gọi một Action — trang không ghi Eloquent và không truy vấn `activity_log`:
 *
 *  1. **Dải cảnh báo R12 mục 3** — danh sách việc pháp lý của chủ văn phòng, hiện tới khi quản trị ghi
 *     ngày đã nộp hồ sơ đánh giá tác động; ghi ngày chỉ ẩn dải, không bật/tắt gì khác
 *     ({@see UpdateAiSettings}, trường `transfer_assessment_filed_on`).
 *  2. **Hai công tắc toàn hệ thống** `mcp.enabled`, `mcp.write_enabled` ({@see UpdateAiSettings}).
 *  3. **Bảng nhân sự** — chế độ, ngày cam kết và phiên bản, số kết nối, lần dùng cuối
 *     ({@see ListAiConnections::overview()}, tính theo lô cho cả trang bảng). Nút "Đổi chế độ" gọi
 *     {@see SetUserAiAccess}; người không giữ được quyền AI (thiếu `matter.view`, ví dụ kế toán) hay
 *     đang bị vô hiệu hoá chỉ có lựa chọn "Tắt" — Action vẫn từ chối lần nữa nếu bị ép.
 *  4. **Chi tiết một người** (nút "Kết nối") — từng kết nối: nền tảng suy từ host redirect, chính
 *     host đó, ngày tạo, lần dùng cuối ({@see ListAiConnections::forUser()}); "Thu hồi" từng dòng và
 *     "Thu hồi tất cả" ({@see DisconnectAiConnections}). Người đang xem nằm ở {@see self::$selectedUserId},
 *     `#[Locked]`: trình duyệt không đặt được, chỉ nút "Kết nối" của bảng đặt.
 *  5. **Nhật ký MCP** — 100 dòng gần nhất, lọc theo người và theo tool ({@see ListMcpAuditEntries}),
 *     kèm ghi chú IP là IP của nền tảng.
 *
 * # Làn ai (2026-10-15): trình bày như một CRM quốc tế — không đổi luật nào của M11
 *
 * Chủ văn phòng hỏi trang "hoạt động như thế nào". Thêm, đều chỉ đọc hoặc gọi lại đúng Action cũ:
 *
 *  - **Thẻ trạng thái tổng** và **"Các bước để bắt đầu dùng"** ({@see SummarizeAiSetup}): TẮT / BẬT
 *    chỉ đọc / BẬT đọc và ghi, cảnh báo vàng khi bật mà chưa ghi ngày nộp hồ sơ, bốn con số, sáu bước
 *    ✓/○ theo trạng thái thật ({@see AiSetupStep}), mỗi bước một đường làm ngay;
 *  - khối 1 thành **mục thu gọn** "Việc pháp lý trước khi dùng trên dữ liệu thật" — mở sẵn khi chưa ghi
 *    ngày, đóng khi đã ghi; nội dung và ô ngày giữ nguyên;
 *  - khối 2: **nhãn tiếng thường**, tên khoá kỹ thuật vào "Chi tiết kỹ thuật"; nút lưu mở **hộp xác
 *    nhận** nói hậu quả ({@see self::confirmSwitchesAction()}) rồi gọi {@see self::saveSwitches()};
 *  - khối 3: **huy hiệu màu**, lần dùng cuối "… trước", **bộ lọc nhanh** (cùng định nghĩa với các con số
 *    của thẻ), **"Đổi chế độ" hàng loạt** ({@see SetAiAccessForMany}: mỗi người một lần gọi
 *    {@see SetUserAiAccess});
 *  - **"Hoạt động của trợ lý AI"** 7/30 ngày ({@see SummarizeMcpActivity}, kỳ ở {@see self::$activityDays},
 *    `#[Locked]`, chỉ {@see self::showActivity()} đổi).
 *
 * # Cổng: `settings.manage`, hỏi ở MỌI request — kể cả request cập nhật Livewire
 *
 * Như `OfficeProfilePage`: {@see self::boot()} trả 404 ở đầu lần mount VÀ mọi request cập nhật (hook
 * của Filament trả 403, middleware 404 của panel không phủ request cập nhật); mọi hành động THẬT hỏi
 * lại ({@see self::guard()}), và Action hỏi `Gate::forUser($actor)` lần nữa. Người được chọn được
 * resolve lại ở mỗi lần dùng ({@see self::selectedUser()}): không còn (đã xoá) hay không được xem thì
 * 404.
 */
class AiConnections extends Page implements HasTable
{
    use InteractsWithTable;
    use ReportsActionFailures;

    protected string $view = 'filament.admin.pages.ai-connections';

    protected static ?string $slug = 'ket-noi-ai';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    /** Nhân sự đang mở phần "Chi tiết" — chỉ nút "Kết nối" của bảng đặt được. */
    #[Locked]
    public ?int $selectedUserId = null;

    /** @var array<string, mixed> trạng thái form hai công tắc */
    public array $switches = [];

    /** @var array<string, mixed> trạng thái form ngày nộp hồ sơ */
    public array $assessment = [];

    /** @var array{user?: int|string|null, tool?: string|null} bộ lọc của khối Nhật ký MCP */
    public array $auditFilters = ['user' => null, 'tool' => null];

    /**
     * Kỳ của khối "Hoạt động của trợ lý AI" (làn ai): 7 hay 30 ngày. `#[Locked]` — chỉ nút chọn kỳ
     * ({@see self::showActivity()}) đổi được, và chỉ sang một giá trị của {@see SummarizeMcpActivity::PERIODS}.
     */
    #[Locked]
    public int $activityDays = SummarizeMcpActivity::DEFAULT_PERIOD;

    /** @var array<int, StaffAiSummary>|null tóm tắt theo lô cho trang bảng đang hiện, nhớ trong MỘT request */
    private ?array $overview = null;

    public static function getNavigationLabel(): string
    {
        return __('ai_connections.admin.navigation_label');
    }

    public function getTitle(): string
    {
        return __('ai_connections.admin.title');
    }

    public static function canAccess(): bool
    {
        return Gate::forUser(Auth::user())->allows(Permission::SettingsManage->value);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /** Xem docblock lớp, mục "Cổng" — 404 ở mount VÀ ở mọi request cập nhật Livewire. */
    public function boot(): void
    {
        abort_unless(static::canAccess(), 404);
    }

    public function mount(): void
    {
        $this->getSchema('switchesForm')?->fill([
            'enabled' => McpSwitches::enabled(),
            'write_enabled' => McpSwitches::writeSwitchOn(),
        ]);

        $this->getSchema('assessmentForm')?->fill([
            'filed_on' => McpSwitches::transferAssessmentFiledOn()?->format('Y-m-d'),
        ]);

        $this->getSchema('auditFiltersForm')?->fill(['user' => null, 'tool' => null]);
    }

    // ---------------------------------------------------------------------------------------------
    // Công tắc và ngày nộp hồ sơ.
    // ---------------------------------------------------------------------------------------------

    public function switchesForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('switches')
            ->components([
                Toggle::make('enabled')
                    ->label(__('ai_connections.admin.switches.enabled'))
                    ->helperText(__('ai_connections.admin.switches.enabled_help')),
                Toggle::make('write_enabled')
                    ->label(__('ai_connections.admin.switches.write_enabled'))
                    ->helperText(__('ai_connections.admin.switches.write_help')),
            ]);
    }

    /**
     * Nút "Lưu công tắc" (làn ai): hộp xác nhận nói đúng hậu quả của điều SẮP đổi
     * ({@see self::switchConsequences()}), rồi gọi {@see self::saveSwitches()} — hành vi lưu giữ nguyên
     * (cùng Action, cùng kiểm tra, cùng audit). Hộp xác nhận chỉ là trình bày: `saveSwitches()` vẫn
     * tự hỏi cổng.
     */
    public function confirmSwitchesAction(): Action
    {
        return Action::make('confirmSwitches')
            ->label(__('ai_connections.admin.switches.save'))
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedSparkles)
            ->modalHeading(__('ai_connections.admin.switches.confirm.heading'))
            ->modalDescription(fn (): HtmlString => new HtmlString(collect($this->switchConsequences())
                ->map(fn (string $key): string => '<span style="display:block;margin-top:0.5rem;">'.e(__('ai_connections.admin.switches.confirm.'.$key)).'</span>')
                ->implode('')))
            ->modalSubmitActionLabel(__('ai_connections.admin.switches.confirm.submit'))
            ->action(function (): void {
                $this->guard();

                $this->saveSwitches();
            });
    }

    /**
     * Khoá câu hậu quả (`ai_connections.admin.switches.confirm.*`) của việc lưu hai công tắc đang chọn
     * trên form, so với giá trị ĐANG LƯU:
     *
     *  - công tắc đọc đổi → `server_on` / `server_off`;
     *  - quyền ghi CÓ TÁC DỤNG (cả hai công tắc bật, {@see McpSwitches::writeEnabled()}) từ không thành
     *    có → `write_on`; từ có thành không trong khi đọc vẫn bật → `write_off` (tắt đọc thì `server_off`
     *    đã nói);
     *  - công tắc ghi đổi khi đọc vẫn tắt (chưa có tác dụng) → `write_without_server` khi bật,
     *    `write_off` khi tắt;
     *  - không gì đổi → `unchanged`.
     *
     * @return list<string>
     */
    private function switchConsequences(): array
    {
        $enabled = (bool) ($this->switches['enabled'] ?? false);
        $write = (bool) ($this->switches['write_enabled'] ?? false);
        $wasEnabled = McpSwitches::enabled();
        $wasWrite = McpSwitches::writeSwitchOn();

        $writesBefore = $wasEnabled && $wasWrite;
        $writesAfter = $enabled && $write;

        $lines = [];

        if ($enabled !== $wasEnabled) {
            $lines[] = $enabled ? 'server_on' : 'server_off';
        }

        if ($writesAfter && ! $writesBefore) {
            $lines[] = 'write_on';
        } elseif ($writesBefore && ! $writesAfter && $enabled) {
            $lines[] = 'write_off';
        } elseif (! $enabled && $write !== $wasWrite) {
            $lines[] = $write ? 'write_without_server' : 'write_off';
        }

        return $lines === [] ? ['unchanged'] : $lines;
    }

    public function assessmentForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('assessment')
            ->components([
                // native(false) + d/m/Y như MatterForm: input date gốc hiện theo locale trình duyệt
                // (mm/dd/yyyy trên en-US). State vẫn là Y-m-d, nên `date_format:Y-m-d` của
                // UpdateAiSettings không đổi (fix round 1, I3).
                DatePicker::make('filed_on')
                    ->label(__('ai_connections.assessment.filed_on'))
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->maxDate(today()),
            ]);
    }

    /** Lưu hai công tắc qua {@see UpdateAiSettings} — Action hỏi quyền, kiểm tra, ghi audit. */
    public function saveSwitches(): void
    {
        $this->guard();

        $changed = $this->saveSettings([
            'enabled' => (bool) ($this->switches['enabled'] ?? false),
            'write_enabled' => (bool) ($this->switches['write_enabled'] ?? false),
        ], ['enabled' => 'switches.enabled', 'write_enabled' => 'switches.write_enabled']);

        $notification = Notification::make()->title(__($changed === [] ? 'ai_connections.admin.switches.unchanged' : 'ai_connections.admin.switches.saved'));
        $changed === [] ? $notification->info() : $notification->success();
        $notification->send();
    }

    /** Ghi (hay xoá) ngày đã nộp hồ sơ đánh giá tác động — chỉ ẩn/hiện dải cảnh báo. */
    public function recordTransferAssessment(): void
    {
        $this->guard();

        $value = $this->assessment['filed_on'] ?? null;

        $this->saveSettings(
            ['transfer_assessment_filed_on' => is_string($value) ? $value : null],
            ['transfer_assessment_filed_on' => 'assessment.filed_on'],
        );

        Notification::make()->title(__('ai_connections.assessment.saved'))->success()->send();
    }

    /**
     * Nút chọn kỳ 7/30 ngày của khối "Hoạt động của trợ lý AI" (làn ai). Giá trị khác hai kỳ đó bị bỏ
     * qua (kỳ giữ nguyên); hỏi lại cổng như mọi hành động thật.
     */
    public function showActivity(int $days): void
    {
        $this->guard();

        if (in_array($days, SummarizeMcpActivity::PERIODS, true)) {
            $this->activityDays = $days;
        }
    }

    /**
     * Dữ liệu của view, tính ở mỗi lần render — hàm đọc là `protected`, không gọi được từ trình duyệt.
     * Làn ai thêm thẻ trạng thái + các bước ({@see SummarizeAiSetup}) và khối hoạt động
     * ({@see SummarizeMcpActivity}); cả hai là Action đọc-chỉ, tự hỏi quyền.
     *
     * @return array{filedOn: ?string, selected: ?User, connections: list<AiConnection>, entries: list<McpAuditEntry>, summary: AiSetupSummary, steps: list<AiSetupStep>, activity: McpActivityReport, mattersUrl: string}
     */
    protected function getViewData(): array
    {
        $selected = $this->selectedUser();
        $summary = app(SummarizeAiSetup::class)->handle($this->actor());

        return [
            'filedOn' => $summary->assessmentFiledOn?->format('d/m/Y'),
            'selected' => $selected,
            'connections' => $selected === null ? [] : app(ListAiConnections::class)->forUser($this->actor(), $selected),
            'entries' => $this->auditEntries(),
            'summary' => $summary,
            'steps' => AiSetupStep::cases(),
            'activity' => app(SummarizeMcpActivity::class)->handle($this->actor(), $this->activityDays),
            'mattersUrl' => MatterResource::getUrl('index'),
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // Bảng nhân sự.
    // ---------------------------------------------------------------------------------------------

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => User::query()->orderBy('name'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('ai_connections.admin.columns.name'))
                    ->description(fn (User $record): ?string => $record->is_active ? $record->email : __('ai_connections.admin.inactive'))
                    ->searchable(),
                TextColumn::make('ai_access')
                    ->label(__('ai_connections.admin.columns.ai_access'))
                    ->state(fn (User $record): string => $record->ai_access->label())
                    ->badge()
                    ->color(fn (User $record): string => match ($record->ai_access) {
                        AiAccessMode::Off => 'gray',
                        AiAccessMode::Read => 'info',
                        AiAccessMode::ReadWrite => 'warning',
                    }),
                // Làn ai: huy hiệu màu — xanh khi đã cam kết đúng phiên bản, vàng khi chỉ có bản cũ, xám khi chưa.
                TextColumn::make('acknowledged')
                    ->label(__('ai_connections.admin.columns.acknowledged'))
                    ->state(fn (User $record): string => $this->acknowledgementText($this->summaryFor($record)))
                    ->badge()
                    ->color(fn (User $record): string => match (true) {
                        $this->summaryFor($record)->acknowledgedCurrent => 'success',
                        $this->summaryFor($record)->acknowledgedAt !== null => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('connections')
                    ->label(__('ai_connections.admin.columns.connections'))
                    ->state(fn (User $record): int => $this->summaryFor($record)->connections)
                    ->badge()
                    ->color(fn (User $record): string => $this->summaryFor($record)->connections > 0 ? 'success' : 'gray'),
                // Giá trị giữ ngày giờ đầy đủ (bộ lọc, test của M11); chữ hiện là "2 giờ trước", ngày giờ ở tooltip.
                TextColumn::make('last_used_at')
                    ->label(__('ai_connections.admin.columns.last_used_at'))
                    ->state(fn (User $record): string => $this->summaryFor($record)->lastUsedAt?->format('d/m/Y H:i')
                        ?? __('ai_connections.admin.never_used'))
                    ->formatStateUsing(fn (User $record): string => $this->summaryFor($record)->lastUsedAt?->diffForHumans()
                        ?? __('ai_connections.admin.never_used'))
                    ->tooltip(fn (User $record): ?string => $this->summaryFor($record)->lastUsedAt?->format('d/m/Y H:i')),
            ])
            ->filters([
                // Làn ai: bộ lọc nhanh — cùng MỘT định nghĩa với con số của thẻ trạng thái.
                Filter::make('enabled')
                    ->label(__('ai_connections.staff.filters.enabled'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => SummarizeAiSetup::whereEnabled($query)),
                Filter::make('not_acknowledged')
                    ->label(__('ai_connections.staff.filters.not_acknowledged'))
                    ->toggle()
                    // n6: người ĐƯỢC BẬT chưa cam kết — đúng m − n của thẻ "Đã cam kết chính sách n/m".
                    ->query(fn (Builder $query): Builder => SummarizeAiSetup::whereNotAcknowledgedCurrent(SummarizeAiSetup::whereEnabled($query))),
                Filter::make('connected')
                    ->label(__('ai_connections.staff.filters.connected'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => ListAiConnections::whereConnected($query)),
            ])
            ->filtersLayout(FiltersLayout::AboveContent)
            ->toolbarActions([
                $this->setAiAccessBulkAction(),
            ])
            ->recordActions([
                $this->setAiAccessAction(),
                Action::make('showConnections')
                    ->label(__('ai_connections.admin.actions.show_connections'))
                    ->icon(Heroicon::OutlinedLink)
                    ->color('gray')
                    ->action(function (User $record): void {
                        $this->guard();

                        $this->selectedUserId = (int) $record->getKey();
                    }),
            ])
            ->paginated([25, 50, 100]);
    }

    /**
     * "Đổi chế độ" — {@see SetUserAiAccess}. Lựa chọn bật chỉ có cho người giữ được quyền AI
     * ({@see McpAccess::canHold()}) VÀ đang hoạt động; người khác chỉ có "Tắt". Select của Filament
     * từ chối một giá trị ngoài danh sách, và Action từ chối lần nữa nếu bị ép.
     */
    private function setAiAccessAction(): Action
    {
        return Action::make('setAiAccess')
            ->label(__('ai_connections.admin.actions.set_ai_access'))
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->modalHeading(fn (User $record): string => __('ai_connections.admin.actions.set_ai_access_heading', ['name' => $record->name]))
            ->authorize(fn (User $record): bool => Gate::forUser(Auth::user())->allows('setAiAccess', $record))
            ->fillForm(fn (User $record): array => ['ai_access' => $record->ai_access->value])
            ->schema([
                Select::make('ai_access')
                    ->label(__('ai_connections.admin.columns.ai_access'))
                    ->options(fn (User $record): array => self::modeOptions($record))
                    ->helperText(fn (User $record): string => self::canBeEnabled($record)
                        ? __('ai_connections.admin.actions.set_ai_access_hint')
                        : __('ai_connections.admin.actions.set_ai_access_hint_locked'))
                    ->selectablePlaceholder(false)
                    ->required(),
            ])
            ->successNotificationTitle(__('ai_connections.admin.notifications.ai_access_saved'))
            ->action(function (Action $action, User $record, array $data): void {
                $this->guard();

                $this->runAction($action, fn () => app(SetUserAiAccess::class)->handle(
                    $record,
                    AiAccessMode::from((string) $data['ai_access']),
                    $this->actor(),
                ));

                $this->overview = null;
            });
    }

    /**
     * "Đổi chế độ" hàng loạt (làn ai) — {@see SetAiAccessForMany}: mỗi người đã chọn MỘT lần gọi
     * {@see SetUserAiAccess} (cùng kiểm quyền, kiểm điều kiện, audit, thu hồi khi về "Tắt"); người không
     * bật được bị bỏ qua và được nêu tên. Ô chọn có đủ ba chế độ: lọc theo từng người là việc của Action.
     */
    private function setAiAccessBulkAction(): BulkAction
    {
        return BulkAction::make('setAiAccessBulk')
            ->label(__('ai_connections.staff.bulk.label'))
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->modalHeading(__('ai_connections.staff.bulk.heading'))
            ->authorize(fn (): bool => Gate::forUser(Auth::user())->allows('viewAny', User::class))
            ->schema([
                Select::make('ai_access')
                    ->label(__('ai_connections.admin.columns.ai_access'))
                    ->options(collect(AiAccessMode::cases())->mapWithKeys(fn (AiAccessMode $mode): array => [$mode->value => $mode->label()])->all())
                    ->helperText(__('ai_connections.staff.bulk.hint'))
                    ->selectablePlaceholder(false)
                    ->required(),
            ])
            ->deselectRecordsAfterCompletion()
            ->action(function (BulkAction $action, Collection $records, array $data): void {
                $this->guard();

                $result = ['applied' => 0, 'skipped' => []];

                $this->runAction($action, function () use ($records, $data, &$result): void {
                    $result = app(SetAiAccessForMany::class)->handle(
                        $records,
                        AiAccessMode::from((string) $data['ai_access']),
                        $this->actor(),
                    );
                });

                $this->overview = null;

                // Không một dòng xanh "Đã đặt chế độ cho 0 người." cạnh cảnh báo vàng khi mọi người
                // đều bị bỏ qua — luật của ReportsActionFailures::failWithNotification (fix round 1, I2).
                // `applied` đếm cả người đã đúng chế độ chọn ({@see SetAiAccessForMany}): với họ câu
                // "đã đặt" vẫn đúng.
                if ($result['applied'] > 0) {
                    Notification::make()
                        ->title(__('ai_connections.staff.bulk.done', ['count' => $result['applied']]))
                        ->success()
                        ->send();
                }

                if ($result['skipped'] !== []) {
                    Notification::make()
                        ->title(__('ai_connections.staff.bulk.skipped', [
                            'count' => count($result['skipped']),
                            'names' => implode(', ', $result['skipped']),
                        ]))
                        ->warning()
                        ->send();
                }
            });
    }

    /** @return array<string, string> giá trị → nhãn của các chế độ đặt được cho người này */
    private static function modeOptions(User $user): array
    {
        $modes = self::canBeEnabled($user) ? AiAccessMode::cases() : [AiAccessMode::Off];

        return collect($modes)->mapWithKeys(fn (AiAccessMode $mode): array => [$mode->value => $mode->label()])->all();
    }

    /** Cùng hai điều kiện mà {@see SetUserAiAccess} kiểm cho chiều bật. */
    private static function canBeEnabled(User $user): bool
    {
        return $user->is_active && McpAccess::canHold($user);
    }

    private function summaryFor(User $record): StaffAiSummary
    {
        if ($this->overview === null) {
            $records = $this->getTableRecords();

            $this->overview = app(ListAiConnections::class)->overview(
                $this->actor(),
                $records instanceof Paginator ? $records->items() : $records,
            );
        }

        return $this->overview[$record->getKey()]
            ?? app(ListAiConnections::class)->overview($this->actor(), [$record])[$record->getKey()];
    }

    private function acknowledgementText(StaffAiSummary $summary): string
    {
        if ($summary->acknowledgedAt === null) {
            return __('ai_connections.admin.not_acknowledged');
        }

        return __($summary->acknowledgedCurrent ? 'ai_connections.admin.acknowledged_on' : 'ai_connections.admin.acknowledged_outdated', [
            'date' => $summary->acknowledgedAt->format('d/m/Y'),
            'version' => (string) $summary->acknowledgedVersion,
        ]);
    }

    // ---------------------------------------------------------------------------------------------
    // Chi tiết một người: kết nối, "Thu hồi", "Thu hồi tất cả".
    // ---------------------------------------------------------------------------------------------

    /**
     * Nhân sự đang mở phần chi tiết, resolve LẠI ở mỗi lần dùng: không còn (đã xoá) hay người xem
     * không được xem kết nối của họ thì 404. `null` khi chưa chọn ai.
     */
    protected function selectedUser(): ?User
    {
        if ($this->selectedUserId === null) {
            return null;
        }

        $user = User::query()->find($this->selectedUserId);

        abort_unless($user instanceof User && Gate::forUser(Auth::user())->allows('viewAiConnections', $user), 404);

        return $user;
    }

    public function closeConnections(): void
    {
        $this->guard();

        $this->selectedUserId = null;
    }

    /** "Thu hồi" một dòng — đối số `client` là `oauth_clients.id` của dòng đó. */
    public function revokeConnectionAction(): Action
    {
        return Action::make('revokeConnection')
            ->label(__('ai_connections.actions.revoke'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading(__('ai_connections.actions.revoke_heading'))
            ->modalDescription(__('ai_connections.actions.revoke_description'))
            ->visible(fn (): bool => $this->selectedUserId !== null)
            ->action(function (Action $action, array $arguments): void {
                $this->guard();

                $clientId = $arguments['client'] ?? null;

                // Một dòng luôn mang id client; thiếu id KHÔNG được rơi về "thu hồi tất cả".
                abort_unless(is_string($clientId) && $clientId !== '', 404);

                $this->disconnect($action, $clientId);
            });
    }

    /** "Thu hồi tất cả" của người đang xem. Chế độ giữ nguyên (ngắt kết nối không phải rút quyền). */
    public function revokeAllConnectionsAction(): Action
    {
        return Action::make('revokeAllConnections')
            ->label(__('ai_connections.actions.revoke_all'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('ai_connections.actions.revoke_all_heading'))
            ->modalDescription(__('ai_connections.actions.revoke_all_description'))
            ->visible(fn (): bool => $this->selectedUserId !== null)
            ->action(function (Action $action): void {
                $this->guard();

                $this->disconnect($action, null);
            });
    }

    private function disconnect(Action $action, ?string $clientId): void
    {
        $target = $this->selectedUser();

        abort_unless($target instanceof User, 404);

        $revoked = 0;

        $this->runAction($action, function () use ($target, $clientId, &$revoked): void {
            $revoked = app(DisconnectAiConnections::class)->handle($this->actor(), $target, $clientId);
        });

        Notification::make()
            ->title(__($revoked > 0 ? 'ai_connections.actions.revoked' : 'ai_connections.actions.nothing_revoked'))
            ->success()
            ->send();

        $this->overview = null;
    }

    // ---------------------------------------------------------------------------------------------
    // Nhật ký MCP.
    // ---------------------------------------------------------------------------------------------

    public function auditFiltersForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('auditFilters')
            ->components([
                Grid::make(2)->schema([
                    Select::make('user')
                        ->label(__('ai_connections.admin.audit.filter_user'))
                        ->placeholder(__('ai_connections.admin.audit.filter_all'))
                        // Làn fb, mục A6: kể cả nhân sự đã nghỉ việc — nhật ký của họ vẫn phải lọc được.
                        ->options(fn (): array => ActivityPeople::staffOptions())
                        ->searchable()
                        ->live(),
                    Select::make('tool')
                        ->label(__('ai_connections.admin.audit.filter_tool'))
                        ->placeholder(__('ai_connections.admin.audit.filter_all'))
                        ->options(fn (): array => collect(app(ListMcpAuditEntries::class)->toolNames($this->actor()))
                            ->mapWithKeys(fn (string $name): array => [$name => $name])
                            ->all())
                        ->live(),
                ]),
            ]);
    }

    /** @return list<McpAuditEntry> */
    protected function auditEntries(): array
    {
        $user = $this->auditFilters['user'] ?? null;
        $tool = $this->auditFilters['tool'] ?? null;

        return app(ListMcpAuditEntries::class)->handle(
            $this->actor(),
            is_numeric($user) ? (int) $user : null,
            is_string($tool) && $tool !== '' ? $tool : null,
        );
    }

    // ---------------------------------------------------------------------------------------------

    /** Hỏi lại cổng của trang ở đầu MỌI hành động thật, không tin vòng đời đã chạy. */
    private function guard(): void
    {
        abort_unless(static::canAccess(), 404);
    }

    private function actor(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 404);

        return $user;
    }

    /**
     * Gọi {@see UpdateAiSettings}, đổi khoá lỗi của Action sang đường dẫn trạng thái của form để lỗi
     * hiện đúng dưới ô của nó.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $errorPaths  trường của Action → đường dẫn trạng thái
     * @return list<string>
     */
    private function saveSettings(array $input, array $errorPaths): array
    {
        try {
            return app(UpdateAiSettings::class)->handle($this->actor(), $input);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => [($errorPaths[$field] ?? $field) => $messages])
                ->all());
        }
    }
}
