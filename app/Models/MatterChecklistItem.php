<?php

namespace App\Models;

use App\Enums\ChecklistItemStatus;
use Database\Factories\MatterChecklistItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MatterChecklistItem extends Model
{
    /** @use HasFactory<MatterChecklistItemFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'matter_id', 'name', 'description', 'is_required', 'sort_order',
        'status', 'rejection_reason', 'reviewed_by', 'reviewed_at',
    ];

    protected $attributes = ['status' => 'missing'];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'sort_order' => 'integer',
            'status' => ChecklistItemStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'matter_checklist_item_id');
    }
}
