<?php

namespace App\Filament\Admin\Pages;

use App\Actions\Mcp\AiConnection;
use App\Actions\Mcp\DisconnectAiConnections;
use App\Actions\Mcp\ListAiConnections;
use App\Actions\Mcp\ListMcpAuditEntries;
use App\Actions\Mcp\McpAuditEntry;
use App\Actions\Mcp\SetUserAiAccess;
use App\Actions\Mcp\StaffAiSummary;
use App\Actions\Mcp\UpdateAiSettings;
use App\Enums\AiAccessMode;
use App\Enums\Permission;
use App\Filament\Admin\Concerns\ReportsActionFailures;
use App\Models\User;
use App\Support\Mcp\McpAccess;
use App\Support\Mcp\McpSwitches;
use BackedEnum;
use Filament\Actions\Action;
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
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
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
                Toggle::make('enabled')->label(__('ai_connections.admin.switches.enabled')),
                Toggle::make('write_enabled')
                    ->label(__('ai_connections.admin.switches.write_enabled'))
                    ->helperText(__('ai_connections.admin.switches.write_needs_enabled')),
            ]);
    }

    public function assessmentForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('assessment')
            ->components([
                DatePicker::make('filed_on')
                    ->label(__('ai_connections.assessment.filed_on'))
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
     * Dữ liệu của view, tính ở mỗi lần render — hàm đọc là `protected`, không gọi được từ trình duyệt.
     *
     * @return array{filedOn: ?string, selected: ?User, connections: list<AiConnection>, entries: list<McpAuditEntry>}
     */
    protected function getViewData(): array
    {
        $selected = $this->selectedUser();

        return [
            'filedOn' => McpSwitches::transferAssessmentFiledOn()?->format('d/m/Y'),
            'selected' => $selected,
            'connections' => $selected === null ? [] : app(ListAiConnections::class)->forUser($this->actor(), $selected),
            'entries' => $this->auditEntries(),
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
                TextColumn::make('acknowledged')
                    ->label(__('ai_connections.admin.columns.acknowledged'))
                    ->state(fn (User $record): string => $this->acknowledgementText($this->summaryFor($record))),
                TextColumn::make('connections')
                    ->label(__('ai_connections.admin.columns.connections'))
                    ->state(fn (User $record): int => $this->summaryFor($record)->connections),
                TextColumn::make('last_used_at')
                    ->label(__('ai_connections.admin.columns.last_used_at'))
                    ->state(fn (User $record): string => $this->summaryFor($record)->lastUsedAt?->format('d/m/Y H:i')
                        ?? __('ai_connections.admin.never_used')),
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
                        ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
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
