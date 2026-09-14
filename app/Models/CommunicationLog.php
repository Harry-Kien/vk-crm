<?php

namespace App\Models;

use App\Enums\CommunicationType;
use App\Models\Concerns\HasBlameable;
use Database\Factories\CommunicationLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CommunicationLog extends Model
{
    use HasBlameable;

    /** @use HasFactory<CommunicationLogFactory> */
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'matter_id', 'type', 'occurred_at', 'duration_minutes', 'counterpart', 'summary', 'is_visible_to_client',
    ];

    protected function casts(): array
    {
        return [
            'type' => CommunicationType::class,
            'occurred_at' => 'datetime',
            'duration_minutes' => 'integer',
            'is_visible_to_client' => 'boolean',
        ];
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
