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
 * **Gõ mã hồ sơ thì bỏ cửa sổ 90 ngày** (M9 Task 13, vòng sửa 1, I1): lúc nhập hợp đồng đang chạy
 * khi bắt đầu dùng hệ thống (QUY-TRINH, Giai đoạn 5), kế toán ghi lùi `paid_on` về nhiều tháng
 * trước. Một khoản như vậy ghi nhầm trên một đợt đã thu đủ thì vừa rời bảng công nợ vừa nằm ngoài
 * cửa sổ — lại thành khoản kế toán không huỷ được. Nên bộ lọc "Mã hồ sơ" quyết định cửa sổ
 * ({@see self::windowOrMatterCode()}): để trống là 90 ngày; gõ mã là mọi khoản thu chưa huỷ của các
 * hồ sơ khớp mã, cũ đến đâu cũng vậy. Chỉ cửa sổ được bỏ: điều kiện "chưa huỷ" và phạm vi
 * `listableBy()` vẫn nằm ở {@see self::rowsQuery()}, áp cho mọi dòng.
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
 * đúng khoản thu của dòng. `VoidPayment` hỏi lại trên hàng ĐÃ KHOÁ; mọi lời từ chối của nó đi ra
 * thành thông báo qua {@see ReportsActionFailures}.
 *
 * **Không có nút huỷ trên khoản thu của hợp đồng đã hoàn tất** (lượt sửa thứ hai sau rà soát cuối
 * M9, minor): `VoidPayment` LUÔN từ chối ở đó (C1), nên nút ẩn theo đúng định nghĩa đó,
 * `ContractStatus::allowsPaymentVoid()`, đọc từ hợp đồng đã nạp sẵn (không thêm truy vấn mỗi dòng).
 * Filament hỏi lại `->visible()` ngay lúc bấm: một hợp đồng được hoàn tất ở tab khác giữa lúc
 * trang vẽ và lúc bấm thì nút tắt đi lúc bấm, không huỷ gì — an toàn, và im lặng (cùng hình dạng
 * nút "Xoá bản nháp" của tab tiền).
 *
 * **Số truy vấn không tăng theo số dòng:** `instalment.contract.matter.client` nạp sẵn và ĐẦY ĐỦ
 * (vụ việc mang `confidentiality`/`lead_lawyer_id`/`deleted_at`, nên cổng tiền không nạp lại
 * mỗi dòng khi `->authorize()` hỏi — cùng lý do của bảng công nợ).
 */
class RecentPaymentsWidget extends TableWidget
{
    use ReportsActionFailures;

    /**
     * Cửa sổ thời gian của mục khi bộ lọc "Mã hồ sơ" để trống, tính theo `payments.paid_on`, cả hai
     * đầu. Gõ mã hồ sơ thì không có cửa sổ ({@see self::windowOrMatterCode()}).
     */
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
                    ->query(fn (Builder $query, array $data): Builder => static::windowOrMatterCode($query, $data['code'] ?? null)),
            ])
            ->recordActions([
                $this->voidPaymentAction(),
            ])
            ->defaultPaginationPageOption(10);
    }

    /**
     * Khoản thu CHƯA HUỶ của vụ việc người xem thấy được tiền — mới nhất trước. Cửa sổ thời gian
     * KHÔNG ở đây mà ở bộ lọc "Mã hồ sơ" ({@see self::windowOrMatterCode()}): Filament áp mọi bộ
     * lọc lên truy vấn này, kể cả bộ lọc để trống, và cả lúc tìm lại bản ghi cho nút huỷ — nên một
     * khoản ngoài cửa sổ chỉ huỷ được khi mã hồ sơ của nó đang được lọc.
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
            ->whereHas('instalment.contract.matter', fn (Builder $matter) => $matter->listableBy($user))
            ->with([
                'instalment.contract.matter' => fn ($matter) => $matter->with([
                    'client' => fn ($client) => $client->withTrashed(),
                ]),
            ])
            ->orderByDesc('paid_on')
            ->orderByDesc('id');
    }

    /**
     * Bộ lọc "Mã hồ sơ" quyết định cửa sổ thời gian của mục (docblock lớp): để trống (hay chỉ có
     * khoảng trắng) → chỉ khoản thu có `paid_on` từ hôm nay trừ {@see self::WINDOW_DAYS} ngày trở
     * đi (NGÀY theo múi giờ ứng dụng); có mã → khoản thu của các hồ sơ có mã CHỨA chuỗi đã gõ, không
     * giới hạn ngày. Điều kiện "chưa huỷ" và phạm vi `listableBy()` không ở đây, nên không bị bỏ.
     */
    private static function windowOrMatterCode(Builder $query, mixed $code): Builder
    {
        $code = filled($code) ? trim((string) $code) : null;

        if ($code === null) {
            return $query->where('paid_on', '>=', today()->subDays(self::WINDOW_DAYS)->toDateString());
        }

        return $query->whereHas(
            'instalment.contract.matter',
            fn (Builder $matter) => $matter->where('code', 'like', '%'.addcslashes($code, '%_\\').'%'),
        );
    }

    private static function rowFor(Payment $record): AccountantPaymentRow
    {
        return AccountantPaymentRow::fromPayment($record);
    }

    /**
     * Khách hàng có ít nhất một khoản thu trong tập dòng MẶC ĐỊNH (cửa sổ 90 ngày) của CHÍNH người
     * đang xem — không rộng hơn. Khoản cũ hơn tìm bằng mã hồ sơ, không bằng khách hàng.
     */
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
     * "Huỷ khoản thu" trên ĐÚNG khoản thu của dòng. Ẩn trên hợp đồng đã hoàn tất (docblock lớp).
     * `->authorize()` hỏi `PaymentPolicy::void` trên chính bản ghi (vụ việc đã nạp đầy đủ ở
     * {@see self::rowsQuery()}); `VoidPayment` hỏi lại trên hàng đã khoá.
     */
    private function voidPaymentAction(): Action
    {
        return Action::make('voidPayment')
            ->label(__('billing.receivables.actions.void_payment'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->modalHeading(__('billing.receivables.actions.void_payment_heading'))
            ->visible(fn (Payment $record): bool => $record->instalment->contract->status->allowsPaymentVoid())
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
