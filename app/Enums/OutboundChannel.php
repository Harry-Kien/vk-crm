<?php

namespace App\Enums;

enum OutboundChannel: string
{
    case Email = 'email';
    case Zns = 'zns';
    case Sms = 'sms';

    public function label(): string
    {
        return __('enums.outbound_channel.'.$this->value);
    }
}
