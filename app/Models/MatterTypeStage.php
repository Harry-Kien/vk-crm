<?php

namespace App\Models;

use Database\Factories\MatterTypeStageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MatterTypeStage extends Model
{
    /** @use HasFactory<MatterTypeStageFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'matter_type_id', 'key', 'label', 'client_label', 'client_description',
        'sort_order', 'is_terminal', 'allowed_next', 'default_next_update_days',
    ];

    protected function casts(): array
    {
        return [
            'is_terminal' => 'boolean',
            'allowed_next' => 'array',
            'sort_order' => 'integer',
            'default_next_update_days' => 'integer',
        ];
    }

    public function matterType(): BelongsTo
    {
        return $this->belongsTo(MatterType::class);
    }

    public function allows(string $nextKey): bool
    {
        return in_array($nextKey, $this->allowed_next ?? [], true);
    }
}
