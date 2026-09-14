<?php

namespace App\Enums;

enum Confidentiality: string
{
    case Normal = 'normal';
    case Restricted = 'restricted';

    public function label(): string
    {
        return __('enums.confidentiality.'.$this->value);
    }
}
