<?php

namespace App\Models;

use App\Exceptions\StageLogImmutable;
use App\Models\Concerns\HasBlameable;
use Database\Factories\StageLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StageLog extends Model
{
    use HasBlameable;

    /** @use HasFactory<StageLogFactory> */
    use HasFactory;

    /** Các cột được phép đổi sau khi ghi: chỉ trạng thái công bố và thông báo. */
    public const MUTABLE = ['is_published', 'published_at', 'notified_at', 'updated_by', 'updated_at'];

    protected $fillable = [
        'matter_id', 'from_stage', 'to_stage', 'occurred_at', 'internal_note', 'public_content',
        'next_step', 'client_action', 'expected_next_update_at', 'is_published', 'published_at', 'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'expected_next_update_at' => 'date',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (StageLog $log): void {
            $illegal = array_diff(array_keys($log->getDirty()), self::MUTABLE);

            if ($illegal !== []) {
                throw StageLogImmutable::make();
            }
        });

        static::deleting(function (): void {
            throw StageLogImmutable::make();
        });
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function views(): HasMany
    {
        return $this->hasMany(StageLogView::class);
    }
}
