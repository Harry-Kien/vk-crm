<?php

namespace App\Enums;

enum UserPosition: string
{
    case Lawyer = 'lawyer';
    case Assistant = 'assistant';
    case Accountant = 'accountant';
    case Manager = 'manager';
    case Admin = 'admin';
    // Làn kd (2026-10-14): nhân viên kinh doanh — ánh xạ sang `Role::Sales`.
    case Sales = 'sales';

    public function label(): string
    {
        return __('enums.user_position.'.$this->value);
    }
}
