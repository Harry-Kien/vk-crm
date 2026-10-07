<?php

namespace App\Models;

use App\Enums\ContractStatus;
use App\Exceptions\DuplicateStageKey;
use App\Exceptions\StageKeyInUse;
use App\Exceptions\StageTerminalFlagInUse;
use App\Policies\MatterTypeStagePolicy;
use App\Support\Scopes\ClientPortalScope;
use Database\Factories\MatterTypeStageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

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

            /**
             * Task 19 (rà soát cuối, "Cấu hình không phá dữ liệu đang chạy"): đổi `key` của một
             * giai đoạn ĐANG được `matters.stage` hoặc `stage_logs.from_stage`/`to_stage` dùng làm
             * mọi hồ sơ đứng ở đó ĐÓNG BĂNG ngay lập tức — `MatterType::stage($key)` không còn tìm
             * thấy cấu hình cũ, nên `TransitionMatterStage` từ chối MỌI lần chuyển giai đoạn (kể cả
             * "thêm cập nhật" cùng giai đoạn, §6.3) bằng `InvalidStageTransition`, và không có
             * đường sửa nào trên giao diện, kể cả với admin.
             *
             * Đây là CHỐT CHẶN THỨ HAI — `StagesRelationManager` đã chặn ở tầng form (thông báo
             * gắn vào đúng ô `key`) cho đường bấm nút thật; chốt này phủ mọi đường ghi KHÔNG qua
             * form đó (Action, artisan, seeder, factory), cùng đúng lý do `DuplicateStageKey` ở
             * trên tồn tại cả hai nơi.
             *
             * So sánh với `isDirty('key')`, KHÔNG so `getOriginal('key') !== $stage->key` sau khi
             * gán: `isDirty` đã tự loại trường hợp gán lại đúng giá trị cũ (không có gì thật sự
             * đổi), khớp test "lets a stage save again without changing its key".
             */
            if ($stage->exists && $stage->isDirty('key')) {
                $oldKey = $stage->getOriginal('key');

                if (static::keyInUse((int) $stage->matter_type_id, $oldKey, $stage->getKey())) {
                    $type = $stage->matterType ?? MatterType::query()->findOrFail($stage->matter_type_id);

                    // M9 Task 6: khi lý do là đợt thanh toán đang chờ, câu nêu SỐ ĐỢT — người đọc
                    // cần biết phải sửa lịch thu nào, không chỉ "đang có thứ gì đó dùng".
                    $awaiting = static::instalmentsAwaitingStage((int) $stage->matter_type_id, $oldKey);

                    throw $awaiting > 0
                        ? StageKeyInUse::awaitedByInstalments($type, $oldKey, $awaiting)
                        : StageKeyInUse::make($type, $oldKey);
                }
            }

            // Final review X9 (C-I3): bật/tắt `is_terminal` khi còn hồ sơ đứng ở giai đoạn này.
            // Chốt chặn thứ hai — tầng form (`StagesRelationManager`) gắn lỗi vào đúng ô.
            if ($stage->exists && $stage->isDirty('is_terminal')) {
                $standing = static::mattersStandingIn((int) $stage->matter_type_id, (string) $stage->getOriginal('key'));

                if ($standing > 0) {
                    throw StageTerminalFlagInUse::make((string) $stage->getOriginal('key'), $standing);
                }
            }
        });
    }

    /**
     * Số hồ sơ (kể cả đã xoá mềm — khôi phục được, cùng lý do `MatterTypeStagePolicy::delete()`)
     * đang đứng ở `key` của loại vụ việc này. Final review X9: cờ `is_terminal` quyết định nghĩa
     * `closed_at` của đúng những hồ sơ này, nên không đổi được khi con số khác 0.
     */
    public static function mattersStandingIn(int $matterTypeId, string $key): int
    {
        return Matter::withTrashed()
            ->where('matter_type_id', $matterTypeId)
            ->where('stage', $key)
            ->count();
    }

    /** Bản đọc công khai của {@see self::mattersStandingIn()} cho dòng này — dùng ở form. */
    public function mattersStandingHereCount(): int
    {
        return static::mattersStandingIn((int) $this->matter_type_id, $this->key);
    }

    /**
     * M9 Task 6 — guard của M6.5 Task 19 mở rộng tới TIỀN: số đợt thanh toán đang chờ hồ sơ chạm
     * `key` này (`Instalment::awaitingStage()` — `stage`, `pending`, chưa kích hoạt) của hợp đồng
     * `draft` HOẶC `active`, trên hồ sơ CÙNG loại vụ việc (kể cả hồ sơ đã xoá mềm — khôi phục được,
     * cùng lý do {@see self::mattersStandingIn()}). Đổi hay xoá `key` đó thì không lần chuyển giai đoạn
     * nào còn mang nó: đợt không bao giờ đến hạn, một khoản nợ biến mất âm thầm khỏi "Công nợ".
     *
     * Không tính: đợt đã kích hoạt (đã có ngày đến hạn, không còn chờ giai đoạn nào), đợt đã
     * miễn/thu/huỷ, đợt của hợp đồng đã hoàn tất/đã huỷ (không còn gì để đòi), đợt không phải loại
     * `stage`. MỘT hàm đếm cho cả ba nơi hỏi — chốt đổi `key` ở {@see self::keyInUse()} (đường Eloquent),
     * rule của ô `key` ở `StagesRelationManager` (đường form) và {@see MatterTypeStagePolicy::delete()}
     * (đường xoá) — để ba câu từ chối nêu cùng một con số.
     */
    public static function instalmentsAwaitingStage(int $matterTypeId, string $key): int
    {
        return Instalment::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->awaitingStage($key)
            ->whereHas('contract', fn (Builder $contract) => $contract
                ->withoutGlobalScope(ClientPortalScope::class)
                ->whereIn('status', [ContractStatus::Draft->value, ContractStatus::Active->value])
                ->whereHas('matter', fn (Builder $matter) => $matter
                    ->withoutGlobalScope(ClientPortalScope::class)
                    ->withTrashed()
                    ->where('matter_type_id', $matterTypeId)))
            ->count();
    }

    /** Bản đọc công khai của {@see self::instalmentsAwaitingStage()} cho `key` HIỆN TẠI của dòng này — dùng ở form. */
    public function instalmentsAwaitingHereCount(): int
    {
        return static::instalmentsAwaitingStage((int) $this->matter_type_id, $this->key);
    }

    /**
     * Task 19, vòng sửa 1 (Critical): đổi `key` của một giai đoạn còn nằm trong `allowed_next`
     * của một giai đoạn KHÁC bỏ lại một tham chiếu TREO — trước bản vá này, `keyInUse()` chỉ kiểm
     * `matters.stage`/`stage_logs`, nên đổi `on_hold` (bị `intake` VÀ `collecting_documents` cùng
     * trỏ tới trong `StagePresets::civil()`) thành công trót lọt. Sau đó một hồ sơ ở `intake` mở
     * "Chuyển giai đoạn" thấy `on_hold` (nay không tồn tại) trong danh sách, và chọn nó ném
     * `InvalidStageTransition` — cùng hậu quả với xoá mềm mà Policy đã chặn, nhưng đường ĐỔI KEY
     * lại không chặn tương ứng.
     *
     * Điều kiện này MIRROR đúng {@see MatterTypeStagePolicy::delete()} điều kiện 2,
     * và CẢ HAI dùng chung {@see self::stagesReferencing()} — không lặp lại truy vấn ở hai nơi.
     *
     * @return Collection<int, MatterTypeStage>
     */
    public static function stagesReferencing(int $matterTypeId, string $key, ?int $excludeStageId = null): Collection
    {
        return static::query()
            ->where('matter_type_id', $matterTypeId)
            ->when($excludeStageId !== null, fn (Builder $query) => $query->whereKeyNot($excludeStageId))
            ->get()
            ->filter(fn (MatterTypeStage $other): bool => $other->allows($key));
    }

    /**
     * True nếu còn HỒ SƠ đang đứng ở `key` này (kể cả đã xoá mềm — vẫn khôi phục được, cùng lý do
     * `MatterTypePolicy::delete()` dùng `withTrashed()`), một DÒNG TIẾN ĐỘ
     * (`stage_logs.from_stage`/`to_stage`) của cùng loại vụ việc đã dùng `key` này, `key` này còn
     * nằm trong `allowed_next` của một giai đoạn KHÁC (xem docblock {@see self::stagesReferencing()}),
     * HOẶC (M9 Task 6) một đợt thanh toán còn chờ hồ sơ chạm `key` này (xem
     * {@see self::instalmentsAwaitingStage()}). Dùng để chặn ĐỔI `key` (xem `booted()`); KHÔNG dùng
     * cho luật xoá — luật xoá hẹp hơn (không tính `stage_logs` lịch sử) và sống ở
     * {@see MatterTypeStagePolicy::delete()}.
     */
    private static function keyInUse(int $matterTypeId, string $key, ?int $excludeStageId = null): bool
    {
        return Matter::withTrashed()
            ->where('matter_type_id', $matterTypeId)
            ->where('stage', $key)
            ->exists()
            || StageLog::withoutGlobalScopes()
                ->where(fn (Builder $query) => $query->where('from_stage', $key)->orWhere('to_stage', $key))
                ->whereHas('matter', fn (Builder $query) => $query->withTrashed()->where('matter_type_id', $matterTypeId))
                ->exists()
            || static::stagesReferencing($matterTypeId, $key, $excludeStageId)->isNotEmpty()
            || static::instalmentsAwaitingStage($matterTypeId, $key) > 0;
    }

    /** Bản đọc công khai của {@see self::keyInUse()} cho `key` HIỆN TẠI — dùng ở form (xem StagesRelationManager). */
    public function isKeyInUse(): bool
    {
        return static::keyInUse((int) $this->matter_type_id, $this->key, $this->getKey());
    }

    /**
     * Nhãn các giai đoạn KHÁC đang trỏ `allowed_next` vào `key` HIỆN TẠI của dòng này — dùng để
     * ghép một câu từ chối NÊU TÊN thay vì một câu chung chung (xem `StagesRelationManager`).
     *
     * @return Collection<int, string>
     */
    public function referencingStageLabels(): Collection
    {
        return static::stagesReferencing((int) $this->matter_type_id, $this->key, $this->getKey())->pluck('label');
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
