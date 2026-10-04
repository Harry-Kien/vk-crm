<?php

namespace App\Filament\Admin\Pages;

use App\Actions\Billing\RecordPayment;
use App\Actions\Billing\VoidPayment;
use App\Enums\ContractStatus;
use App\Enums\InstalmentState;
use App\Enums\InstalmentStatus;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Filament\Admin\Concerns\ReportsActionFailures;
use App\Filament\Admin\Resources\Matters\RelationManagers\BillingRelationManager;
use App\Filament\Admin\Widgets\Billing\RecentPaymentsWidget;
use App\Models\Client;
use App\Models\Instalment;
use App\Models\Payment;
use App\Models\User;
use App\Policies\Concerns\ChecksBillingAccess;
use App\Support\Billing\AccountantBillingRow;
use App\Support\Billing\BillingSummary;
use App\Support\Billing\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Trang "Công nợ" cho kế toán (M9 Task 8; kế hoạch, P3 "Ai thấy và ghi tiền"): kế toán ghi tiền
 * nhưng KHÔNG mở được trang vụ việc (`matter.view`) và không được cần tới trang đó — đây là màn
 * hình DUY NHẤT của họ về tiền, đứng cạnh tab "Hợp đồng và thanh toán" của Task 7 (dành cho người
 * mở được trang vụ việc: luật sư, quản lý, admin).
 *
 * # Cổng của cả TRANG — `canAccess()`, KHÔNG phải `billing.view`
 *
 * Quyền vào trang là `revenue.viewAny` (cặp với `billing.view` như `matter.viewAny` với
 * `matter.view` — xem docblock hằng số `Permission::RevenueViewAny`): admin, quản lý, kế toán vào
 * được; luật sư và trợ lý — có `billing.view` (luật sư) hoặc không có gì (trợ lý) nhưng không có
 * `revenue.viewAny` — nhận 404 qua `AnswerDeniedPanelRequestsWithNotFound` (SPEC §10.10: từ chối
 * không phân biệt "không có quyền" với "trang không tồn tại").
 *
 * # Tập dòng — ĐÚNG một định nghĩa, không viết lại
 *
 * `Matter::scopeListableBy($user)` qua {@see self::rowsQuery()} — cùng định nghĩa
 * {@see ChecksBillingAccess} dùng cho "ai thấy tiền của vụ nào". Mọi người
 * vào được trang này đã có `billing.view` (xem `Role::permissions()`: cả ba vai trò được
 * `revenue.viewAny` đều có sẵn `billing.view`), nên không cần hỏi lại điều kiện đó ở đây — chỉ cần
 * lọc theo vụ việc. Hệ quả đúng theo `isListableBy()`: kế toán và quản lý không thấy vụ
 * `restricted` (không có `matter.view` hoặc không phải luật sư phụ trách/admin); admin thấy tất cả.
 *
 * # Dữ liệu chỉ đi qua `AccountantBillingRow` — DTO giới hạn thông tin
 *
 * Mọi cột hiển thị đọc qua {@see AccountantBillingRow::fromInstalment()}, không đọc trực tiếp một
 * thuộc tính nào khác của `Matter`/`Contract`/`Instalment` — không tiêu đề vụ việc, không mô tả
 * nội bộ, không lý do miễn/huỷ. Mã hồ sơ là một CHUỖI, không phải một liên kết: kế toán không qua
 * được `MatterPolicy::view`, nên không có gì để trỏ tới.
 *
 * # SQL-aggregate outstanding — phán quyết controller 1 của Task 8
 *
 * `collected`/`outstanding` của mỗi dòng đọc THẲNG hai cột `collected_amount`/`outstanding_amount`
 * mà {@see BillingSummary::pendingInstalmentsQuery()} đã tính SẴN bằng biểu thức SQL, và `state()`
 * (Instalment) đọc lại đúng con số đó thay vì tự chạy một SUM() khác — xem docblock
 * {@see Instalment::collectedForState()}. Một round-trip cho TOÀN BỘ danh sách, không phải một
 * câu SUM() mỗi dòng.
 *
 * # Eager loading — vì sao cổng tiền không truy vấn lại mỗi dòng
 *
 * `contract.matter.matterType`/`contract.matter.client` nạp ĐẦY ĐỦ (không select cột), nên
 * `Matter` của mỗi dòng mang sẵn `confidentiality`/`lead_lawyer_id`/`deleted_at` — ba cột
 * {@see ChecksBillingAccess::matterForBillingGate()} cần để KHÔNG nạp lại.
 * `->authorize()` của hai nút "Ghi khoản thu"/"Huỷ khoản thu" gọi `Gate::allows()` cho MỖI dòng khi
 * Filament vẽ bảng; thiếu ba cột đó sẽ là một truy vấn nạp lại MỖI dòng — đúng thứ mà việc chọn
 * "nạp đầy đủ" ở đây tránh. `payments` nạp CÓ ĐIỀU KIỆN (`whereNull('voided_at')`) — cột "Các
 * khoản thu" và ô chọn "Huỷ khoản thu" đều đọc từ quan hệ đã nạp, không hỏi lại CSDL.
 *
 * # Huỷ ĐÚNG một khoản thu, không giới hạn "gần nhất" (phán quyết controller 2)
 *
 * Khác `BillingRelationManager::voidPaymentAction()` (tab của Task 7, huỷ khoản GẦN NHẤT chưa
 * huỷ), nút "Huỷ khoản thu" ở đây cho kế toán CHỌN đúng khoản cần huỷ trong số các khoản chưa huỷ
 * của đợt — vì kế toán mới là người ghi hằng ngày trên chính trang này, và một đợt thu nhiều lần
 * cần huỷ ĐÚNG dòng ghi nhầm, không phải luôn luôn dòng mới nhất.
 *
 * # Biên lai — KHÔNG có ở M9 (phán quyết controller 3)
 *
 * `receipt_document_id` bị bỏ trống ở mọi lần `RecordPayment::handle()` gọi từ trang này: mở
 * đường tải tệp cho kế toán đòi sửa `DocumentPolicy::create` (kế toán không có `matter.update`),
 * một thay đổi NGOÀI PHẠM VI làn này. `reference` (mã giao dịch/số biên lai) vẫn ghi được như một
 * chuỗi.
 *
 * # Mục thứ hai — "Khoản thu gần đây" (lượt rà soát cuối M9, I2)
 *
 * Bảng trên chỉ có đợt còn `pending`; một đợt đã thu đủ hay đã miễn rời khỏi nó, và cùng lúc nút
 * "Huỷ khoản thu" của bảng không còn tới được khoản thu ghi nhầm trên đợt đó. Chân trang mang
 * {@see RecentPaymentsWidget} ({@see self::getFooterWidgets()}): khoản thu chưa huỷ trong 90 ngày
 * (gõ mã hồ sơ thì mọi khoản thu chưa huỷ của hồ sơ đó, không giới hạn ngày — M9 Task 13, vòng sửa
 * 1), cùng phạm vi `listableBy()`, qua DTO `AccountantPaymentRow`, mỗi dòng một nút huỷ.
 */
class Receivables extends Page implements HasTable
{
    use InteractsWithTable;
    use ReportsActionFailures;

    protected string $view = 'filament.admin.pages.receivables';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    public static function getNavigationLabel(): string
    {
        return __('billing.receivables.navigation_label');
    }

    public function getTitle(): string
    {
        return __('billing.receivables.title');
    }

    /**
     * Cổng của cả TRANG — xem docblock lớp.
     *
     * **`billing.view` cộng thêm, có chủ đích (fix vòng 1, minor).** Bảng quyền hôm nay (`Role::
     * permissions()`) đã khiến `revenue.viewAny` một mình đủ — cả ba vai trò được cấp nó (admin,
     * quản lý, kế toán) đều có sẵn `billing.view`. Hỏi thêm ở đây không đổi ai vào được trang hôm
     * nay; nó là một lưới an toàn nếu một ngày `revenue.viewAny` được cấp riêng cho một vai trò
     * không có `billing.view` — trang này không có gì để hiện nếu vậy (`rowsQuery()` vẫn lọc theo
     * `listableBy()`, không đọc `billing.view`), nên đúng hơn là từ chối thẳng ở cổng.
     */
    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && static::canBeOpenedBy($user);
    }

    /**
     * Cùng cổng của {@see self::canAccess()}, hỏi về MỘT người cụ thể thay vì người đang đăng nhập —
     * cho nơi cần biết "người này có mở được trang Công nợ không" khi không có phiên nào (thư nhắc
     * đợt quá hạn, M9 Task 11, chọn liên kết theo từng người nhận). MỘT định nghĩa: `canAccess()`
     * gọi thẳng hàm này, không có bản thứ hai để lệch.
     */
    public static function canBeOpenedBy(User $user): bool
    {
        return $user->can(Permission::BillingView->value)
            && Gate::forUser($user)->allows(Permission::RevenueViewAny->value);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /** @return array<class-string<Widget>> */
    protected function getFooterWidgets(): array
    {
        return [RecentPaymentsWidget::class];
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 1;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->rowsQuery())
            ->description(__('billing.receivables.scope_note'))
            ->emptyStateHeading(__('billing.receivables.empty_heading'))
            ->columns([
                TextColumn::make('matter_code')
                    ->label(__('billing.receivables.columns.matter_code'))
                    ->state(fn (Instalment $record): string => static::rowFor($record)->matterCode),
                TextColumn::make('matter_type_name')
                    ->label(__('billing.receivables.columns.matter_type'))
                    ->state(fn (Instalment $record): string => static::rowFor($record)->matterTypeName),
                TextColumn::make('client_name')
                    ->label(__('billing.receivables.columns.client'))
                    ->state(fn (Instalment $record): string => static::rowFor($record)->clientName),
                TextColumn::make('instalment_name')
                    ->label(__('billing.receivables.columns.instalment'))
                    ->state(fn (Instalment $record): string => static::rowFor($record)->instalmentName)
                    ->wrap(),
                TextColumn::make('amount')
                    ->label(__('billing.receivables.columns.amount'))
                    ->state(fn (Instalment $record): string => Money::format(static::rowFor($record)->amount)),
                TextColumn::make('collected')
                    ->label(__('billing.receivables.columns.collected'))
                    ->state(fn (Instalment $record): string => Money::format(static::rowFor($record)->collected)),
                TextColumn::make('outstanding')
                    ->label(__('billing.receivables.columns.outstanding'))
                    ->state(fn (Instalment $record): string => Money::format(static::rowFor($record)->outstanding)),
                TextColumn::make('due_date')
                    ->label(__('billing.receivables.columns.due_date'))
                    ->state(fn (Instalment $record): ?string => static::rowFor($record)->dueDate?->format('d/m/Y'))
                    ->placeholder(__('billing.tab.due_date_scheduled')),
                TextColumn::make('state')
                    ->label(__('billing.receivables.columns.state'))
                    ->badge()
                    ->state(fn (Instalment $record): InstalmentState => static::rowFor($record)->state)
                    ->formatStateUsing(fn (InstalmentState $state): string => $state->label())
                    ->color(fn (InstalmentState $state): string => BillingRelationManager::stateColor($state)),
                TextColumn::make('payments_list')
                    ->label(__('billing.receivables.columns.payments'))
                    ->html()
                    ->wrap()
                    ->state(fn (Instalment $record): HtmlString => static::paymentsBlock($record)),
            ])
            ->filters([
                Filter::make('overdue')
                    ->label(__('billing.receivables.filters.overdue'))
                    ->query(fn (Builder $query): Builder => $query->overdue()),
                Filter::make('due_within_7_days')
                    ->label(__('billing.receivables.filters.due_within_7_days'))
                    // Cận là mốc thời gian đủ giờ, không phải ngày trần (M9 Task 13): trên SQLite cast
                    // `date` ghi `Y-m-d 00:00:00`, lớn hơn cận trên `Y-m-d` — đợt đến hạn đúng ngày
                    // thứ bảy rơi khỏi bộ lọc. Cùng lý do `RevenueFilters::bounds()`.
                    ->query(fn (Builder $query): Builder => $query
                        ->where('status', InstalmentStatus::Pending->value)
                        ->whereNotNull('due_date')
                        ->whereBetween('due_date', [today()->toDateTimeString(), today()->addDays(7)->endOfDay()->toDateTimeString()])),
                Filter::make('closed_with_balance')
                    ->label(__('billing.receivables.filters.closed_with_balance'))
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'contract.matter',
                        fn (Builder $matter) => $matter->closed(),
                    )),
                SelectFilter::make('client_id')
                    ->label(__('billing.receivables.filters.client'))
                    ->options(fn (): array => static::clientOptions())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, $value): Builder => $query->whereHas(
                            'contract.matter',
                            fn (Builder $matter) => $matter->where('client_id', $value),
                        ),
                    )),
            ])
            ->recordActions([
                $this->recordPaymentAction(),
                $this->voidPaymentAction(),
            ]);
    }

    /**
     * Tập dòng: mọi đợt còn tính là công nợ ({@see BillingSummary::pendingInstalmentsQuery()}),
     * lọc theo `Matter::listableBy()` — xem docblock lớp. Sắp xếp mặc định: quá hạn LÂU NHẤT
     * trước (`due_date` tăng dần, đợt chưa lên lịch — `due_date` null — xuống cuối, vì nó chưa có
     * gì để "quá hạn" so với).
     */
    private function rowsQuery(): Builder
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(404);
        }

        return BillingSummary::pendingInstalmentsQuery()
            ->whereHas('contract.matter', fn (Builder $matter) => $matter->listableBy($user))
            ->with([
                'contract.matter.matterType',
                'contract.matter.client',
                'payments' => fn ($query) => $query->whereNull('voided_at')->orderByDesc('paid_on')->orderByDesc('id'),
            ])
            ->orderByRaw('due_date is null, due_date asc');
    }

    private static function rowFor(Instalment $record): AccountantBillingRow
    {
        return AccountantBillingRow::fromInstalment(
            $record,
            (int) $record->getAttribute('collected_amount'),
            (int) $record->getAttribute('outstanding_amount'),
            $record->state(),
        );
    }

    /** Khách hàng có ít nhất một đợt công nợ trong tập dòng của CHÍNH người đang xem — không liệt kê rộng hơn tập đó. */
    private static function clientOptions(): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return [];
        }

        return Client::query()
            ->whereHas('matters', fn (Builder $matter) => $matter->listableBy($user)
                ->whereHas('contract', fn (Builder $contract) => $contract
                    ->where('status', ContractStatus::Active->value)
                    ->whereHas('instalments', fn (Builder $instalment) => $instalment->where('status', InstalmentStatus::Pending->value))))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** Chỉ khoản thu CHƯA HUỶ — đã nạp có điều kiện ở {@see self::rowsQuery()}, không hỏi lại CSDL. */
    private static function paymentsBlock(Instalment $instalment): HtmlString
    {
        if ($instalment->payments->isEmpty()) {
            return new HtmlString('<em>'.e(__('billing.tab.payments_empty')).'</em>');
        }

        $lines = $instalment->payments->map(fn (Payment $payment): string => e(sprintf(
            '%s — %s (%s)%s',
            $payment->paid_on->format('d/m/Y'),
            Money::format($payment->amount),
            $payment->method->label(),
            filled($payment->reference) ? ' — '.$payment->reference : '',
        )))->implode('<br/>');

        return new HtmlString($lines);
    }

    /**
     * "Ghi khoản thu" — không có ô tải biên lai (phán quyết controller 3, xem docblock lớp).
     * `->authorize()` hỏi CÓ NGỮ CẢNH vụ việc, cùng thành ngữ `BillingRelationManager::
     * recordPaymentAction()`; `$record->contract->matter` đã nạp ĐẦY ĐỦ (xem `rowsQuery()`), nên
     * câu hỏi này không truy vấn lại.
     */
    private function recordPaymentAction(): Action
    {
        return Action::make('recordPayment')
            ->label(__('billing.receivables.actions.record_payment'))
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->modalHeading(__('billing.receivables.actions.record_payment_heading'))
            ->authorize(fn (Instalment $record): bool => Gate::allows('create', [Payment::class, $record->contract->matter]))
            ->schema([
                TextInput::make('amount')
                    ->label(__('billing.tab.fields.amount'))
                    ->helperText(__('billing.tab.fields.payment_amount_help'))
                    ->maxLength(15)
                    ->required(),
                DatePicker::make('paid_on')
                    ->label(__('billing.tab.fields.paid_on'))
                    ->default(today()->toDateString())
                    ->native(false)
                    ->required(),
                Select::make('method')
                    ->label(__('billing.tab.fields.method'))
                    ->options(collect(PaymentMethod::cases())->mapWithKeys(fn (PaymentMethod $m): array => [$m->value => $m->label()])->all())
                    ->default(PaymentMethod::BankTransfer->value)
                    ->required(),
                TextInput::make('reference')
                    ->label(__('billing.tab.fields.reference'))
                    ->maxLength(RecordPayment::MAX_REFERENCE_LENGTH),
                Textarea::make('note')
                    ->label(__('billing.tab.fields.payment_note')),
            ])
            ->successNotificationTitle(__('billing.tab.actions.record_payment_success'))
            ->action(fn (Action $action, Instalment $record, array $data) => $this->runAction(
                $action,
                fn () => app(RecordPayment::class)->handle(
                    Auth::user(),
                    $record,
                    Money::parse((string) ($data['amount'] ?? ''), 'amount'),
                    $data['paid_on'] ?? '',
                    PaymentMethod::from($data['method']),
                    $data['reference'] ?? null,
                    null,
                    $data['note'] ?? null,
                ),
            ));
    }

    /**
     * "Huỷ khoản thu" — chọn ĐÚNG một khoản trong số các khoản chưa huỷ của đợt (phán quyết
     * controller 2, xem docblock lớp), không giới hạn khoản gần nhất.
     *
     * `->authorize()` hỏi trên một `Payment` CHƯA LƯU mang quan hệ `instalment` đã gán — cùng thành
     * ngữ `BillingRelationManager::voidPaymentAction()`: `PaymentPolicy::void()` chỉ đọc
     * `$payment->instalment->contract->matter`, không phụ thuộc một khoản thu cụ thể.
     */
    private function voidPaymentAction(): Action
    {
        return Action::make('voidPayment')
            ->label(__('billing.receivables.actions.void_payment'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->modalHeading(__('billing.receivables.actions.void_payment_heading'))
            ->visible(fn (Instalment $record): bool => $record->payments->isNotEmpty())
            ->authorize(fn (Instalment $record): bool => Gate::allows('void', (new Payment)->setRelation('instalment', $record)))
            ->schema([
                Select::make('payment_id')
                    ->label(__('billing.receivables.fields.payment_to_void'))
                    ->options(fn (Instalment $record): array => $record->payments
                        ->mapWithKeys(fn (Payment $payment): array => [
                            $payment->id => sprintf(
                                '%s — %s (%s)',
                                $payment->paid_on->format('d/m/Y'),
                                Money::format($payment->amount),
                                $payment->method->label(),
                            ),
                        ])->all())
                    ->required(),
                Textarea::make('reason')
                    ->label(__('billing.tab.fields.reason'))
                    ->helperText(__('billing.tab.fields.reason_help'))
                    ->required(),
            ])
            ->successNotificationTitle(__('billing.receivables.actions.void_payment_success'))
            ->action(fn (Action $action, Instalment $record, array $data) => $this->runAction(
                $action,
                function () use ($data, $record): Payment {
                    $payment = Payment::query()->whereNull('voided_at')->find($data['payment_id'] ?? null);

                    // **Không tin `payment_id` từ Livewire (fix vòng 1, minor).** Ô `Select` chỉ
                    // liệt kê khoản thu của ĐÚNG đợt đang mở modal, nhưng đó là một ràng buộc GIAO
                    // DIỆN — một request `wire:submit` dựng tay có thể gửi id của một khoản thu
                    // thuộc đợt KHÁC. `PaymentPolicy::void()` (qua `VoidPayment::handle()`) vẫn
                    // xét đúng vụ việc của KHOẢN ĐÓ nên không lộ tiền của vụ khác, nhưng nó có thể
                    // huỷ nhầm một khoản của một đợt/vụ THƯỜNG khác mà kế toán cũng có quyền — một
                    // đợt hiện trên màn hình không phải đợt bị đổi. Chốt ở đây: khoản được chọn
                    // phải thuộc ĐÚNG đợt đang mở modal, không chỉ "một khoản nào đó kế toán có
                    // quyền huỷ".
                    //
                    // **Đo được, kèm một giới hạn thành thật.** `ReceivablesPageTest`'s "refuses to
                    // void a payment whose id belongs to a different instalment…" đi qua đúng
                    // đường Livewire (`callAction()`) và ĐANG xanh; nhưng xoá điều kiện này rồi
                    // chạy lại KHÔNG làm test đó đỏ — validation "in options" của chính `Select`
                    // (vendor, sinh từ `getOptions()` được tính lại đúng cho `$record` đang mount)
                    // đã chặn TRƯỚC khi request chạm tới đây, với lỗi field `payment_id` "đã chọn
                    // không hợp lệ". Điều kiện này vì vậy là PHÒNG THỦ CHIỀU SÂU không độc lập đo
                    // được qua đường Livewire hiện có — giữ lại vì rẻ và đúng bất biến, nhưng không
                    // phải là chốt chặn DUY NHẤT; xem báo cáo Task 8, "Fix round 1" cho chi tiết.
                    if ($payment === null || $payment->instalment_id !== $record->id) {
                        throw ValidationException::withMessages(['payment_id' => [__('billing.receivables.no_payment_to_void')]]);
                    }

                    return app(VoidPayment::class)->handle(Auth::user(), $payment, $data['reason'] ?? '');
                },
            ));
    }
}
