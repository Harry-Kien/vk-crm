<?php

namespace App\Enums;

enum CommunicationType: string
{
    case CallIn = 'call_in';
    case CallOut = 'call_out';
    case Meeting = 'meeting';
    case Email = 'email';
    case Letter = 'letter';
    case CourtVisit = 'court_visit';

    public function label(): string
    {
        return __('enums.communication_type.'.$this->value);
    }
}
