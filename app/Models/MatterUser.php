<?php

namespace App\Models;

use App\Enums\MatterRole;
use Illuminate\Database\Eloquent\Relations\Pivot;

class MatterUser extends Pivot
{
    protected $table = 'matter_user';

    public $incrementing = true;

    protected function casts(): array
    {
        return ['role_in_matter' => MatterRole::class];
    }
}
