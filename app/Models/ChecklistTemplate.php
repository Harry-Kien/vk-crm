<?php

namespace App\Models;

use App\Models\Concerns\HasBlameable;
use Database\Factories\ChecklistTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChecklistTemplate extends Model
{
    use HasBlameable;

    /** @use HasFactory<ChecklistTemplateFactory> */
    use HasFactory;
    use SoftDeletes;

    protected $fillable = ['matter_type_id', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function matterType(): BelongsTo
    {
        return $this->belongsTo(MatterType::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ChecklistTemplateItem::class, 'template_id')->orderBy('sort_order');
    }
}
