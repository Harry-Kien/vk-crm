<?php

namespace App\Enums;

enum ClientType: string
{
    case Individual = 'individual';
    case Organization = 'organization';

    public function label(): string
    {
        return __('enums.client_type.'.$this->value);
    }
}
