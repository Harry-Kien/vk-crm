<?php

namespace App\Models;

use App\Exceptions\StageLogImmutable;
use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\HidesInternalAttributesFromPortal;
use App\Models\Concerns\RestrictedToClientPortal;
use Database\Factories\StageLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StageLog extends Model
{
    use HasBlameable;

    /** @use HasFactory<StageLogFactory> */
    use HasFactory;

    use HidesInternalAttributesFromPortal;
    use RestrictedToClientPortal;

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

    /**
     * Khách chỉ đọc dòng đã công bố, thuộc vụ việc mà Matter cho phép (SPEC §5).
     * `whereHas('matter')` kế thừa điều kiện của Matter nên không lặp lại client_id ở đây.
     */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->where($this->qualifyColumn('is_published'), true)->whereHas('matter');
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

    /** Đợt thanh toán mà dòng tiến độ này kích hoạt (`instalments.triggered_by_stage_log_id`) — bằng chứng "tại sao đợt này đến hạn". */
    public function triggeredInstalments(): HasMany
    {
        return $this->hasMany(Instalment::class, 'triggered_by_stage_log_id');
    }

    /**
     * **MỘT định nghĩa "dòng đưa vụ VÀO giai đoạn `to_stage`"** (M9 Task 6): `from_stage` rỗng (dòng
     * mở đầu mà dữ liệu mẫu ghi thẳng) HOẶC khác `to_stage`. Dòng cùng giai đoạn — "thêm cập nhật"
     * (SPEC §6.3), dòng bàn giao nội bộ của `ReassignMatter` — không đưa vụ vào đâu cả, nên không bao
     * giờ là một lần "chạm tới giai đoạn". Cùng điều kiện mà `TransitionMatterStage` dùng để phát
     * `MatterStageChanged` (`! $isSameStage`), nói lại bằng SQL cho các dòng đã ghi.
     *
     * Dùng bởi `App\Actions\Billing\TriggerInstalmentsForStage::firstEntryInto()` (lần chạm ĐẦU của
     * một vụ vào một giai đoạn) và bởi tập ứng viên của
     * `App\Actions\Schedule\ReconcileStageTriggeredInstalments`.
     */
    public function scopeEntries(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->whereNull($this->qualifyColumn('from_stage'))
            ->orWhereColumn($this->qualifyColumn('from_stage'), '!=', $this->qualifyColumn('to_stage')));
    }

    /**
     * Dòng tiến độ ghi cho một ngày trong kỳ (M13, cột P4 "Chuyển giai đoạn", ghép với
     * {@see self::scopeEntries()}): `occurred_at` trong `$bounds` — hai cận đủ giờ của
     * `PerformancePeriod::bounds()`. `occurred_at` là ngày NGƯỜI DÙNG chọn (ô chọn ngày, nửa đêm), ghi
     * lùi được, nên một dòng ghi hôm nay cho ngày tháng trước làm đổi số của tháng trước — câu giải
     * thích của P4 nói điều đó; không "khoá kỳ".
     *
     * @param  array{0: string, 1: string}  $bounds
     */
    public function scopeOccurredBetween(Builder $query, array $bounds): Builder
    {
        return $query->whereBetween($this->qualifyColumn('occurred_at'), $bounds);
    }

    /** SPEC §4.8: internal_note chỉ dành cho nội bộ. */
    protected function internalAttributes(): array
    {
        return ['internal_note'];
    }
}
