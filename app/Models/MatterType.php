<?php

namespace App\Models;

use App\Exceptions\DuplicateMatterTypeCode;
use App\Models\Concerns\HasBlameable;
use Database\Factories\MatterTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cố ý KHÔNG dùng RestrictedToClientPortal: đây là dữ liệu cấu hình, không chứa dữ liệu khách
 * hàng, và portal cần đọc client_label / client_description để hiển thị giai đoạn (SPEC §8.3).
 */
class MatterType extends Model
{
    use HasBlameable;

    /** @use HasFactory<MatterTypeFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = ['code', 'name', 'description', 'is_active', 'sort_order'];

    /**
     * `unique` trên `code` không còn ở DB (MariaDB không có unique một phần, và ràng buộc cũ tính
     * cả dòng đã xoá mềm — xem migration 2026_09_20_000001, cùng lỗ hổng `matter_type_stages.key`
     * đã đóng ở M3). Tính duy nhất trong phạm vi các dòng CÒN DÙNG giờ được `MatterTypeForm` kiểm
     * ở form (thông báo thân thiện) VÀ ở đây — chốt chặn này mới là thứ phủ mọi đường ghi khác:
     * seeder, factory, Action, artisan command. Bài học nguyên văn từ M3: đặt luật ở mỗi form thì
     * `MatterTypeSeeder` và `MatterTypeFactory` đi thẳng qua Eloquent, không bị chặn gì cả.
     */
    protected static function booted(): void
    {
        static::saving(function (MatterType $type): void {
            // static::query() đã tự loại các dòng đã xoá mềm nhờ SoftDeletes (global scope).
            $duplicateExists = static::query()
                ->where('code', $type->code)
                ->when($type->exists, fn ($query) => $query->whereKeyNot($type->getKey()))
                ->exists();

            if ($duplicateExists) {
                throw DuplicateMatterTypeCode::make((string) $type->code);
            }
        });
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** `sort_order` không đảm bảo duy nhất; `id` là tiêu chí phụ để thứ tự luôn xác định. */
    public function stages(): HasMany
    {
        return $this->hasMany(MatterTypeStage::class)->orderBy('sort_order')->orderBy('id');
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

    /**
     * Task 19, vòng sửa 1 (Important — "admin timeline hiện raw key cho giai đoạn đã xoá mềm"):
     * `stage($key)` chỉ đọc quan hệ `stages` đã nạp, LUÔN loại các dòng đã xoá mềm (global scope
     * của `SoftDeletes` trên `MatterTypeStage`). Xoá mềm một giai đoạn mà chỉ LỊCH SỬ
     * (`stage_logs`) còn dùng là hành vi ĐƯỢC PHÉP (`MatterTypeStagePolicy::delete()` chỉ chặn hồ
     * sơ ĐANG đứng và `allowed_next`, không chặn lịch sử) — nên "không tìm thấy trong `stages()`
     * còn sống" không có nghĩa "chưa từng tồn tại thật", và một dòng lịch sử không nên mất nhãn
     * tiếng Việt của nó chỉ vì cấu hình đã dọn.
     *
     * Ưu tiên giai đoạn CÒN SỐNG nếu trùng `key` (trường hợp xoá mềm rồi tạo lại cùng `key`, xem
     * `MatterTypeStage::booted()`); nếu không, lấy dòng đã xoá mềm GẦN NHẤT khớp `key` —
     * `orderByDesc('deleted_at')` vì hôm nay không có cột nào khác gắn một dòng `stage_log` cụ
     * thể với đúng phiên bản giai đoạn đã tạo ra nó.
     *
     * Dùng chung cho `StageLogsRelationManager` (tab "Tiến độ", admin) và
     * `MatterProgress::stageLabel()` (cổng khách) — MỘT helper duy nhất để hai màn hình không
     * lệch nhau (I1, rà soát vòng 1).
     */
    public function stageIncludingTrashed(string $key): ?MatterTypeStage
    {
        return $this->stage($key)
            ?? $this->stages()->onlyTrashed()->where('key', $key)->orderByDesc('deleted_at')->first();
    }
}
