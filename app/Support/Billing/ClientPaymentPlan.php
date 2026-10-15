<?php

namespace App\Support\Billing;

use App\Enums\InstalmentStatus;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use Illuminate\Support\Collection;

/**
 * Hình chiếu HẸP của phần "Lộ trình thanh toán" mà khách đọc trên trang hồ sơ của cổng (làn nt Task 1),
 * chỉ khi công tắc {@see ClientPaymentSettings} bật — nơi gọi:
 * `App\Filament\Portal\Pages\MatterProgress::paymentPlan()`.
 *
 * **Không lọc gì.** Nơi gọi đưa vào ĐÚNG những bản ghi khối "Hợp đồng và thanh toán" của M9 đã lọc qua
 * ba tầng của cổng (scope cổng, rồi `Gate` trên từng bản ghi): hợp đồng đã ký (hiệu lực hay hoàn tất —
 * không nháp, không huỷ), đợt chưa huỷ, khoản thu chưa huỷ. Hàm này chỉ cộng và chọn TRƯỜNG.
 *
 * **Các con số** (cộng lại đúng bằng giá trị hợp đồng khi lịch thu cân với hợp đồng):
 *  - giá trị hợp đồng = `total_amount` HIỆN HÀNH (mỗi phụ lục đã ghi lại cột này — `AmendContract`);
 *  - đã thanh toán = tổng khoản thu khách thấy (chưa huỷ);
 *  - đã miễn = phần chưa thu của các đợt `waived` (`null` khi không có, để view không in "0 ₫");
 *  - còn phải thanh toán = tổng {@see Instalment::outstanding()} của các đợt — MỘT định nghĩa "còn nợ"
 *    (hợp đồng không `active` thì 0), không tính lại ở đây.
 *
 * **Đợt cần thanh toán** (`payables`): đợt có `due_date` (đã tới hạn hoặc sắp tới hạn — kể cả đợt theo
 * giai đoạn khi vụ đã chạm bước của nó) và còn phải trả > 0, theo thứ tự lịch thu. Đợt theo giai đoạn
 * CHƯA tới bước không có ngày hạn nên không có nội dung chuyển khoản hay mã QR: khách chưa phải trả nó.
 * Mỗi đợt: tên, số tiền còn phải trả, ngày hạn, trạng thái ({@see Instalment::state()}), nội dung chuyển
 * khoản gợi ý ({@see VietQr::transferNote()}) và — khi có tài khoản nhận — ảnh mã QR VietQR của ĐÚNG số
 * tiền còn phải trả và nội dung đó.
 *
 * Thông tin chuyển khoản (`transfer`) chỉ đi ra khi có đợt cần thanh toán và tài khoản nhận đủ
 * ({@see ClientPaymentSettings::transferAccount()}); hợp đồng đã trả đủ hay đã hoàn tất thì không in số
 * tài khoản. Mọi giá trị là chuỗi đã định dạng, `bool` hoặc `null` — không model, không id, không ghi
 * chú hay lý do nội bộ.
 */
final class ClientPaymentPlan
{
    /**
     * @param  Collection<int, Instalment>  $instalments  đợt khách được thấy, theo `sequence`
     * @param  Collection<int, Payment>  $payments  khoản thu khách được thấy, của chính các đợt trên
     * @param  array{bank_bin: string, bank_name: string, account_number: string, account_holder: string, bank_branch: ?string}|null  $account
     * @return array{contract_value: string, collected: string, waived: ?string, outstanding: string, settled: bool, transfer: ?array{bank: string, account_number: string, account_holder: string, bank_branch: ?string}, payables: list<array{name: string, amount: string, due: string, state: string, state_label: string, note: string, qr: ?string}>}
     */
    public static function present(Matter $matter, Contract $contract, Collection $instalments, Collection $payments, ?array $account): array
    {
        $collectedBy = $payments
            ->groupBy('instalment_id')
            ->map(fn (Collection $rows): int => (int) $rows->sum('amount'));

        $collected = (int) $payments->sum('amount');
        $waived = 0;
        $outstanding = 0;
        $payables = [];

        foreach ($instalments as $instalment) {
            $instalment->setRelation('contract', $contract);
            $instalment->setAttribute('collected_amount', $collectedBy->get($instalment->getKey(), 0));

            if ($instalment->status === InstalmentStatus::Waived) {
                $waived += max(0, $instalment->amount - $collectedBy->get($instalment->getKey(), 0));
            }

            $left = $instalment->outstanding();
            $outstanding += $left;

            if ($left > 0 && $instalment->due_date !== null) {
                $payables[] = self::payable($matter, $instalment, $left, $account);
            }
        }

        return [
            'contract_value' => Money::format($contract->total_amount),
            'collected' => Money::format($collected),
            'waived' => $waived > 0 ? Money::format($waived) : null,
            'outstanding' => Money::format($outstanding),
            'settled' => $outstanding === 0,
            'transfer' => $payables !== [] && $account !== null ? [
                'bank' => $account['bank_name'],
                'account_number' => $account['account_number'],
                'account_holder' => $account['account_holder'],
                'bank_branch' => $account['bank_branch'],
            ] : null,
            'payables' => $payables,
        ];
    }

    /**
     * @param  array{bank_bin: string, account_number: string}|null  $account
     * @return array{name: string, amount: string, due: string, state: string, state_label: string, note: string, qr: ?string}
     */
    private static function payable(Matter $matter, Instalment $instalment, int $left, ?array $account): array
    {
        $state = $instalment->state();
        $note = VietQr::transferNote((string) $matter->code, (int) $instalment->sequence);

        return [
            'name' => (string) $instalment->name,
            'amount' => Money::format($left),
            'due' => __('portal_progress.billing.due.on', ['date' => $instalment->due_date->format('d/m/Y')]),
            'state' => $state->value,
            'state_label' => __('portal_progress.billing.state.'.$state->value),
            'note' => $note,
            'qr' => $account === null ? null : VietQr::svgDataUri(VietQr::payload($account['bank_bin'], $account['account_number'], $left, $note)),
        ];
    }
}
