<?php

namespace App\Filament\Admin\Pages;

use App\Actions\Billing\ActivateContract;
use App\Actions\Billing\DeleteDraftContract;
use App\Actions\Billing\DraftContract;
use App\Actions\Billing\EnterSignedContract;
use App\Actions\Billing\RevertContractToDraft;
use App\Actions\Billing\UpdateDraftContract;
use App\Enums\ContractStatus;
use App\Enums\NavigationGroup;
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
use App\Support\Search\LetterD;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
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
use UnitEnum;

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
 * tiền của vụ nào" của `ChecksBillingAccess`: quản trị viên thấy mọi vụ, luật sư thấy vụ trong đội; vụ
 * `restricted` chỉ hiện với quản trị viên và luật sư phụ trách vụ đó (còn `matter.view`) — kế toán không
 * bao giờ thấy, trưởng phòng chỉ thấy vụ hạn chế mình phụ trách. Lời dẫn `contract_entry.scope_note` nói
 * đúng điều này cho mọi người mở trang. Mặc định lọc "Cần nhập hợp đồng" + "Nháp" — đúng những hồ sơ còn
 * việc phải nhập. "Cần nhập hợp đồng" là MỘT luật, {@see self::whereNeedsContract()} (làn fa3, 2026-10-16,
 * mục 4): hồ sơ đang mở, chưa có hợp đồng nào hoặc hợp đồng hiện hành đã huỷ — đúng những dòng có nút "Nhập
 * hợp đồng" (trừ hồ sơ đã kết thúc); ô "Hồ sơ cần nhập hợp đồng" của {@see ContractsToEnterWidget} đếm bằng
 * chính hàm đó. Bộ lọc trạng thái "Chưa có hợp đồng" (`STATUS_NONE`) vẫn có, nghĩa hẹp: chưa từng có hợp
 * đồng nào, kể cả hồ sơ đã kết thúc.
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
 * ràng.
 *
 * **Một khác biệt có chủ đích với form của tab:** tab "Hợp đồng và thanh toán" truyền gợi ý tổng giá
 * trị là phí đã báo lúc tiếp nhận (`BillingRelationManager::quotedAmountSuggestion()` →
 * `ContractScheduleForm::fields(suggestedTotal:)`, M10 R3); trang này KHÔNG truyền. Kế toán không thấy
 * gì của tiếp nhận (không `intake.*`, SPEC §5) — phí đã báo là ghi chú của người tư vấn, không phải số
 * trên hợp đồng giấy kế toán đang cầm, và một con số điền sẵn từ nơi kế toán không được xem vừa lộ
 * dữ liệu tiếp nhận vừa mời nhập sai giá trị. Ô tổng giá trị ở đây vì thế để trống.
 *
 * **Sửa bản nháp** ({@see UpdateDraftContract}), **Xoá bản nháp** ({@see DeleteDraftContract}),
 * **Kích hoạt (đã ký)** ({@see ActivateContract}) chỉ hiện khi hợp đồng hiện hành còn nháp — trừ khi
 * hộp thoại của nút đã mở trên dòng đó: khi ấy trạng thái đổi ở tab khác không làm nút tự ẩn, Action
 * từ chối bằng câu của nó ({@see self::isOpenDialog()}; "Nhập hợp đồng" cũng vậy). **Ghi khoản thu**
 * (hợp đồng đang hiệu lực) là một liên kết sang trang Công nợ, lọc sẵn mã hồ sơ — chỉ hiện khi người
 * xem mở được trang đó. **Đưa về bản nháp** ({@see RevertContractToDraft}, làn fa3 2026-10-16) chỉ hiện
 * trên hợp đồng hiện hành đang hiệu lực (cùng ngoại lệ hộp thoại đang mở): sửa một lần kích hoạt nhầm —
 * Action từ chối khi đã có khoản thu chưa huỷ, phụ lục, hay đợt từng được miễn/dời hạn.
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

    /** Làn fa3: giá trị của bộ lọc trạng thái cho "Cần nhập hợp đồng" — {@see self::whereNeedsContract()}. */
    public const STATUS_TO_ENTER = 'to_enter';

    /** Fix round 2 (mE): cột EXISTS "có khoản thu nào trên hợp đồng" nạp cùng hợp đồng hiện hành — {@see self::hasPaymentHistory()}. */
    private const PAYMENT_HISTORY = 'has_payment_history';

    protected string $view = 'filament.admin.pages.contract-entry';

    protected static ?string $slug = 'contract-entry';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    /**
     * Gộp integ-4 (phán quyết controller): nhóm "Tài chính", giữa "Công nợ" (20) và "Nhắc thanh toán cho
     * khách" (30) — các trang tiền của kế toán đứng cạnh nhau. `tests/Feature/Filament/AdminNavigationGroupsTest.php`.
     */
    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Finance;

    protected static ?int $navigationSort = 25;

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
                    // Làn fa3: "đ" như "d", "%"/"_" người gõ được thoát (`LetterD`, cùng trang Tìm kiếm và Tài liệu).
                    ->searchable(query: fn (Builder $query, string $search): Builder => LetterD::whereContains($query, 'matters.code', $search)),
                TextColumn::make('matter_type_name')
                    ->label(__('contract_entry.columns.matter_type'))
                    ->state(fn (Matter $record): string => static::rowFor($record)->matterTypeName),
                TextColumn::make('client_name')
                    ->label(__('contract_entry.columns.client'))
                    ->state(fn (Matter $record): string => static::rowFor($record)->clientName)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'client',
                        fn (Builder $client) => LetterD::whereContains($client->withTrashed(), $client->qualifyColumn('name'), $search),
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
                    ->default([self::STATUS_TO_ENTER, ContractStatus::Draft->value])
                    // Đợt polish (m4): tìm lại dòng của một hộp thoại đang mở KHÔNG qua bộ lọc này —
                    // xem `isOpenDialog()`. Tập dòng vẫn là `listableBy()` (truy vấn gốc của bảng).
                    ->excludeWhenResolvingRecord()
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
                $this->revertToDraftAction(),
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
                // Fix round 2 (mE, làn fa3): nút "Xoá bản nháp" hỏi "có lịch sử khoản thu (kể cả đã huỷ) không" trên
                // mỗi dòng nháp — một EXISTS trong câu nạp hợp đồng thay vì một truy vấn `hasPayments()` mỗi dòng.
                'contract' => fn ($query) => $query->withExists([
                    'instalments as '.self::PAYMENT_HISTORY => fn ($instalments) => $instalments->whereHas('payments'),
                ]),
            ]);
    }

    /**
     * Có khoản thu nào (kể cả đã huỷ) trên hợp đồng hiện hành của dòng — đúng câu của `Contract::hasPayments()`,
     * đọc cột EXISTS đã nạp ({@see self::rowsQuery()}); dòng được nạp theo đường khác thì hỏi thẳng.
     */
    private static function hasPaymentHistory(Contract $contract): bool
    {
        $loaded = $contract->getAttribute(self::PAYMENT_HISTORY);

        return $loaded === null ? $contract->hasPayments() : (bool) $loaded;
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
            self::STATUS_TO_ENTER => __('contract_entry.status.to_enter'),
            self::STATUS_NONE => __('contract_entry.status.none'),
            ...collect(ContractStatus::cases())
                ->mapWithKeys(fn (ContractStatus $status): array => [$status->value => $status->label()])
                ->all(),
        ];
    }

    /**
     * Lọc theo trạng thái của hợp đồng HIỆN HÀNH (`Matter::contract()`, bản mới nhất). Không chọn gì thì
     * không lọc. "Cần nhập hợp đồng" là {@see self::whereNeedsContract()}; "Chưa có hợp đồng" là
     * `whereDoesntHave('contracts')` — vụ chưa từng có hợp đồng nào. Các giá trị đã chọn gộp bằng HOẶC.
     *
     * `$values` là chuỗi kể cả khi bộ lọc đến từ query string (`#[Url]`): ô chọn nhiều của Filament bỏ
     * mọi giá trị không nằm trong `statusOptions()` khi điền bộ lọc từ URL, nên một mảng lồng tự chế
     * (`filters[contract_status][values][0][]=x`) tới đây thành `[]` — trang trả 200, không lọc (đo
     * bằng HTTP ở `ContractEntryPageTest`, "crafted nested array in the contract status filter").
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
            if (in_array(self::STATUS_TO_ENTER, $values, true)) {
                $inner->orWhere(fn (Builder $toEnter): Builder => static::whereNeedsContract($toEnter));
            }

            if (in_array(self::STATUS_NONE, $values, true)) {
                $inner->orWhereDoesntHave('contracts');
            }

            if ($statuses !== []) {
                $inner->orWhereHas('contract', fn (Builder $contract) => $contract->whereIn('status', $statuses));
            }
        });
    }

    /**
     * Làn fa3 (2026-10-16, mục 4) — luật "Cần nhập hợp đồng", MỘT định nghĩa cho bộ lọc mặc định của trang
     * và cho ô "Hồ sơ cần nhập hợp đồng" trên trang chủ ({@see ContractsToEnterWidget}): hồ sơ ĐANG MỞ
     * (`Matter::scopeOpen()`, một định nghĩa của "đang mở"; vụ đã huỷ vốn nằm ngoài `listableBy()` của người gọi) mà chưa có hợp đồng nào, hoặc
     * hợp đồng HIỆN HÀNH (`Matter::contract()`, bản mới nhất) đã huỷ — tức hồ sơ chưa có hợp đồng nào đang
     * sống, đúng những dòng có nút "Nhập hợp đồng". Hồ sơ đã kết thúc không còn là việc phải nhập: hợp đồng
     * (nếu có) đã được ghi từ trước, và một hồ sơ cũ chưa từng có hợp đồng (khi bắt đầu dùng hệ thống) không
     * nằm mãi trong việc của kế toán. Chỉ thêm điều kiện, không tự áp tầm nhìn: người gọi đã `listableBy()`.
     *
     * @param  Builder<Matter>  $matters
     * @return Builder<Matter>
     */
    public static function whereNeedsContract(Builder $matters): Builder
    {
        return $matters
            ->open()
            ->where(fn (Builder $either): Builder => $either
                ->whereDoesntHave('contracts')
                ->orWhereHas('contract', fn (Builder $contract) => $contract->where('status', ContractStatus::Cancelled->value)));
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

    /**
     * `$action` là hộp thoại ĐANG MỞ trên trang này (đợt polish, m4 — trang này và tab tiền của vụ làm
     * trên CÙNG một bản nháp).
     *
     * Bốn nút trên dòng chỉ hiện theo trạng thái hợp đồng ("Nhập hợp đồng" khi chưa có/đã huỷ, ba nút
     * nháp khi còn nháp) — để mỗi dòng chỉ mời đúng việc của nó. Nhưng Filament hỏi lại `->visible()`
     * NGAY LÚC MỞ và LÚC GỬI hộp thoại (`mountAction()`/`callMountedAction()` dừng khi `isDisabled()`,
     * mà `isDisabled()` đọc `isHidden()` — chú thích ở `BillingRelationManager::activateContractAction()`):
     * nếu ở tab kia người khác vừa kích hoạt, xoá hay soạn bản nháp, nút tự ẩn lúc bấm, Filament bỏ
     * ngang không một lời và dữ liệu đã gõ mất. Nên với hộp thoại đã mở (trên đúng dòng người dùng
     * bấm), điều kiện hiện KHÔNG đọc trạng thái nữa: Action bên dưới là nơi nói "không" bằng câu tiếng
     * Việt của nó (`ContractStatusConflict`, `ContractNotDestroyable`, `billing.validation.contract_exists`), qua
     * {@see ReportsActionFailures} — hộp thoại ở lại với dữ liệu đã gõ. Thành ngữ của tab là "chỉ lọc
     * cấu trúc"; ở một danh sách nhiều dòng, lọc cấu trúc nghĩa là bốn nút trên mọi dòng, và "Nhập hợp
     * đồng" vẫn ẩn khi người khác vừa soạn (vụ đã có hợp đồng) — nên ở đây là "không lọc trạng thái khi
     * hộp thoại đang mở".
     *
     * "Đang mở" = trang có một mục `$mountedActions` ở ĐÚNG vị trí lồng của action. Filament chỉ gán
     * vị trí lồng cho action nó tìm lại từ một mục như vậy (`InteractsWithActions::resolveActions()`) —
     * và dòng của mục đó là dòng người dùng đã bấm (`context.recordKey`); action chưa mở không có vị
     * trí lồng, không trỏ tới mục nào (`-1`). Nút trên các dòng của bảng được vẽ từ những bản khác,
     * không mang vị trí lồng, nên vẫn theo trạng thái — kể cả lúc một hộp thoại đang mở (đo bằng
     * `ContractEntryPageTest`, "still draws the other rows…"; phép thử ngược so thêm khoá dòng không đổi
     * kết quả nào, nên không có điều kiện đó). Hỏi "có mục ở vị trí đó không", không chỉ "có vị trí
     * lồng không": công cụ test của Filament (`assertActionHidden`…) cũng gán vị trí lồng khi tìm action
     * mà không mở nó. Gửi xong, Filament gỡ mục đó.
     *
     * Không mở rộng quyền: `->authorize()` (không đọc trạng thái) vẫn hỏi `Gate`, Action hỏi lại trên
     * hàng đã khoá. Bộ lọc trạng thái không áp khi Filament tìm lại dòng (`excludeWhenResolvingRecord`),
     * nếu không một dòng vừa đổi trạng thái rơi khỏi bộ lọc mặc định và hộp thoại cũng bỏ ngang. Còn một
     * trường hợp vẫn im lặng: bản nháp ĐẦU TIÊN của vụ bị xoá ở tab kia — vụ không còn hợp đồng nào, ba
     * nút nháp không còn hợp đồng để hỏi `Gate` nên tự ẩn; lần vẽ sau cho thấy "Chưa có hợp đồng".
     */
    private function isOpenDialog(Action $action): bool
    {
        return isset($this->mountedActions[$action->getNestingIndex() ?? -1]);
    }

    private function enterContractAction(): Action
    {
        return Action::make('enterContract')
            ->label(__('contract_entry.actions.enter'))
            ->icon(Heroicon::OutlinedDocumentPlus)
            ->color('primary')
            ->modalHeading(__('contract_entry.actions.enter_heading'))
            // Vòng sửa 1 (I1): hợp đồng hiện hành đã huỷ thì nói thêm đúng câu của modal "Soạn hợp đồng
            // mới" ở tab — luật tiền đã thu kèm SỐ đã thu trên hợp đồng đã huỷ, để chỉ nhập phần còn phải thu.
            ->modalDescription(fn (Matter $record): string => trim(__('contract_entry.actions.enter_description')
                .' '.ContractScheduleForm::redraftCollectedRule($record)))
            // Rà soát cuối F1: nút gửi nói đúng việc nó làm theo công tắc `activate_now` (ô `live()`, đọc
            // từ dữ liệu thô của hộp thoại đang mở) — "Lưu nháp" khi tắt, "Lưu và kích hoạt" khi bật —
            // thay nhãn mặc định "Gửi"; lời dẫn đầu trang và QUY-TRINH gọi đúng hai nhãn này.
            ->modalSubmitActionLabel(fn (Action $action): string => ($action->getRawData()['activate_now'] ?? false)
                ? __('contract_entry.actions.submit_activate')
                : __('contract_entry.actions.submit_draft'))
            ->modalWidth('4xl')
            ->visible(fn (Action $action, Matter $record): bool => $this->isOpenDialog($action)
                || $record->contract === null
                || $record->contract->status === ContractStatus::Cancelled)
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
            ->visible(fn (Action $action, Matter $record): bool => $this->isOpenDialog($action) || $record->contract?->status === ContractStatus::Draft)
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
            ->visible(fn (Action $action, Matter $record): bool => $this->isOpenDialog($action) || $record->contract?->status === ContractStatus::Draft)
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

    /**
     * Làn fa3 (2026-10-16, mục 2): "Đưa về bản nháp" cho hợp đồng hiện hành đang hiệu lực — xem docblock lớp.
     * `->authorize()` hỏi `ContractPolicy::revertToDraft` trên đúng hợp đồng của dòng; Action hỏi lại trên
     * hàng đã khoá và nói "không" (khoản thu, phụ lục, miễn/dời hạn) qua {@see ReportsActionFailures}.
     */
    private function revertToDraftAction(): Action
    {
        return Action::make('revertToDraft')
            ->label(__('billing_corrections.revert.label'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->modalHeading(__('billing_corrections.revert.heading'))
            ->modalDescription(fn (Matter $record): string => __('billing_corrections.revert.description', ['code' => $record->contract?->code ?? '—']))
            // Fix round 2 (mB): không mời trên hồ sơ đã kết thúc (Action từ chối — `ContractNotRevertible::matterClosed()`).
            ->visible(fn (Action $action, Matter $record): bool => $this->isOpenDialog($action)
                || ($record->contract?->status === ContractStatus::Active && ! $record->isClosed()))
            // Fix round 2 (I-B): `revertToDraft` nay đọc `activated_by` (người chỉ có `contract.enter` — chỉ hợp đồng chính
            // mình đã kích hoạt), nên nút ẩn trên hợp đồng người khác kích hoạt. Khi hộp thoại ĐANG MỞ thì hỏi tầng
            // "ai thử được" (`activate`: thấy tiền của vụ + `contract.manage`/`contract.enter`, không đọc trạng thái) —
            // cùng lý do với `isOpenDialog()`: người khác vừa đưa về hay kích hoạt lại thì Action (hỏi lại `revertToDraft`
            // trên hàng đã khoá) nói vì sao, thay vì lần bấm biến mất. Không mở rộng quyền: Action vẫn từ chối.
            ->authorize(fn (Action $action, Matter $record): bool => $record->contract !== null
                && Gate::allows($this->isOpenDialog($action) ? 'activate' : 'revertToDraft', $record->contract))
            ->schema([
                Textarea::make('reason')
                    ->label(__('billing.tab.fields.reason'))
                    ->helperText(__('billing_corrections.revert.reason_help'))
                    ->required(),
            ])
            ->successNotificationTitle(__('billing_corrections.revert.success'))
            ->action(fn (Action $action, Matter $record, array $data) => $this->runAction(
                $action,
                fn () => app(RevertContractToDraft::class)->handle(Auth::user(), $record->contract, $data['reason'] ?? ''),
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
            // Fix round 1 (m6, làn fa3): bản nháp mang lịch sử khoản thu đã huỷ (một lần kích hoạt nhầm đã được
            // "Đưa về bản nháp") không xoá được — `Contract::assertDestroyable()`; nút không mời một việc chắc chắn
            // bị từ chối. Bản nháp đó sửa rồi kích hoạt lại.
            ->visible(fn (Action $action, Matter $record): bool => $this->isOpenDialog($action)
                || ($record->contract?->status === ContractStatus::Draft && ! self::hasPaymentHistory($record->contract)))
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
