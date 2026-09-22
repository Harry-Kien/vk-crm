<?php

namespace App\Enums;

enum OutboundStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';

    public function label(): string
    {
        return __('enums.outbound_status.'.$this->value);
    }
}
