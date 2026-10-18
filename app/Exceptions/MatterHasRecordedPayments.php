<?php

namespace App\Exceptions;

use App\Models\Matter;
use App\Support\Billing\BillingSummary;
use App\Support\Billing\Money;
use DomainException;

/**
 * Làn fa3, fix round 1 (m9 của rà soát Task 1) — phòng thủ chiều sâu: hook `Matter::deleting` chặn xoá mềm
 * một vụ việc còn khoản thu CHƯA HUỶ trên bất kỳ hợp đồng nào ({@see BillingSummary::collectedForMatter()}),
 * cùng luật mà `App\Actions\Matter\CancelMatter` đã hỏi trước (và trả lời bằng `ValidationException` trên ô
 * lý do — Action đó không bao giờ để lớp này nổ lên màn hình). Hôm nay `CancelMatter` là đường xoá mềm duy
 * nhất; hook giữ luật cho mọi đường khác sau này. Hook hỏi dư nợ TRƯỚC ({@see MatterHasOutstandingBalance}),
 * rồi mới tới lớp này.
 */
class MatterHasRecordedPayments extends DomainException
{
    public static function make(Matter $matter, int $amount, int $count): self
    {
        return new self(__('exceptions.matter_has_recorded_payments', [
            'code' => $matter->code,
            'count' => $count,
            'amount' => Money::format($amount),
        ]));
    }
}
