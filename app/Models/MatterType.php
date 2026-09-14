<?php

namespace App\Models;

use App\Models\Concerns\HasBlameable;
use Database\Factories\MatterTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MatterType extends Model
{
    use HasBlameable;

    /** @use HasFactory<MatterTypeFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = ['code', 'name', 'description', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function stages(): HasMany
    {
        return $this->hasMany(MatterTypeStage::class)->orderBy('sort_order');
    }

    public function matters(): HasMany
    {
        return $this->hasMany(Matter::class);
    }

    public function checklistTemplates(): HasMany
    {
        return $this->hasMany(ChecklistTemplate::class);
    }

    /**
     * Giai đoạn đầu tiên theo sort_order. Đọc từ quan hệ `stages` đã nạp (xem stage()).
     */
    public function firstStage(): ?MatterTypeStage
    {
        return $this->stages->first();
    }

    /**
     * Tra giai đoạn theo key từ quan hệ `stages` đã nạp, để Matter::currentStage() không
     * sinh thêm truy vấn khi liệt kê. Sau khi thêm/sửa giai đoạn trên cùng một instance,
     * gọi `$type->unsetRelation('stages')` (hoặc `load('stages')`) trước khi tra lại.
     */
    public function stage(string $key): ?MatterTypeStage
    {
        return $this->stages->firstWhere('key', $key);
    }
}
