<?php

namespace App\Models;

use App\Models\Concerns\RestrictedToClientPortal;
use Database\Factories\ChecklistTemplateItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChecklistTemplateItem extends Model
{
    /** @use HasFactory<ChecklistTemplateItemFactory> */
    use HasFactory;

    use RestrictedToClientPortal;
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

    /**
     * Khách chỉ thấy bản sao trong matter_checklist_items, không thấy danh mục mẫu (SPEC §4.10).
     * Chặn sạch ở tầng truy vấn thay vì trông vào việc không ai viết resource cho nó.
     */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereRaw('1 = 0');
    }
}
