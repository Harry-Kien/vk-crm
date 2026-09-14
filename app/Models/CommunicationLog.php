<?php

namespace App\Models;

use App\Enums\CommunicationType;
use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\RestrictedToClientPortal;
use Database\Factories\CommunicationLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CommunicationLog extends Model
{
    use HasBlameable;

    /** @use HasFactory<CommunicationLogFactory> */
    use HasFactory;

    use RestrictedToClientPortal;
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

    /** Nhật ký liên lạc mặc định là nội bộ; chỉ dòng được đánh dấu mới ra portal (SPEC §4.17). */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->where($this->qualifyColumn('is_visible_to_client'), true)->whereHas('matter');
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
