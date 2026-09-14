<?php

namespace App\Enums;

enum ClientRequestStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Answered = 'answered';
    case Closed = 'closed';

    public function label(): string
    {
        return __('enums.client_request_status.'.$this->value);
    }
}
