<?php

namespace App\Models;

use Database\Factories\ChecklistTemplateItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChecklistTemplateItem extends Model
{
    /** @use HasFactory<ChecklistTemplateItemFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = ['template_id', 'name', 'description', 'is_required', 'sort_order'];

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'sort_order' => 'integer'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplate::class, 'template_id');
    }
}
