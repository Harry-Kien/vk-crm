<?php

namespace App\Exceptions;

use App\Models\MatterType;
use DomainException;

class DuplicateStageKey extends DomainException
{
    public static function make(MatterType $matterType, string $key): self
    {
        return new self(__('exceptions.duplicate_stage_key', ['name' => $matterType->name, 'key' => $key]));
    }
}
