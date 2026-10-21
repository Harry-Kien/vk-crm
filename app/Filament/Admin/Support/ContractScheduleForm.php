<?php

namespace App\Filament\Admin\Support;

use App\Actions\Billing\DraftContract;
use App\Actions\Billing\UpdateDraftContract;
use App\Enums\ContractStatus;
use App\Enums\InstalmentTrigger;
use App\Filament\Admin\Pages\ContractEntry;
use App\Filament\Admin\Resources\Matters\RelationManagers\BillingRelationManager;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Support\Billing\CollectedRevenue;
use App\Support\Billing\Money;
use App\Support\Billing\SplitByPercent;
use App\Support\Billing\Vat;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Form "soạn / sửa bản nháp hợp đồng" — MỘT bản, dùng chung cho tab "Hợp đồng và thanh toán" của vụ
 * ({@see BillingRelationManager}, luật sư/quản lý/quản trị) và trang "Hợp đồng" của kế toán
 * ({@see ContractEntry}, làn kt). Tách ra từ `BillingRelationManager` ở làn kt (không đổi một ô nào):
 * hai màn hình nhập cùng một hợp đồng thì phải hỏi cùng những ô, cùng luật phần trăm, cùng khung xem
 * trước — không chép hai bản để rồi lệch nhau.
 *
 * # Ô tiền
 *
 * Mọi ô tiền là `TextInput` nhận chuỗi có dấu chấm phân nhóm nghìn, được đưa qua {@see Money::parse()}
 * NGAY TRONG closure gọi Action ({@see self::actionArguments()}, bên trong `ReportsActionFailures::
 * runAction()` của màn hình) — nên một chuỗi hỏng ra lỗi gắn đúng ô (`total_amount`,
 * `instalments.0.amount`). `maxLength(15)` khớp `"999.999.999.999"` (`Money::MAX` đã định dạng).
 *
 * # Chia theo phần trăm và ba con số VAT — xem trước NGAY trong form (lượt rà soát cuối M9, I5)
 *
 * Mỗi dòng lịch thu nhập HOẶC phần trăm của tổng giá trị HOẶC số tiền, không bao giờ cả hai: điền
 * phần trăm thì ô số tiền bị khoá, để trống (và không gửi lên — {@see self::percentOrAmountFields()}),
 * số tiền TÍNH từ phần trăm bằng {@see SplitByPercent::amountsForRows()} — mọi dòng theo phần trăm
 * cộng đủ 100% thì đợt cuối nhận phần dư làm tròn. Cùng MỘT hàm tính cho khung xem trước lúc gõ
 * ({@see self::schedulePreview()}) và cho lúc lưu ({@see self::parsedInstalmentRows()}) — số người
 * dùng thấy là số được ghi. Khung xem trước còn in ba con số VAT của tổng qua {@see Vat}. Không Action
 * nào đổi: `DraftContract`/`UpdateDraftContract` vẫn nhận `amount` (số quyết định) và `percent_basis`
 * (truy vết) như trước.
 *
 * Form phụ lục (chỉ ở tab của vụ) dùng lại {@see self::percentOrAmountFields()},
 * {@see self::vatPreviewLine()}, {@see self::previewRowLine()}, {@see self::tryParseMoney()} — phần
 * riêng của phụ lục ở lại `BillingRelationManager`.
 */
final class ContractScheduleForm
{
    /** @return array<int, mixed> */
    public static function fields(Matter $matter, ?Closure $suggestedTotal = null): array
    {
        return [
            TextInput::make('total_amount')
                ->label(__('billing.tab.fields.total_amount'))
                ->helperText(__('billing.tab.fields.total_amount_help'))
                // Chỉ áp khi modal mở KHÔNG có `fillForm()` (soạn mới); "sửa bản nháp" điền từ hợp đồng.
                ->default($suggestedTotal)
                ->maxLength(15)
                ->required()
                ->live(onBlur: true),
            TextInput::make('vat_rate_percent')
                ->label(__('billing.tab.fields.vat_rate_percent'))
                ->helperText(__('billing.tab.fields.vat_rate_percent_help'))
                ->numeric()
                ->live(onBlur: true),
            Repeater::make('instalments')
                ->label(__('billing.tab.fields.instalments'))
                ->addActionLabel(__('billing.tab.actions.add_instalment'))
                ->defaultItems(1)
                ->columns(2)
                ->live()
                ->schema([
                    TextInput::make('name')
                        ->label(__('billing.tab.fields.instalment_name'))
                        ->helperText(__('billing.tab.fields.instalment_name_help'))
                        ->maxLength(150)
                        ->required()
                        ->columnSpanFull(),
                    ...self::percentOrAmountFields(null, __('billing.tab.fields.instalment_percent_basis_help')),
                    Select::make('trigger_type')
                        ->label(__('billing.tab.fields.trigger_type'))
                        ->options(self::triggerOptions())
                        ->live()
                        ->required(),
                    DatePicker::make('due_date')
                        ->label(__('billing.tab.fields.due_date'))
                        ->native(false)
                        ->visible(fn (Get $get): bool => $get('trigger_type') === 'due_date'),
                    Select::make('trigger_stage_key')
                        ->label(__('billing.tab.fields.trigger_stage_key'))
                        ->options(fn (): array => self::stageOptions($matter))
                        ->visible(fn (Get $get): bool => $get('trigger_type') === 'stage'),
                    TextInput::make('due_days_after_trigger')
                        ->label(__('billing.tab.fields.due_days_after_trigger'))
                        ->numeric()
                        ->default(0)
                        ->visible(fn (Get $get): bool => $get('trigger_type') !== 'due_date' && $get('trigger_type') !== null),
                    Textarea::make('note')
                        ->label(__('billing.tab.fields.note'))
                        ->columnSpanFull(),
                ]),
            TextEntry::make('schedule_preview')
                ->label(__('billing.tab.preview.heading'))
                ->state(fn (Get $get): HtmlString => self::schedulePreview(
                    $get('total_amount'),
                    $get('vat_rate_percent'),
                    (array) ($get('instalments') ?? []),
                ))
                ->html()
                ->columnSpanFull(),
        ];
    }

    /**
     * Cặp ô "phần trăm HOẶC số tiền" của một dòng lịch thu (I5 — xem docblock lớp): điền phần trăm
     * thì ô số tiền bị khoá, không bắt buộc và KHÔNG gửi lên (số tiền tính lại từ phần trăm lúc
     * lưu); để trống phần trăm thì số tiền bắt buộc.
     *
     * **Ô số tiền bị khoá luôn TRỐNG** (lượt sửa thứ hai sau rà soát cuối M9, minor): điền phần
     * trăm thì số đã gõ trước đó bị xoá, và form "Sửa hợp đồng" không điền sẵn số tiền của dòng
     * theo phần trăm ({@see BillingRelationManager::updateDraftContractAction()}). Ô khoá chỉ còn dòng gợi ý "tự tính
     * từ phần trăm"; con số thật — tính lại mỗi lần giá trị hay phần trăm đổi — nằm ở khung xem
     * trước, đúng số sẽ lưu. Một con số cũ trong ô khoá sẽ nói ngược với cả hai.
     *
     * @param  (Closure(Get): bool)|null  $visible  điều kiện hiện của CẢ HAI ô (dòng phụ lục chỉ hiện chúng khi thêm/sửa)
     * @return array<int, TextInput>
     */
    public static function percentOrAmountFields(?Closure $visible, string $percentHelp): array
    {
        $isVisible = fn (Get $get): bool => $visible === null || $visible($get);
        $byPercent = fn (Get $get): bool => filled($get('percent_basis'));

        return [
            TextInput::make('percent_basis')
                ->label(__('billing.tab.fields.instalment_percent_basis'))
                ->helperText($percentHelp)
                ->maxLength(6)
                ->live(onBlur: true)
                ->afterStateUpdated(function (Set $set, mixed $state): void {
                    if (filled($state)) {
                        $set('amount', null);
                    }
                })
                ->visible($isVisible),
            TextInput::make('amount')
                ->label(__('billing.tab.fields.instalment_amount'))
                ->maxLength(15)
                ->live(onBlur: true)
                ->required(fn (Get $get): bool => ! $byPercent($get))
                ->disabled($byPercent)
                ->placeholder(fn (Get $get): ?string => $byPercent($get) ? __('billing.tab.fields.instalment_amount_from_percent') : null)
                ->visible($isVisible),
        ];
    }

    /**
     * Khung xem trước của form soạn/sửa bản nháp (I5): ba con số VAT của tổng, số tiền từng đợt
     * (tính từ phần trăm bằng {@see SplitByPercent::amountsForRows()}, hay số đã gõ), và tổng các
     * đợt so với giá trị hợp đồng. Chỉ HIỂN THỊ — không ghi gì, không thay bất biến tổng của Action.
     *
     * @param  array<array-key, mixed>  $rows
     */
    public static function schedulePreview(mixed $totalInput, mixed $vatInput, array $rows): HtmlString
    {
        $total = self::tryParseMoney($totalInput);

        if ($total === null) {
            return new HtmlString('<em>'.e(__('billing.tab.preview.enter_total')).'</em>');
        }

        $rows = array_values(array_filter($rows, 'is_array'));
        $lines = [e(self::vatPreviewLine($total, $vatInput))];

        try {
            $derived = SplitByPercent::amountsForRows($total, array_map(fn (array $row): mixed => $row['percent_basis'] ?? null, $rows));
        } catch (ValidationException) {
            return new HtmlString(implode('<br/>', [...$lines, e(__('billing.tab.preview.percent_invalid'))]));
        }

        $sum = 0;
        $complete = true;

        foreach ($rows as $index => $row) {
            $amount = $derived[$index] ?? self::tryParseMoney($row['amount'] ?? null);
            $complete = $complete && $amount !== null;
            $sum += $amount ?? 0;
            $lines[] = e(self::previewRowLine(
                __('billing.tab.preview.row_label_instalment', ['number' => $index + 1]),
                (string) ($row['name'] ?? ''),
                $row['percent_basis'] ?? null,
                $amount,
            ));
        }

        if ($rows === []) {
            $lines[] = e(__('billing.tab.preview.no_instalments', ['total' => Money::format($total)]));
        }

        if ($rows !== [] && $complete) {
            $lines[] = e($sum === $total
                ? __('billing.tab.preview.sum_matches', ['sum' => Money::format($sum)])
                : __('billing.tab.preview.sum_differs', ['sum' => Money::format($sum), 'total' => Money::format($total), 'difference' => Money::format(abs($total - $sum))]));
        }

        return new HtmlString(implode('<br/>', $lines));
    }

    /** Ba con số VAT của một tổng (qua {@see Vat}), hoặc câu "không có dòng thuế". */
    public static function vatPreviewLine(int $total, mixed $vatInput): string
    {
        $rate = is_numeric($vatInput) && (int) $vatInput >= 0 && (int) $vatInput <= 100 ? (int) $vatInput : null;

        if ($rate === null) {
            return __('billing.tab.preview.vat_none_line', ['total' => Money::format($total)]);
        }

        return __('billing.tab.preview.vat_line', [
            'total' => Money::format($total),
            'rate' => $rate,
            'tax' => Money::format(Vat::tax($total, $rate)),
            'net' => Money::format(Vat::net($total, $rate)),
        ]);
    }

    public static function previewRowLine(string $label, string $name, mixed $percent, ?int $amount): string
    {
        $amountText = $amount === null ? __('billing.tab.preview.amount_missing') : Money::format($amount);

        return filled($percent)
            ? __('billing.tab.preview.row_by_percent', ['label' => $label, 'name' => $name, 'percent' => trim((string) $percent), 'amount' => $amountText])
            : __('billing.tab.preview.row_by_amount', ['label' => $label, 'name' => $name, 'amount' => $amountText]);
    }

    /** `null` khi ô trống hoặc chưa đọc được — khung xem trước không báo lỗi, chỉ chưa có gì để hiện. */
    public static function tryParseMoney(mixed $input): ?int
    {
        if (blank($input)) {
            return null;
        }

        try {
            return Money::parse((string) $input);
        } catch (ValidationException) {
            return null;
        }
    }

    /**
     * Luật "ký lại sau khi huỷ" kèm SỐ đã thu trên hợp đồng đã huỷ — `null` khi hợp đồng hiện hành của
     * vụ không phải bản đã huỷ (soạn hợp đồng đầu tiên thì không có gì để nói). MỘT câu cho cả modal
     * "Soạn hợp đồng mới" của tab ({@see BillingRelationManager}) lẫn modal "Nhập hợp đồng" của trang kế
     * toán ({@see ContractEntry}, vòng sửa 1 của làn kt, I1): người nhập lại hợp đồng giấy đủ giá trị mà
     * không thấy số này thì doanh thu bị đếm hai lần, Công nợ và cổng khách báo khách nợ nhiều hơn.
     */
    public static function redraftCollectedRule(Matter $matter): ?string
    {
        if ($matter->contract?->status !== ContractStatus::Cancelled) {
            return null;
        }

        return __('billing_corrections.redraft.collected_rule', [
            'collected' => Money::format(self::collectedOnCancelledContracts($matter)),
        ]);
    }

    /**
     * Tổng khoản thu CHƯA HUỶ trên các hợp đồng ĐÃ HUỶ của vụ — số mà {@see CollectedRevenue} vẫn
     * tính doanh thu nhưng cổng khách và Công nợ không còn đọc (cả hai chỉ theo hợp đồng hiện hành).
     * Modal soạn/nhập hợp đồng mới hiện số này để văn phòng chỉ ghi phần còn phải thu.
     *
     * Chỉ {@see self::redraftCollectedRule()} gọi hàm này, khi hợp đồng hiện hành đã huỷ — lúc đó MỌI
     * hợp đồng của vụ đều đã huỷ (A1: hợp đồng mới chỉ soạn được khi mọi bản trước đã huỷ), nên điều
     * kiện `cancelled` dưới đây là phòng xa, không đo riêng được qua màn hình. Chuyển nguyên từ
     * `BillingRelationManager` ở vòng sửa 1 của làn kt (không đổi truy vấn).
     */
    private static function collectedOnCancelledContracts(Matter $matter): int
    {
        return (int) Payment::query()
            ->whereHas('instalment.contract', fn (Builder $query) => $query
                ->where('matter_id', $matter->getKey())
                ->where('status', ContractStatus::Cancelled->value))
            ->whereNull('voided_at')
            ->sum('amount');
    }

    /** @return array<string, string> */
    public static function triggerOptions(): array
    {
        return collect(InstalmentTrigger::cases())
            ->mapWithKeys(fn (InstalmentTrigger $trigger): array => [$trigger->value => $trigger->label()])
            ->all();
    }

    /** Giai đoạn của loại vụ việc, TRỪ giai đoạn đầu (không thể gắn đợt vào đó — xem `ValidatesBillingInput`). */
    public static function stageOptions(Matter $matter): array
    {
        $firstKey = $matter->matterType->firstStage()?->key;

        return $matter->matterType->stages()
            ->get()
            ->reject(fn ($stage): bool => $stage->key === $firstKey)
            ->pluck('label', 'key')
            ->all();
    }

    public static function intOrNull(mixed $value): ?int
    {
        return blank($value) ? null : (int) $value;
    }

    /** Chuỗi phần trăm đã cắt hai đầu, hoặc `null` khi ô trống — "dòng này gõ số tiền". */
    public static function percentOrNull(mixed $value): ?string
    {
        return blank($value) ? null : trim((string) $value);
    }

    /**
     * Một danh sách dòng lịch thu từ form (`DraftContract`/`UpdateDraftContract`). Dòng có phần trăm
     * nhận số tiền TÍNH bằng {@see SplitByPercent::amountsForRows()} trên `$total` — cùng hàm của
     * khung xem trước, nên số được lưu là số người dùng đã thấy; ô số tiền của dòng đó bị khoá và
     * không được gửi lên, nếu có gửi cũng bị bỏ qua. Dòng không có phần trăm đi qua `Money::parse()`
     * (lỗi gắn `"{$prefix}.{i}.amount"`) và `percent_basis` để `null`. Phần còn lại (tên, loại kích
     * hoạt, ngày…) ĐỂ NGUYÊN cho chính Action kiểm qua
     * `ValidatesBillingInput::instalmentAttributes()`, không kiểm hai lần.
     *
     * @param  array<array-key, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function parsedInstalmentRows(array $rows, int $total, string $prefix): array
    {
        $rows = array_values($rows);
        $percents = array_map(fn (array $row): ?string => self::percentOrNull($row['percent_basis'] ?? null), $rows);
        $derived = SplitByPercent::amountsForRows($total, $percents, $prefix);

        return collect($rows)->map(fn (array $row, int $index): array => [
            ...$row,
            'amount' => $derived[$index] ?? Money::parse((string) ($row['amount'] ?? ''), "{$prefix}.{$index}.amount"),
            'percent_basis' => $percents[$index],
            'due_days_after_trigger' => self::intOrNull($row['due_days_after_trigger'] ?? null) ?? 0,
        ])->all();
    }

    /**
     * Trạng thái form "sửa bản nháp" điền từ một hợp đồng đã có. `Money::formatForInput()`, không
     * `Money::format()`: ô nhập nhận đúng dạng `Money::parse()` đọc được ("50.000.000"), không kèm
     * "₫" — nếu không, mở form rồi bấm lưu ngay mà không sửa gì cũng ra lỗi định dạng số tiền. Dòng
     * theo phần trăm KHÔNG điền sẵn số tiền: ô đó bị khoá, và số đã lưu thành số cũ ngay khi giá trị
     * hợp đồng đổi (xem {@see self::percentOrAmountFields()}).
     *
     * @return array<string, mixed>
     */
    public static function formState(Contract $contract): array
    {
        return [
            'total_amount' => Money::formatForInput($contract->total_amount),
            'vat_rate_percent' => $contract->vat_rate_percent,
            'instalments' => $contract->instalments->map(fn (Instalment $i): array => [
                'name' => $i->name,
                'amount' => $i->percent_basis === null ? Money::formatForInput($i->amount) : null,
                'percent_basis' => $i->percent_basis,
                'trigger_type' => $i->trigger_type->value,
                'trigger_stage_key' => $i->trigger_stage_key,
                'due_date' => $i->due_date?->toDateString(),
                'due_days_after_trigger' => $i->due_days_after_trigger,
                'note' => $i->note,
            ])->all(),
        ];
    }

    /**
     * Hai đối số `$attributes`, `$instalments` của {@see DraftContract::handle()} và
     * {@see UpdateDraftContract::handle()} từ dữ liệu form. Gọi BÊN TRONG `runAction()` của màn hình:
     * `Money::parse()` và {@see self::parsedInstalmentRows()} ném `ValidationException` gắn đúng ô.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array{total_amount: int, vat_rate_percent: int|null}, 1: list<array<string, mixed>>}
     */
    public static function actionArguments(array $data): array
    {
        $total = Money::parse((string) ($data['total_amount'] ?? ''), 'total_amount');

        return [
            ['total_amount' => $total, 'vat_rate_percent' => self::intOrNull($data['vat_rate_percent'] ?? null)],
            self::parsedInstalmentRows($data['instalments'] ?? [], $total, 'instalments'),
        ];
    }
}
