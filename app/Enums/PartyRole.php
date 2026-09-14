<?php

namespace App\Enums;

enum PartyRole: string
{
    case Plaintiff = 'plaintiff';
    case Defendant = 'defendant';
    case Related = 'related';
    case ThirdParty = 'third_party';
    case OpposingCounsel = 'opposing_counsel';

    public function label(): string
    {
        return __('enums.party_role.'.$this->value);
    }
}
