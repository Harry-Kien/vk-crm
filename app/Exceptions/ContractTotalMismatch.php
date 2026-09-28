<?php

namespace App\Exceptions;

use App\Models\Contract;
use App\Support\Billing\Money;
use DomainException;

/**
 * Bất biến tổng của M9 bị vi phạm: `SUM(instalments.amount)` của các đợt chưa huỷ khác
 * `contracts.total_amount` — số nguyên đồng, không dung sai. Ba tầng ném nó, mỗi tầng một câu
 * riêng, vì mỗi tầng bảo người dùng làm một việc khác:
 *
 * - {@see self::onActivation()} — `ActivateContract` (tầng 1): sửa lịch thu rồi kích hoạt lại.
 * - {@see self::onAmendment()} — `AmendContract` (tầng 3): giá trị và lịch thu phải đổi cùng nhau.
 * - {@see self::onWrite()} — hook của `Instalment`/`Contract` (tầng 2): một lần ghi đi vòng qua
 *   Action; giá trị và lịch thu của hợp đồng đã ký chỉ đổi bằng phụ lục.
 *
 * Câu khác nhau cũng là thứ cho mutation probe phân biệt được tầng nào đã chặn: bỏ kiểm tra của
 * `ActivateContract` thì hook vẫn chặn, nhưng bằng câu của hook, và test của tầng 1 đỏ.
 */
class ContractTotalMismatch extends DomainException
{
    public static function onActivation(Contract $contract, int $scheduleTotal): self
    {
        return new self(__('billing.errors.total_mismatch_on_activation', self::numbers($contract->code, $contract->total_amount, $scheduleTotal)));
    }

    public static function onAmendment(Contract $contract, int $newTotal, int $scheduleTotal): self
    {
        return new self(__('billing.errors.total_mismatch_on_amendment', self::numbers($contract->code, $newTotal, $scheduleTotal)));
    }

    public static function onWrite(Contract $contract, int $scheduleTotal): self
    {
        return new self(__('billing.errors.total_mismatch_on_write', self::numbers($contract->code, $contract->total_amount, $scheduleTotal)));
    }

    /** @return array<string, string> */
    private static function numbers(string $code, int $total, int $scheduleTotal): array
    {
        return [
            'code' => $code,
            'total' => Money::format($total),
            'schedule' => Money::format($scheduleTotal),
            'difference' => Money::format(abs($total - $scheduleTotal)),
        ];
    }
}
