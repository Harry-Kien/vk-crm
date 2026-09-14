<?php

namespace App\Enums;

enum DeadlineSeverity: string
{
    case Normal = 'normal';
    case Critical = 'critical';

    public function label(): string
    {
        return __('enums.deadline_severity.'.$this->value);
    }
}
