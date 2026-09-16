<?php

namespace App\Models;

use App\Exceptions\DuplicateStageKey;
use Database\Factories\MatterTypeStageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cố ý KHÔNG dùng RestrictedToClientPortal: đây là dữ liệu cấu hình, không chứa dữ liệu khách
 * hàng, và portal cần đọc client_label / client_description để hiển thị giai đoạn (SPEC §8.3).
 */
class MatterTypeStage extends Model
{
    /** @use HasFactory<MatterTypeStageFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'matter_type_id', 'key', 'label', 'client_label', 'client_description',
        'sort_order', 'is_terminal', 'allowed_next', 'default_next_update_days',
    ];

    /**
     * `unique(matter_type_id, key)` không còn ở DB (MariaDB không có unique một phần, và ràng
     * buộc cũ tính cả dòng đã xoá mềm — xem migration 2026_09_15_000001). Uniqueness giờ được
     * StagesRelationManager kiểm tra ở form (thông báo thân thiện) VÀ ở đây (chặn mọi đường ghi
     * khác — Action, artisan command, seeder, factory — không chỉ mỗi form đó).
     */
    protected static function booted(): void
    {
        static::saving(function (MatterTypeStage $stage): void {
            // static::query() đã tự loại các dòng đã xoá mềm nhờ SoftDeletes (global scope);
            // không cần tự thêm whereNull('deleted_at').
            $duplicateExists = static::query()
                ->where('matter_type_id', $stage->matter_type_id)
                ->where('key', $stage->key)
                ->when($stage->exists, fn ($query) => $query->whereKeyNot($stage->getKey()))
                ->exists();

            if ($duplicateExists) {
                $type = $stage->matterType ?? MatterType::query()->findOrFail($stage->matter_type_id);

                throw DuplicateStageKey::make($type, $stage->key);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_terminal' => 'boolean',
            'allowed_next' => 'array',
            'sort_order' => 'integer',
            'default_next_update_days' => 'integer',
        ];
    }

    public function matterType(): BelongsTo
    {
        return $this->belongsTo(MatterType::class);
    }

    public function allows(string $nextKey): bool
    {
        return in_array($nextKey, $this->allowed_next ?? [], true);
    }
}
