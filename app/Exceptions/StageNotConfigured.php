<?php

namespace App\Exceptions;

use App\Models\MatterType;
use DomainException;

class StageNotConfigured extends DomainException
{
    public static function make(MatterType $matterType): self
    {
        return new self(__('exceptions.stage_not_configured', ['name' => $matterType->name]));
    }
}
