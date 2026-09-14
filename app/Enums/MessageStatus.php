<?php

namespace App\Enums;

enum MessageStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';

    public function label(): string
    {
        return __('enums.message_status.'.$this->value);
    }
}
