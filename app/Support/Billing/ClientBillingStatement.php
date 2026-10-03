<?php

namespace App\Support\Billing;

use App\Enums\ContractStatus;
use App\Enums\InstalmentState;
use App\Enums\InstalmentTrigger;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use Illuminate\Support\Collection;

/**
 * Hình chiếu HẸP của hợp đồng và lịch thu mà KHÁCH được đọc (M9 Task 10, P1) — một chỗ cho hai bề
 * mặt: khối "Hợp đồng và thanh toán" trên trang tiến độ của cổng
 * (`App\Filament\Portal\Pages\MatterProgress::billing()`) và mục "Bảng kê thanh toán" trong
 * `MUC-LUC.pdf` của gói bàn giao (`App\Actions\Matter\RenderHandoverIndex::billingStatement()`).
 * Cùng dữ liệu thì hai nơi cho ĐÚNG cùng một mảng — có test so sánh bằng `toBe`.
 *
 * **Không lọc gì.** Nơi gọi đưa vào những bản ghi ĐÃ lọc: trang cổng qua scope cổng cộng một lần
 * `Gate` trên từng bản ghi; gói bàn giao (chạy trong job, không có phiên cổng) qua các scope
 * `shownToClient()` của `Contract`/`Instalment`/`Payment`. Hàm này chỉ chọn TRƯỜNG.
 *
 * **Các trường, và chỉ chúng** (P1): số hợp đồng, tổng giá trị, thuế suất, ngày ký, ngày hoàn tất;
 * mỗi đợt — tên, số tiền, đến hạn khi nào, đã thanh toán, còn lại, trạng thái; mỗi khoản đã nhận —
 * ngày, số tiền, cách trả. Không ghi chú, không lý do miễn/huỷ/phụ lục, không người ghi, không mã
 * giao dịch, không biên lai, không phần trăm người soạn đã gõ, không id. Mọi giá trị là chuỗi đã
 * định dạng (tiền qua {@see Money::format()}) hoặc `null` (không có thuế suất, chưa hoàn tất, dòng
 * không mang trạng thái), nên không một kiểu dữ liệu nào của model đi ra.
 *
 * Trạng thái của đợt đọc {@see Instalment::state()} (một định nghĩa), còn lại đọc
 * {@see Instalment::outstanding()} — không tự tính lại "quá hạn" hay "còn nợ".
 */
final class ClientBillingStatement
{
    /**
     * @param  Collection<int, Instalment>  $instalments  đợt khách được thấy, theo `sequence`
     * @param  Collection<int, Payment>  $payments  khoản thu khách được thấy, của chính các đợt trên, theo ngày
     * @return array{code: string, total: string, vat: ?string, signed_on: ?string, completed_on: ?string, instalments: list<array{name: string, amount: string, due: string, collected: string, outstanding: string, state: ?string, state_label: ?string}>, payments: list<array{paid_on: string, amount: string, method: string}>}
     */
    public static function present(Matter $matter, Contract $contract, Collection $instalments, Collection $payments): array
    {
        $collected = $payments
            ->groupBy('instalment_id')
            ->map(fn (Collection $rows): int => (int) $rows->sum('amount'));

        return [
            'code' => (string) $contract->code,
            'total' => Money::format($contract->total_amount),
            'vat' => $contract->vat_rate_percent === null
                ? null
                : __('portal_progress.billing.vat', ['rate' => $contract->vat_rate_percent]),
            'signed_on' => $contract->signed_at?->format('d/m/Y'),
            'completed_on' => $contract->status === ContractStatus::Completed
                ? $contract->ended_at?->format('d/m/Y')
                : null,
            'instalments' => $instalments
                ->map(fn (Instalment $instalment): array => self::instalment(
                    $matter,
                    $contract,
                    $instalment,
                    $collected->get($instalment->getKey(), 0),
                ))
                ->values()
                ->all(),
            'payments' => $payments
                ->map(fn (Payment $payment): array => [
                    'paid_on' => $payment->paid_on->format('d/m/Y'),
                    'amount' => Money::format($payment->amount),
                    'method' => __('portal_progress.billing.method.'.$payment->method->value),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Một đợt. "Đã thanh toán" là tổng của CHÍNH những khoản thu khách thấy trong danh sách bên dưới
     * (chưa huỷ) — cùng tập với SUM mà `Instalment` tự đọc, và đưa cho `state()` qua lối vào
     * `collected_amount` mà `Instalment::collectedForState()` để sẵn, để trạng thái và con số trên
     * cùng một dòng đọc cùng một tổng. Thuộc tính đó không phải một cột: các bản ghi đi qua đây
     * không bao giờ được lưu lại.
     *
     * @return array{name: string, amount: string, due: string, collected: string, outstanding: string, state: ?string, state_label: ?string}
     */
    private static function instalment(Matter $matter, Contract $contract, Instalment $instalment, int $collected): array
    {
        $instalment->setRelation('contract', $contract);
        $instalment->setAttribute('collected_amount', $collected);

        $state = self::state($contract, $instalment);

        return [
            'name' => (string) $instalment->name,
            'amount' => Money::format($instalment->amount),
            'due' => self::due($matter, $instalment),
            'collected' => Money::format($collected),
            'outstanding' => Money::format($instalment->outstanding()),
            'state' => $state?->value,
            'state_label' => $state === null ? null : __('portal_progress.billing.state.'.$state->value),
        ];
    }

    /**
     * Trạng thái của ĐỢT chỉ khi hợp đồng `active`. Hợp đồng `completed` thì trạng thái của HỢP ĐỒNG
     * thay cho nó (luật I4 ở docblock `Instalment::state()`: `state()` không đọc hợp đồng cha) — khối
     * in "Hợp đồng đã hoàn tất ngày …" — và một dòng chỉ còn nói "Văn phòng đã miễn" khi đúng là
     * vậy. Nhờ thế một đợt của hợp đồng đã hoàn tất không bao giờ hiện "quá hạn" bên cạnh "còn lại
     * 0 ₫" (`outstanding()` trả 0 cho hợp đồng không `active`).
     */
    private static function state(Contract $contract, Instalment $instalment): ?InstalmentState
    {
        $state = $instalment->state();

        if ($contract->status === ContractStatus::Active) {
            return $state;
        }

        return $state === InstalmentState::Waived ? $state : null;
    }

    /**
     * "Đến hạn khi nào". Có `due_date` thì nói ngày — kể cả đợt theo tiến độ đã tới bước của nó. Đợt
     * theo tiến độ CHƯA tới bước nói tên bước bằng `client_label` (nhãn cho khách, như mọi nhãn giai
     * đoạn trên cổng và trong mục lục), không bao giờ `label` nội bộ hay `trigger_stage_key` thô.
     */
    private static function due(Matter $matter, Instalment $instalment): string
    {
        if ($instalment->due_date !== null) {
            return __('portal_progress.billing.due.on', ['date' => $instalment->due_date->format('d/m/Y')]);
        }

        return match ($instalment->trigger_type) {
            InstalmentTrigger::Stage => self::stageDue($matter, $instalment),
            InstalmentTrigger::OnSigning => __('portal_progress.billing.due.on_signing'),
            InstalmentTrigger::DueDate => __('portal_progress.billing.due.unscheduled'),
        };
    }

    /**
     * Giai đoạn đã xoá mềm vẫn có nhãn (`stageIncludingTrashed()`, cùng đường với
     * `MatterProgress::stageLabel()`). Không tra được nhãn cho khách thì nói chung chung — in khoá
     * thô là đưa định danh nội bộ ra trước mặt khách (SPEC §8).
     */
    private static function stageDue(Matter $matter, Instalment $instalment): string
    {
        $label = blank($instalment->trigger_stage_key)
            ? null
            : $matter->matterType?->stageIncludingTrashed($instalment->trigger_stage_key)?->client_label;

        if (blank($label)) {
            return __('portal_progress.billing.due.stage_unnamed');
        }

        return $instalment->due_days_after_trigger > 0
            ? __('portal_progress.billing.due.stage_after', ['days' => $instalment->due_days_after_trigger, 'stage' => $label])
            : __('portal_progress.billing.due.stage', ['stage' => $label]);
    }
}
