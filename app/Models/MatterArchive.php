<?php

namespace App\Models;

use Database\Factories\MatterArchiveFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MatterArchive extends Model
{
    /** @use HasFactory<MatterArchiveFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'matter_id', 'archived_at', 'archived_by', 'handover_package_path', 'handover_generated_at',
        'client_access_until', 'retention_until', 'destroyed_at',
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
            'handover_generated_at' => 'datetime',
            'client_access_until' => 'date',
            'retention_until' => 'date',
            'destroyed_at' => 'datetime',
        ];
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }
}
