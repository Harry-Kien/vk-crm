<?php

namespace App\Enums;

enum MatterRole: string
{
    case Lead = 'lead';
    case Associate = 'associate';
    case Assistant = 'assistant';
    case Observer = 'observer';

    public function label(): string
    {
        return __('enums.matter_role.'.$this->value);
    }
}
