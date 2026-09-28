<?php

namespace App\Exceptions;

use App\Enums\BillingModel;
use DomainException;

/**
 * M9 chỉ cài `fixed_fee` (giá trị thoả thuận một lần). `hourly`/`mixed` có trong enum để cột
 * không phải đổi kiểu về sau, nhưng chưa có gì tính được chúng — `time_entries` chỉ là khung
 * (M9 Task 12) — nên `DraftContract` từ chối thay vì ghi một hợp đồng không ai thu được.
 */
class BillingModelNotSupported extends DomainException
{
    public static function make(BillingModel $model): self
    {
        return new self(__('billing.errors.billing_model_not_supported', ['model' => $model->label()]));
    }
}
