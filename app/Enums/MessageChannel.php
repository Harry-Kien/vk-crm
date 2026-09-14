<?php

namespace App\Enums;

enum MessageChannel: string
{
    case Email = 'email';
    case Zns = 'zns';
    case Sms = 'sms';

    public function label(): string
    {
        return __('enums.message_channel.'.$this->value);
    }
}
