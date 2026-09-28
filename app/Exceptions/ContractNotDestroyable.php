<?php

namespace App\Exceptions;

use DomainException;

/**
 * Chặn xoá cứng một hợp đồng (`Contract::booted()`). Quyết định 4 của M9: `contracts` không có
 * `deleted_at` — `matter_id` là unique thật, và một hợp đồng xoá mềm rồi tạo lại đúng là lỗ hổng
 * dự án đã vấp hai lần (`matter_type_stages.key`, `MatterType.code`). Nên chỉ có MỘT cửa xoá:
 * xoá cứng, và chỉ khi còn `draft` và chưa có khoản thu nào — cùng họ với `MatterNotDestroyable`.
 */
class ContractNotDestroyable extends DomainException
{
    public static function notDraft(): self
    {
        return new self(__('exceptions.contract_not_destroyable_not_draft'));
    }

    public static function hasPayments(): self
    {
        return new self(__('exceptions.contract_not_destroyable_has_payments'));
    }
}
