<?php

namespace App\Models;

use App\Enums\ChecklistItemStatus;
use App\Models\Concerns\RestrictedToClientPortal;
use Database\Factories\MatterChecklistItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MatterChecklistItem extends Model
{
    /** @use HasFactory<MatterChecklistItemFactory> */
    use HasFactory;

    use RestrictedToClientPortal;
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

    /**
     * Khách thấy toàn bộ danh mục hồ sơ của vụ việc mình, kèm trạng thái và lý do từ chối
     * (SPEC §8.3).
     *
     * `whereNull('deleted_at')`: một mục đã bị gỡ khỏi danh mục không quay lại bằng một lần
     * `withTrashed()` — thứ chỉ gỡ `SoftDeletingScope` chứ không đụng tới `ClientPortalScope`.
     * Cùng lý lẽ với {@see Matter::applyClientPortalConstraints()}. Ở đây nó còn có một nghĩa
     * riêng: một mục đã gỡ vẫn mang trạng thái và lý do từ chối của nó, nên một lần quay lại là
     * một lần đòi khách nộp lại thứ văn phòng đã thôi không cần.
     */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereNull($this->qualifyColumn('deleted_at'))->whereHas('matter');
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
