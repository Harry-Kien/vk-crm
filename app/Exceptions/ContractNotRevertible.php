<?php

namespace App\Exceptions;

use App\Actions\Billing\RevertContractToDraft;
use App\Models\Contract;
use App\Models\Matter;
use App\Support\Billing\Money;
use DomainException;

/**
 * Làn fa3 (2026-10-16, mục 2): lời từ chối của {@see RevertContractToDraft} — một hợp đồng đang hiệu lực
 * chỉ đưa về bản nháp được khi chưa có gì xảy ra trên nó ngoài việc kích hoạt. Mỗi câu nói vì sao và
 * phải làm gì thay; `ReportsActionFailures` đưa câu lên thông báo (một `DomainException`).
 */
class ContractNotRevertible extends DomainException
{
    /**
     * Fix round 1 (m5): hồ sơ đã kết thúc — hợp đồng của nó là sổ sách đã chốt (công nợ "Đã kết thúc còn công
     * nợ", thư nhắc); sửa con số bằng phụ lục, hoặc huỷ hợp đồng.
     */
    public static function matterClosed(Contract $contract, Matter $matter): self
    {
        return new self(__('billing_corrections.revert.matter_closed', [
            'code' => $contract->code,
            'matter' => $matter->code,
        ]));
    }

    /** Còn khoản thu chưa huỷ: tiền đã về gắn với hợp đồng đã ký — huỷ khoản thu (ghi lý do) trước. */
    public static function hasPayments(Contract $contract, int $amount): self
    {
        return new self(__('billing_corrections.revert.has_payments', [
            'code' => $contract->code,
            'amount' => Money::format($amount),
        ]));
    }

    /** Đã ký phụ lục: phụ lục là một văn bản thật khách đã ký trên hợp đồng này. */
    public static function hasAmendments(Contract $contract): self
    {
        return new self(__('billing_corrections.revert.has_amendments', ['code' => $contract->code]));
    }

    /** Có đợt từng được miễn hay dời hạn: đó là quyết định đã ghi nhật ký trên hợp đồng đã ký. */
    public static function hasCorrections(Contract $contract, string $instalmentName): self
    {
        return new self(__('billing_corrections.revert.has_corrections', [
            'code' => $contract->code,
            'name' => $instalmentName,
        ]));
    }
}
