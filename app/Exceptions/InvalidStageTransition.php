<?php

namespace App\Exceptions;

use App\Models\Matter;
use DomainException;

/**
 * `to_stage` không nằm trong `allowed_next` của giai đoạn hiện tại, hoặc không ứng với một
 * giai đoạn nào được cấu hình cho loại vụ việc (SPEC §6.2 bước 1). Vai trò `admin` được phép bỏ
 * qua vế `allowed_next`, nhưng KHÔNG bỏ qua việc `to_stage` phải là một giai đoạn có thật.
 */
class InvalidStageTransition extends DomainException
{
    public static function make(Matter $matter, string $fromStage, string $toStage): self
    {
        return new self(__('exceptions.invalid_stage_transition', [
            'code' => $matter->code,
            'from' => $fromStage,
            'to' => $toStage,
        ]));
    }
}
