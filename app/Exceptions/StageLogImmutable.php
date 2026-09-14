<?php

namespace App\Exceptions;

use DomainException;

class StageLogImmutable extends DomainException
{
    public static function make(): self
    {
        return new self(__('exceptions.stage_log_immutable'));
    }
}
