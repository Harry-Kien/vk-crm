<?php

namespace App\Models;

use App\Enums\ContractStatus;
use App\Enums\InstalmentState;
use App\Enums\InstalmentStatus;
use App\Enums\InstalmentTrigger;
use App\Exceptions\InstalmentNotDestroyable;
use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\HidesInternalAttributesFromPortal;
use App\Models\Concerns\RestrictedToClientPortal;
use App\Support\Billing\ScheduleTotal;
use Database\Factories\InstalmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Một đợt thanh toán của {@see Contract}, mang lịch thu (M9 quyết định 2). Ba loại kích hoạt
 * (`trigger_type`): `on_signing` (tạm ứng khi ký — chưa biết ngày lúc soạn lịch), `due_date`
 * (một ngày cụ thể), `stage` (chạm tới một giai đoạn của ĐÚNG loại vụ việc đó, qua
 * `trigger_stage_key` — KHÔNG phải FK `matter_type_stages`, và không được là giai đoạn đầu).
 *
 * **KHÔNG `SoftDeletes`, cùng lý do với `Contract` (M9 quyết định 4).** Đợt của hợp đồng `draft`
 * xoá cứng được; từ `active` trở đi chỉ `cancelled` (qua Action `AmendContract`) hoặc `waived`,
 * không bao giờ xoá — {@see self::booted()}. Trên hợp đồng `active`, số tiền và trạng thái huỷ của
 * một đợt còn bị hook `saving` canh bất biến tổng — xem {@see ScheduleTotal}.
 *
 * **Không có cột `paid_amount`.** Số đã thu là SUM của {@see Payment} chưa huỷ trên đợt này —
 * một cột tổng hợp là nguồn sự thật THỨ HAI về tiền, và nó sẽ lệch. Đọc "còn phải thu" luôn là
 * một phép join qua {@see self::payments()}.
 */
class Instalment extends Model
{
    use HasBlameable;

    /** @use HasFactory<InstalmentFactory> */
    use HasFactory;

    use HidesInternalAttributesFromPortal;
    use RestrictedToClientPortal;

    protected $fillable = [
        'contract_id', 'sequence', 'name', 'amount', 'percent_basis', 'trigger_type',
        'trigger_stage_key', 'due_days_after_trigger', 'due_date', 'triggered_at',
        'triggered_by_stage_log_id', 'status', 'waived_reason', 'waived_by', 'waived_at', 'note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            // Chỉ để hiển thị và truy vết (xem docblock cột ở migration) — KHÔNG BAO GIỜ dùng
            // để tính lại `amount`. `amount` mới là con số có tính quyết định.
            'percent_basis' => 'decimal:2',
            'trigger_type' => InstalmentTrigger::class,
            'due_date' => 'date',
            'triggered_at' => 'datetime',
            'status' => InstalmentStatus::class,
            'waived_at' => 'datetime',
        ];
    }

    /**
     * Hai hook, cả hai giữ lịch thu của một hợp đồng đã ký:
     *
     * - `saving` — tầng 2 của bất biến tổng M9 ({@see ScheduleTotal::assertInstalmentWriteKeepsBalance()}):
     *   trên hợp đồng `active`, một lần ghi qua model làm tổng các đợt chưa huỷ lệch khỏi
     *   `total_amount` bị từ chối bằng `ContractTotalMismatch`. Phụ lục (`AmendContract`) là đường
     *   duy nhất đổi được số tiền của hợp đồng đã ký.
     * - `deleting` — đợt chỉ xoá được khi hợp đồng còn `draft` (M9 Task 2). Vì thế `saving` không
     *   phải canh đường xoá: trên hợp đồng `active` không lần xoá nào qua được tới bước làm lệch tổng.
     *
     * Cả hai chỉ canh đường Eloquent; `DB::table()` đi vòng qua — xem docblock của {@see ScheduleTotal}.
     */
    protected static function booted(): void
    {
        static::saving(function (Instalment $instalment): void {
            ScheduleTotal::assertInstalmentWriteKeepsBalance($instalment);
        });

        static::deleting(function (Instalment $instalment): void {
            if ($instalment->contract->status !== ContractStatus::Draft) {
                throw InstalmentNotDestroyable::make();
            }
        });
    }

    /**
     * Trạng thái HIỂN THỊ — suy ra từ `status` (LƯU) cộng `due_date` và tổng khoản thu chưa huỷ.
     * Đây là chỗ DUY NHẤT tính bảy trạng thái của {@see InstalmentState}; không nơi nào khác
     * được lặp lại phép so sánh "hôm nay so với due_date" hay "đã thu bao nhiêu".
     *
     * Thứ tự ưu tiên, và vì sao: ba trạng thái LƯU không mơ hồ (`waived`, `cancelled`, `paid`)
     * thắng trước — chúng là sự thật văn phòng đã tuyên bố, không phải suy luận. Với `pending`
     * còn lại: chưa có `due_date` là `scheduled` (chưa tới lúc, không thể nói "quá hạn" một thứ
     * chưa có hạn); có `due_date` thì so tổng đã thu — thu đủ (dù `status` cột chưa kịp cập nhật
     * qua Action) hiển thị `paid` chứ không phải `due`/`overdue`, vì bảy trạng thái hiển thị nói
     * cho người xem biết sự thật ngay bây giờ, không đợi một Action đồng bộ lại cột `status`; thu
     * một phần là `partially_paid`, DÙ đã quá hạn — mất thông tin "đã thu một phần" để đổi lấy
     * "quá hạn" là một lựa chọn tệ hơn khi kế toán đang cần biết còn bao nhiêu để giục; cuối cùng
     * so ngày để phân `due`/`overdue`.
     */
    public function state(): InstalmentState
    {
        return match ($this->status) {
            InstalmentStatus::Waived => InstalmentState::Waived,
            InstalmentStatus::Cancelled => InstalmentState::Cancelled,
            InstalmentStatus::Paid => InstalmentState::Paid,
            InstalmentStatus::Pending => $this->pendingState(),
        };
    }

    private function pendingState(): InstalmentState
    {
        if ($this->due_date === null) {
            return InstalmentState::Scheduled;
        }

        $collected = $this->payments()->whereNull('voided_at')->sum('amount');

        if ($collected >= $this->amount) {
            return InstalmentState::Paid;
        }

        if ($collected > 0) {
            return InstalmentState::PartiallyPaid;
        }

        // So theo NGÀY (`today()`), không theo giờ phút: `due_date` là một cột `date`, nên một
        // đợt đến hạn ĐÚNG HÔM NAY vẫn là `due`, không phải `overdue` — `isPast()` sẽ sai ở đây
        // vì nó so với thời khắc HIỆN TẠI (luôn sau nửa đêm), biến "đến hạn hôm nay" thành "quá
        // hạn" ngay từ 00:00:01. Cùng thành ngữ `today()` với `Deadline::scopeUpcoming()`.
        return $this->due_date->lt(today()) ? InstalmentState::Overdue : InstalmentState::Due;
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function triggeredByStageLog(): BelongsTo
    {
        return $this->belongsTo(StageLog::class, 'triggered_by_stage_log_id');
    }

    public function waiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waived_by');
    }

    /** Cổng khách đóng kín ở Task 2 (P1: mở có chủ đích ở Task 10). */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereRaw('1 = 0');
    }

    /** `waived_reason` (lý do miễn) và `note` là nội bộ (P1). */
    protected function internalAttributes(): array
    {
        return ['waived_reason', 'note'];
    }
}
