<?php

namespace App\Filament\Admin\Pages;

use App\Actions\Billing\ActivateContract;
use App\Actions\Billing\DeleteDraftContract;
use App\Actions\Billing\DraftContract;
use App\Actions\Billing\EnterSignedContract;
use App\Actions\Billing\UpdateDraftContract;
use App\Enums\ContractStatus;
use App\Enums\Permission;
use App\Filament\Admin\Concerns\ReportsActionFailures;
use App\Filament\Admin\Support\ContractScheduleForm;
use App\Filament\Admin\Widgets\ContractsToEnterWidget;
use App\Models\Contract;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\User;
use App\Policies\ContractPolicy;
use App\Support\Billing\AccountantContractRow;
use App\Support\Billing\BillingSummary;
use App\Support\Billing\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;

/**
 * Trang "Hợp đồng" (làn kt, 2026-10-15; SPEC §5 "Bổ sung 2026-10-15"). Chủ văn phòng đăng nhập kế
 * toán, thấy Doanh thu/Công nợ nhưng không có chỗ nào thêm hợp đồng và số tiền của hồ sơ — vì hợp đồng
 * chỉ soạn được ở tab "Hợp đồng và thanh toán" của trang vụ việc, mà kế toán không mở được trang đó
 * (`matter.view`). Chủ văn phòng chọn "Cho kế toán nhập hợp đồng": kế toán nhập hợp đồng giấy đã ký —
 * tổng giá trị, thuế suất, các đợt thu — cho một hồ sơ CÓ SẴN, vẫn không mở nội dung hồ sơ. Hồ sơ vẫn
 * do luật sư/quản lý mở.
 *
 * # Cổng của cả TRANG — {@see self::canBeOpenedBy()}
 *
 * `billing.view` **và** (`contract.enter` **hoặc** `contract.manage`), hỏi qua `Gate`: kế toán, quản
 * trị viên, trưởng phòng, luật sư (tầm nhìn của họ: vụ trong đội). Trợ lý và nhân viên kinh doanh nhận
 * 404 ở mount VÀ ở mọi request cập nhật Livewire ({@see self::boot()}; SPEC §10.10).
 *
 * # Tập dòng — đúng tầm nhìn của trang Công nợ
 *
 * `Matter::listableBy($user)` (vụ chưa xoá mềm — vụ đã huỷ không có ở đây), cùng định nghĩa "ai thấy
 * tiền của vụ nào" của `ChecksBillingAccess`: kế toán và trưởng phòng không thấy vụ `restricted`, quản
 * trị viên thấy mọi vụ, luật sư thấy vụ trong đội. Mặc định lọc "Chưa có hợp đồng" + "Nháp" — đúng
 * những hồ sơ còn việc phải nhập.
 *
 * # Dữ liệu chỉ đi qua {@see AccountantContractRow}
 *
 * Mọi cột đọc qua DTO đó: mã hồ sơ, loại vụ việc, tên khách hàng, luật sư phụ trách, số và trạng thái
 * hợp đồng, tổng giá trị, đã thu, còn phải thu. Không tiêu đề vụ, không ghi chú, không tài liệu, không
 * bên đối lập. Mã hồ sơ là một CHUỖI, không phải liên kết (kế toán không mở được trang vụ). Khoá của
 * dòng là id vụ việc (Filament cần nó để mở hộp thoại trên đúng dòng) — một id không mở được gì: trang
 * vụ việc vẫn 404 với kế toán.
 *
 * # Hành động trên dòng — đúng các Action và đúng form của tab tiền
 *
 * **Nhập hợp đồng** (vụ chưa có hợp đồng, hoặc hợp đồng hiện hành đã huỷ): form
 * {@see ContractScheduleForm} — cùng một bản với tab tiền, không chép — cộng công tắc "Khách đã ký —
 * kích hoạt luôn" (mặc định TẮT) và ngày ký. Tắt: {@see DraftContract} (lưu nháp). Bật:
 * {@see EnterSignedContract} — soạn và kích hoạt trong MỘT transaction, lệch tổng hay ngày ký ở tương
 * lai thì không lưu gì, hộp thoại ở lại với dữ liệu đã nhập. Mặc định tắt có chủ đích: hợp đồng đã kích
 * hoạt chỉ người có `contract.manage` sửa được (phụ lục, huỷ), nên kích hoạt là một bước nhìn lại rõ
 * ràng. **Sửa bản nháp** ({@see UpdateDraftContract}), **Xoá bản nháp** ({@see DeleteDraftContract}),
 * **Kích hoạt (đã ký)** ({@see ActivateContract}) chỉ hiện khi hợp đồng hiện hành còn nháp. **Ghi khoản
 * thu** (hợp đồng đang hiệu lực) là một liên kết sang trang Công nợ, lọc sẵn mã hồ sơ — chỉ hiện khi
 * người xem mở được trang đó.
 *
 * Mỗi nút hỏi `Gate` CÓ NGỮ CẢNH trên đúng dòng (`create` với vụ; `updateDraft`, `delete`, `activate`
 * với hợp đồng — {@see ContractPolicy}); Action hỏi lại trên hàng đã khoá. Lỗi của Action ra qua
 * {@see ReportsActionFailures}. Luật sư phụ trách nhận chuông khi người khác nhập/kích hoạt (trong
 * Action, sau commit).
 *
 * # Hai con số mỗi dòng — một câu SELECT
 *
 * "Còn phải thu" là {@see BillingSummary::outstandingPerMatterExpression()} (hợp đồng đang hiệu lực,
 * như cột cùng tên của danh sách vụ việc); "Đã thu" là {@see self::collectedOnCurrentContractExpression()}
 * (khoản thu chưa huỷ trên hợp đồng HIỆN HÀNH — bản mới nhất, `Matter::contract()`). Cả hai là biểu
 * thức SQL tương quan trong CÙNG câu SELECT của bảng, không phải một SUM() mỗi dòng; loại vụ việc,
 * khách hàng, luật sư phụ trách và hợp đồng hiện hành nạp kèm ({@see self::rowsQuery()}).
 */
class ContractEntry extends Page implements HasTable
{
    use InteractsWithTable;
    use ReportsActionFailures;

    /** Giá trị của bộ lọc trạng thái cho "vụ chưa có hợp đồng nào". */
    public const STATUS_NONE = 'none';

    protected string $view = 'filament.admin.pages.contract-entry';

    protected static ?string $slug = 'contract-entry';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    /**
     * Bộ lọc của bảng đọc được từ query string `filters` — hai ô của {@see ContractsToEnterWidget} trên
     * trang chủ mở trang này lọc sẵn "Chưa có hợp đồng" hay "Nháp". Cùng khoá `filters` với
     * `ListRecords` của Filament. Không có trong URL thì bộ lọc mặc định ("Chưa có hợp đồng" + "Nháp").
     *
     * @var array<string, mixed>|null
     */
    #[Url(as: 'filters')]
    public ?array $tableFilters = null;

    public static function getNavigationLabel(): string
    {
        return __('contract_entry.navigation_label');
    }

    public function getTitle(): string
    {
        return __('contract_entry.title');
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && static::canBeOpenedBy($user);
    }

    /** Cổng của cả trang về MỘT người — xem docblock lớp. `canAccess()` gọi thẳng hàm này. */
    public static function canBeOpenedBy(User $user): bool
    {
        $gate = Gate::forUser($user);

        return $gate->allows(Permission::BillingView->value)
            && ($gate->allows(Permission::ContractEnter->value) || $gate->allows(Permission::ContractManage->value));
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /** 404 ở mount VÀ ở mọi request cập nhật Livewire (hook `boot` chạy trước cả hai). */
    public function boot(): void
    {
        abort_unless(static::canAccess(), 404);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->rowsQuery())
            ->description(__('contract_entry.scope_note'))
            ->emptyStateHeading(__('contract_entry.empty_heading'))
            ->columns([
                TextColumn::make('matter_code')
                    ->label(__('contract_entry.columns.matter_code'))
                    ->state(fn (Matter $record): string => static::rowFor($record)->matterCode)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('matters.code', 'like', '%'.$search.'%')),
                TextColumn::make('matter_type_name')
                    ->label(__('contract_entry.columns.matter_type'))
                    ->state(fn (Matter $record): string => static::rowFor($record)->matterTypeName),
                TextColumn::make('client_name')
                    ->label(__('contract_entry.columns.client'))
                    ->state(fn (Matter $record): string => static::rowFor($record)->clientName)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'client',
                        fn (Builder $client) => $client->withTrashed()->where('name', 'like', '%'.$search.'%'),
                    )),
                TextColumn::make('lead_lawyer_name')
                    ->label(__('contract_entry.columns.lead_lawyer'))
                    ->state(fn (Matter $record): string => static::rowFor($record)->leadLawyerName),
                TextColumn::make('contract_code')
                    ->label(__('contract_entry.columns.contract_code'))
                    ->state(fn (Matter $record): ?string => static::rowFor($record)->contractCode)
                    ->placeholder('—'),
                TextColumn::make('contract_status')
                    ->label(__('contract_entry.columns.contract_status'))
                    ->badge()
                    ->state(fn (Matter $record): string => static::rowFor($record)->contractStatus?->label() ?? __('contract_entry.status.none'))
                    ->color(fn (Matter $record): string => match (static::rowFor($record)->contractStatus) {
                        null => 'warning',
                        ContractStatus::Draft => 'info',
                        ContractStatus::Active => 'success',
                        ContractStatus::Completed, ContractStatus::Cancelled => 'gray',
                    }),
                TextColumn::make('total_amount')
                    ->label(__('contract_entry.columns.total_amount'))
                    ->state(fn (Matter $record): ?string => static::rowFor($record)->totalAmount === null ? null : Money::format(static::rowFor($record)->totalAmount))
                    ->placeholder('—'),
                TextColumn::make('collected')
                    ->label(__('contract_entry.columns.collected'))
                    ->state(fn (Matter $record): string => Money::format(static::rowFor($record)->collected)),
                TextColumn::make('outstanding')
                    ->label(__('contract_entry.columns.outstanding'))
                    ->state(fn (Matter $record): string => Money::format(static::rowFor($record)->outstanding)),
            ])
            ->filters([
                SelectFilter::make('contract_status')
                    ->label(__('contract_entry.filters.contract_status'))
                    ->multiple()
                    ->options(static::statusOptions())
                    ->default([self::STATUS_NONE, ContractStatus::Draft->value])
                    ->query(fn (Builder $query, array $data): Builder => static::whereContractStatus($query, (array) ($data['values'] ?? []))),
                SelectFilter::make('matter_type_id')
                    ->label(__('contract_entry.filters.matter_type'))
                    ->options(fn (): array => $this->matterTypeOptions())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, $value): Builder => $query->where('matters.matter_type_id', $value),
                    )),
                SelectFilter::make('lead_lawyer_id')
                    ->label(__('contract_entry.filters.lead_lawyer'))
                    ->options(fn (): array => $this->leadLawyerOptions())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, $value): Builder => $query->where('matters.lead_lawyer_id', $value),
                    )),
            ])
            ->recordActions([
                $this->enterContractAction(),
                $this->updateDraftAction(),
                $this->activateAction(),
                $this->deleteDraftAction(),
                $this->recordPaymentLink(),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('matters.id'));
    }

    /**
     * Tập dòng — xem docblock lớp. `matterType`, `client`, `leadLawyer` nạp kèm `withTrashed()` để DTO
     * không truy vấn lại; `contract` (bản hiện hành) nạp sẵn cho cột trạng thái và cho cổng của các nút.
     */
    private function rowsQuery(): Builder
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(404);
        }

        return Matter::query()
            ->listableBy($user)
            ->select('matters.*')
            ->selectRaw(BillingSummary::outstandingPerMatterExpression().' as outstanding_amount')
            ->selectRaw(static::collectedOnCurrentContractExpression().' as collected_amount')
            ->with([
                'matterType' => fn ($query) => $query->withTrashed(),
                'client' => fn ($query) => $query->withTrashed(),
                'leadLawyer' => fn ($query) => $query->withTrashed(),
                'contract',
            ]);
    }

    /**
     * Biểu thức SQL tương quan `matters.id`: tổng khoản thu CHƯA HUỶ trên hợp đồng HIỆN HÀNH của vụ
     * (`id` lớn nhất — cùng định nghĩa với `Matter::contract()`, `latestOfMany()`). Vụ chưa có hợp đồng: 0.
     */
    public static function collectedOnCurrentContractExpression(): string
    {
        return 'coalesce((select sum(p.amount) from payments p '
            .'inner join instalments i on i.id = p.instalment_id '
            .'where p.voided_at is null '
            .'and i.contract_id = (select max(c.id) from contracts c where c.matter_id = matters.id)), 0)';
    }

    private static function rowFor(Matter $record): AccountantContractRow
    {
        return AccountantContractRow::fromMatter(
            $record,
            (int) $record->getAttribute('collected_amount'),
            (int) $record->getAttribute('outstanding_amount'),
        );
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        return [
            self::STATUS_NONE => __('contract_entry.status.none'),
            ...collect(ContractStatus::cases())
                ->mapWithKeys(fn (ContractStatus $status): array => [$status->value => $status->label()])
                ->all(),
        ];
    }

    /**
     * Lọc theo trạng thái của hợp đồng HIỆN HÀNH (`Matter::contract()`, bản mới nhất). Không chọn gì thì
     * không lọc. "Chưa có hợp đồng" là `whereDoesntHave('contracts')` — vụ chưa từng có hợp đồng nào.
     *
     * @param  list<string>  $values
     */
    private static function whereContractStatus(Builder $query, array $values): Builder
    {
        if ($values === []) {
            return $query;
        }

        $statuses = array_values(array_filter($values, fn (string $value): bool => ContractStatus::tryFrom($value) !== null));

        return $query->where(function (Builder $inner) use ($values, $statuses): void {
            if (in_array(self::STATUS_NONE, $values, true)) {
                $inner->orWhereDoesntHave('contracts');
            }

            if ($statuses !== []) {
                $inner->orWhereHas('contract', fn (Builder $contract) => $contract->whereIn('status', $statuses));
            }
        });
    }

    /**
     * Loại vụ việc có ít nhất một vụ trong tập dòng của CHÍNH người đang xem — không liệt kê rộng hơn tập
     * đó (cùng thành ngữ `Receivables::clientOptions()`).
     *
     * @return array<int, string>
     */
    private function matterTypeOptions(): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return [];
        }

        return MatterType::query()
            ->withTrashed()
            ->whereHas('matters', fn (Builder $matter) => $matter->listableBy($user))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** @return array<int, string> luật sư phụ trách của ít nhất một vụ trong tập dòng của người đang xem */
    private function leadLawyerOptions(): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return [];
        }

        return User::query()
            ->withTrashed()
            ->whereIn('id', Matter::query()->listableBy($user)->select('lead_lawyer_id'))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Hai ô thêm vào form của tab tiền cho đường "Nhập hợp đồng": công tắc kích hoạt luôn (mặc định tắt
     * — docblock lớp) và ngày ký (chỉ hiện, chỉ bắt buộc khi bật).
     *
     * @return array<int, mixed>
     */
    private static function signingFields(): array
    {
        return [
            Toggle::make('activate_now')
                ->label(__('contract_entry.actions.save_and_activate'))
                ->helperText(__('contract_entry.actions.save_and_activate_help'))
                ->default(false)
                ->live(),
            DatePicker::make('signed_at')
                ->label(__('billing.tab.fields.signed_at'))
                ->default(today()->toDateString())
                ->native(false)
                ->visible(fn (Get $get): bool => (bool) $get('activate_now'))
                ->required(fn (Get $get): bool => (bool) $get('activate_now')),
        ];
    }

    private function enterContractAction(): Action
    {
        return Action::make('enterContract')
            ->label(__('contract_entry.actions.enter'))
            ->icon(Heroicon::OutlinedDocumentPlus)
            ->color('primary')
            ->modalHeading(__('contract_entry.actions.enter_heading'))
            ->modalDescription(__('contract_entry.actions.enter_description'))
            ->modalWidth('4xl')
            ->visible(fn (Matter $record): bool => $record->contract === null || $record->contract->status === ContractStatus::Cancelled)
            ->authorize(fn (Matter $record): bool => Gate::allows('create', [Contract::class, $record]))
            ->schema(fn (Matter $record): array => [...ContractScheduleForm::fields($record), ...static::signingFields()])
            ->successNotificationTitle(fn (array $data): string => ($data['activate_now'] ?? false)
                ? __('contract_entry.actions.enter_activated_success')
                : __('contract_entry.actions.enter_success'))
            ->action(fn (Action $action, Matter $record, array $data) => $this->runAction(
                $action,
                function () use ($record, $data): Contract {
                    [$attributes, $instalments] = ContractScheduleForm::actionArguments($data);

                    return ($data['activate_now'] ?? false)
                        ? app(EnterSignedContract::class)->handle(Auth::user(), $record, $attributes, $instalments, $data['signed_at'] ?? '')
                        : app(DraftContract::class)->handle(Auth::user(), $record, $attributes, $instalments);
                },
            ));
    }

    private function updateDraftAction(): Action
    {
        return Action::make('updateDraft')
            ->label(__('contract_entry.actions.update_draft'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->modalHeading(__('billing.tab.actions.update_draft_heading'))
            ->modalWidth('4xl')
            ->visible(fn (Matter $record): bool => $record->contract?->status === ContractStatus::Draft)
            ->authorize(fn (Matter $record): bool => $record->contract !== null && Gate::allows('updateDraft', $record->contract))
            ->schema(fn (Matter $record): array => ContractScheduleForm::fields($record))
            ->fillForm(fn (Matter $record): array => $record->contract === null ? [] : ContractScheduleForm::formState($record->contract))
            ->successNotificationTitle(__('billing.tab.actions.update_draft_success'))
            ->action(fn (Action $action, Matter $record, array $data) => $this->runAction(
                $action,
                function () use ($record, $data): Contract {
                    [$attributes, $instalments] = ContractScheduleForm::actionArguments($data);

                    return app(UpdateDraftContract::class)->handle(Auth::user(), $record->contract, $attributes, $instalments);
                },
            ));
    }

    private function activateAction(): Action
    {
        return Action::make('activate')
            ->label(__('contract_entry.actions.activate'))
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->modalHeading(__('billing.tab.actions.activate_heading'))
            ->visible(fn (Matter $record): bool => $record->contract?->status === ContractStatus::Draft)
            ->authorize(fn (Matter $record): bool => $record->contract !== null && Gate::allows('activate', $record->contract))
            ->schema([
                DatePicker::make('signed_at')
                    ->label(__('billing.tab.fields.signed_at'))
                    ->default(today()->toDateString())
                    ->native(false)
                    ->required(),
            ])
            ->successNotificationTitle(__('billing.tab.actions.activate_success'))
            ->action(fn (Action $action, Matter $record, array $data) => $this->runAction(
                $action,
                fn () => app(ActivateContract::class)->handle(Auth::user(), $record->contract, $data['signed_at'] ?? ''),
            ));
    }

    private function deleteDraftAction(): Action
    {
        return Action::make('deleteDraft')
            ->label(__('contract_entry.actions.delete_draft'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('billing.tab.actions.delete_draft_heading'))
            ->modalDescription(__('billing.tab.actions.delete_draft_description'))
            ->visible(fn (Matter $record): bool => $record->contract?->status === ContractStatus::Draft)
            ->authorize(fn (Matter $record): bool => $record->contract !== null && Gate::allows('delete', $record->contract))
            ->successNotificationTitle(__('billing.tab.actions.delete_draft_success'))
            ->action(fn (Action $action, Matter $record) => $this->runAction(
                $action,
                fn () => app(DeleteDraftContract::class)->handle(Auth::user(), $record->contract),
            ));
    }

    /**
     * "Ghi khoản thu" — liên kết sang trang Công nợ lọc sẵn mã hồ sơ (bộ lọc `matter_code` của
     * {@see Receivables}), không phải một form thứ hai: tiền về chỉ ghi ở MỘT chỗ.
     */
    private function recordPaymentLink(): Action
    {
        return Action::make('recordPayment')
            ->label(__('contract_entry.actions.record_payment'))
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('gray')
            ->visible(fn (Matter $record): bool => $record->contract?->status === ContractStatus::Active && Receivables::canAccess())
            ->url(fn (Matter $record): string => Receivables::getUrl(['filters' => ['matter_code' => ['code' => $record->code]]]));
    }
}
