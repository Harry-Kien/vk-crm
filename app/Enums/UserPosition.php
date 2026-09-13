<?php

namespace App\Enums;

enum UserPosition: string
{
    case Lawyer = 'lawyer';
    case Assistant = 'assistant';
    case Accountant = 'accountant';
    case Manager = 'manager';
    case Admin = 'admin';

    public function label(): string
    {
        return __('enums.user_position.'.$this->value);
    }
}
