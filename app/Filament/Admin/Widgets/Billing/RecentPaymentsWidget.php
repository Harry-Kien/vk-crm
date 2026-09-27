<?php

namespace App\Filament\Admin\Widgets\Billing;

use App\Actions\Billing\VoidPayment;
use App\Filament\Admin\Concerns\ReportsActionFailures;
use App\Filament\Admin\Pages\Receivables;
use App\Models\Client;
use App\Models\Payment;
use App\Models\User;
use App\Policies\Concerns\ChecksBillingAccess;
use App\Support\Billing\AccountantPaymentRow;
use App\Support\Billing\Money;
use App\Support\Scopes\ClientPortalScope;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Mục "Khoản thu gần đây" của trang "Công nợ" (lượt rà soát cuối M9, I2).
 *
 * **Vì sao có mục này.** Bảng công nợ của trang chỉ liệt kê đợt còn `pending` — một đợt đã thu đủ
 * (`paid`) hay đã miễn rời khỏi bảng đó, nên nút "Huỷ khoản thu" của bảng không còn tới được khoản
 * thu ghi nhầm trên nó. Kế toán không mở được trang vụ việc (`matter.view`), nên với họ đó là một
 * khoản tiền ghi nhầm KHÔNG AI sửa được. Mục này liệt kê khoản thu CHƯA HUỶ trong 90 ngày gần nhất
 * (theo `paid_on`, hôm nay trừ 90 ngày trở đi) — bất kể đợt của nó còn nợ hay không — mỗi dòng một
 * nút huỷ qua {@see VoidPayment}.
 *
 * **Một widget, không phải bảng thứ hai của trang:** một trang Filament chỉ mang MỘT
 * `InteractsWithTable`; mục thứ hai là một `TableWidget` đặt ở chân trang
 * (`Receivables::getFooterWidgets()`), `$isDiscovered = false` để panel không gom nó lên trang chủ.
 *
 * # Cùng ba ranh giới với bảng công nợ — không viết lại cái nào
 *
 * - **Ai vào:** {@see self::canView()} hỏi ĐÚNG `Receivables::canAccess()` (`billing.view` +
 *   `revenue.viewAny`).
 * - **Tập dòng:** `Matter::scopeListableBy($user)` qua `instalment.contract.matter` — định nghĩa
 *   "ai thấy tiền của vụ nào" của {@see ChecksBillingAccess}; kế toán và
 *   quản lý không thấy khoản thu của vụ `restricted`, admin thấy.
 * - **Dữ liệu:** mọi cột đọc qua {@see AccountantPaymentRow} (bảy trường) — không tiêu đề vụ việc,
 *   không ghi chú nội bộ của khoản thu, không lý do huỷ, không luật sư được ghi doanh thu.
 *
 * **Quản lý chỉ xem:** nút huỷ hỏi `PaymentPolicy::void` (`payment.record`, quản lý không có) trên
 * đúng khoản thu của dòng. `VoidPayment` hỏi lại trên hàng ĐÃ KHOÁ, và tự từ chối khoản thu trên
 * hợp đồng đã hoàn tất (C1) — lời từ chối đi ra thành thông báo qua {@see ReportsActionFailures}.
 *
 * **Số truy vấn không tăng theo số dòng:** `instalment.contract.matter.client` nạp sẵn và ĐẦY ĐỦ
 * (vụ việc mang `confidentiality`/`lead_lawyer_id`/`deleted_at`, nên cổng tiền không nạp lại
 * mỗi dòng khi `->authorize()` hỏi — cùng lý do của bảng công nợ).
 */
class RecentPaymentsWidget extends TableWidget
{
    use ReportsActionFailures;

    /** Cửa sổ thời gian của mục, tính theo `payments.paid_on`, cả hai đầu. */
    public const WINDOW_DAYS = 90;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Receivables::canAccess();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('billing.receivables.recent_payments.heading'))
            ->description(__('billing.receivables.recent_payments.description', ['days' => self::WINDOW_DAYS]))
            ->query(fn (): Builder => $this->rowsQuery())
            ->emptyStateHeading(__('billing.receivables.recent_payments.empty_heading'))
            ->columns([
                TextColumn::make('matter_code')
                    ->label(__('billing.receivables.columns.matter_code'))
                    ->state(fn (Payment $record): string => static::rowFor($record)->matterCode),
                TextColumn::make('client_name')
                    ->label(__('billing.receivables.columns.client'))
                    ->state(fn (Payment $record): string => static::rowFor($record)->clientName),
                TextColumn::make('instalment_name')
                    ->label(__('billing.receivables.columns.instalment'))
                    ->state(fn (Payment $record): string => static::rowFor($record)->instalmentName)
                    ->wrap(),
                TextColumn::make('amount')
                    ->label(__('billing.receivables.columns.amount'))
                    ->state(fn (Payment $record): string => Money::format(static::rowFor($record)->amount)),
                TextColumn::make('paid_on')
                    ->label(__('billing.receivables.recent_payments.columns.paid_on'))
                    ->state(fn (Payment $record): string => static::rowFor($record)->paidOn->format('d/m/Y')),
                TextColumn::make('method')
                    ->label(__('billing.receivables.recent_payments.columns.method'))
                    ->state(fn (Payment $record): string => static::rowFor($record)->method->label()),
                TextColumn::make('reference')
                    ->label(__('billing.receivables.recent_payments.columns.reference'))
                    ->state(fn (Payment $record): ?string => static::rowFor($record)->reference)
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('client_id')
                    ->label(__('billing.receivables.filters.client'))
                    ->options(fn (): array => static::clientOptions())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, $value): Builder => $query->whereHas(
                            'instalment.contract.matter',
                            fn (Builder $matter) => $matter->where('client_id', $value),
                        ),
                    )),
                Filter::make('matter_code')
                    ->schema([
                        TextInput::make('code')->label(__('billing.receivables.recent_payments.filters.matter_code')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['code'] ?? null) ? trim((string) $data['code']) : null,
                        fn (Builder $query, string $code): Builder => $query->whereHas(
                            'instalment.contract.matter',
                            fn (Builder $matter) => $matter->where('code', 'like', '%'.addcslashes($code, '%_\\').'%'),
                        ),
                    )),
            ])
            ->recordActions([
                $this->voidPaymentAction(),
            ])
            ->defaultPaginationPageOption(10);
    }

    /**
     * Khoản thu CHƯA HUỶ, `paid_on` trong {@see self::WINDOW_DAYS} ngày gần nhất (tính cả hai
     * đầu, NGÀY theo múi giờ ứng dụng), của vụ việc người xem thấy được tiền — mới nhất trước.
     */
    private function rowsQuery(): Builder
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return Payment::query()->whereRaw('1 = 0');
        }

        return Payment::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->whereNull('voided_at')
            ->where('paid_on', '>=', today()->subDays(self::WINDOW_DAYS)->toDateString())
            ->whereHas('instalment.contract.matter', fn (Builder $matter) => $matter->listableBy($user))
            ->with([
                'instalment.contract.matter' => fn ($matter) => $matter->with([
                    'client' => fn ($client) => $client->withTrashed(),
                ]),
            ])
            ->orderByDesc('paid_on')
            ->orderByDesc('id');
    }

    private static function rowFor(Payment $record): AccountantPaymentRow
    {
        return AccountantPaymentRow::fromPayment($record);
    }

    /** Khách hàng có ít nhất một khoản thu trong tập dòng của CHÍNH người đang xem — không rộng hơn. */
    private static function clientOptions(): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return [];
        }

        return Client::query()
            ->whereHas('matters', fn (Builder $matter) => $matter->listableBy($user)
                ->whereHas('contract.instalments.payments', fn (Builder $payment) => $payment
                    ->whereNull('voided_at')
                    ->where('paid_on', '>=', today()->subDays(self::WINDOW_DAYS)->toDateString())))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * "Huỷ khoản thu" trên ĐÚNG khoản thu của dòng. `->authorize()` hỏi `PaymentPolicy::void` trên
     * chính bản ghi (vụ việc đã nạp đầy đủ ở {@see self::rowsQuery()}); `VoidPayment` hỏi lại trên
     * hàng đã khoá.
     */
    private function voidPaymentAction(): Action
    {
        return Action::make('voidPayment')
            ->label(__('billing.receivables.actions.void_payment'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->modalHeading(__('billing.receivables.actions.void_payment_heading'))
            ->authorize(fn (Payment $record): bool => Gate::allows('void', $record))
            ->schema([
                Textarea::make('reason')
                    ->label(__('billing.tab.fields.reason'))
                    ->helperText(__('billing.tab.fields.reason_help'))
                    ->required(),
            ])
            ->successNotificationTitle(__('billing.receivables.actions.void_payment_success'))
            ->action(fn (Action $action, Payment $record, array $data) => $this->runAction(
                $action,
                fn () => app(VoidPayment::class)->handle(Auth::user(), $record, $data['reason'] ?? ''),
            ));
    }
}
