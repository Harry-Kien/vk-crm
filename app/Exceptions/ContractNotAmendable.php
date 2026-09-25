<?php

namespace App\Exceptions;

use App\Models\Contract;
use DomainException;

/**
 * Phụ lục chỉ ký được trên hợp đồng `active` (M9 Task 4). Bản nháp thì sửa thẳng lịch thu (đợt
 * của hợp đồng `draft` xoá cứng được); hợp đồng đã `completed`/`cancelled` thì đã đóng.
 */
class ContractNotAmendable extends DomainException
{
    public static function make(Contract $contract): self
    {
        return new self(__('billing.errors.contract_not_amendable', [
            'code' => $contract->code,
            'status' => $contract->status->label(),
        ]));
    }
}
