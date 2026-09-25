<?php

namespace App\Enums;

/**
 * Cách một đợt thanh toán biết ngày đến hạn (`instalments.trigger_type`) — ba nhánh, không hai
 * (quyết định 2 của M9): `on_signing` (tạm ứng khi ký, chưa biết ngày lúc soạn lịch thu),
 * `due_date` (một ngày cụ thể do văn phòng nhập), `stage` (chạm tới một giai đoạn của ĐÚNG loại
 * vụ việc đó — không được là giai đoạn đầu, và không phải một FK tới `matter_type_stages`, chỉ
 * là `trigger_stage_key`).
 */
enum InstalmentTrigger: string
{
    case OnSigning = 'on_signing';
    case DueDate = 'due_date';
    case Stage = 'stage';

    public function label(): string
    {
        return __('enums.instalment_trigger.'.$this->value);
    }
}
