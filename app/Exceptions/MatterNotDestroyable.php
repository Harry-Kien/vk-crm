<?php

namespace App\Exceptions;

use DomainException;

class MatterNotDestroyable extends DomainException
{
    public static function make(): self
    {
        return new self(__('exceptions.matter_not_destroyable'));
    }
}
