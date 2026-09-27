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
use App\Support\Billing\BillingSummary;
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

        $collected = $this->collectedForState();

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

    /**
     * Tổng khoản thu CHƯA HUỶ dùng để suy `state()` — CÙNG con số với
     * {@see self::collectedAmount()}, chỉ khác NGUỒN đọc (M9 Task 8, phán quyết controller 1).
     *
     * **Tin cột `collected_amount` nếu nó CÓ MẶT trong thuộc tính đã nạp** — cột đó không tồn tại
     * trên bảng `instalments`; nó chỉ xuất hiện khi model được nạp qua
     * {@see BillingSummary::pendingInstalmentsQuery()} (`selectRaw … as collected_amount`), tức
     * nơi gọi đã tính SẴN bằng MỘT câu SQL cho CẢ danh sách (trang "Công nợ", Task 8). Trang đó gọi
     * `state()` cho MỖI dòng để tô màu badge; nếu hàm này luôn tự chạy `payments()->sum()`, mỗi
     * lần gọi `state()` lại là một truy vấn SUM() riêng — đúng N+1 mà phán quyết controller 1 yêu
     * cầu xoá, chỉ là bị giấu sau `state()` thay vì sau `outstanding()`.
     *
     * **Không có cột đó thì tự truy vấn như trước** — mọi nơi gọi `state()` KHÔNG qua đường
     * `pendingInstalmentsQuery()` (tab tiền của vụ Task 7, test model, `RecordPayment`/
     * `VoidPayment` đọc lại sau khi ghi) không thấy khác biệt gì: `array_key_exists` trên
     * `getAttributes()` chỉ đúng khi khoá đó THẬT SỰ có mặt, một model nạp bình thường
     * (`Instalment::find()`, `$contract->instalments`) không bao giờ có khoá này.
     */
    private function collectedForState(): int
    {
        $attributes = $this->getAttributes();

        return array_key_exists('collected_amount', $attributes)
            ? (int) $attributes['collected_amount']
            : $this->collectedAmount();
    }

    /**
     * Còn phải thu của ĐÚNG đợt này — số nguyên đồng, không âm (M9 Task 5). Định nghĩa MỘT chỗ,
     * dùng bởi {@see BillingSummary} và bởi các chốt chặn xoá còn nợ.
     *
     * - `waived`, `cancelled` → `0`: miễn là bớt số khách phải trả trên một hợp đồng không đổi giá
     *   trị (không phải phụ lục — xem docblock `BillingSummary`); huỷ đợt cũng ra khỏi số phải đòi.
     * - `paid` → `0` theo ĐỊNH NGHĨA: cột `status` là sự thật văn phòng đã tuyên bố (giống lý do
     *   `state()` ưu tiên ba trạng thái LƯU không mơ hồ trước khi suy luận từ due_date/số đã thu).
     * - `pending` → `amount` trừ tổng khoản thu CHƯA HUỶ, kẹp dưới ở `0`. Kẹp dưới chỉ là một lưới
     *   an toàn cho dữ liệu ghi thẳng vào DB đi vòng qua Action — qua đường hợp lệ duy nhất
     *   (`RecordPayment`), thu vượt bị chặn từ trước bởi `PaymentExceedsInstalment`, nên số âm
     *   không bao giờ xảy ra.
     *
     * **Không đọc `state()`.** Một đợt `pending` đã thu đủ nhưng cột `status` chưa kịp đồng bộ (ca
     * biên `state()` xử lý bằng cách hiển thị `paid`) vẫn tính đúng `0` ở đây — vì hàm này CỘNG
     * TRỪ theo số thu thật (`amount - collected`), không theo một bảng tra trạng thái hiển thị.
     * Hai hàm trả lời hai câu khác nhau: `state()` là "hiển thị cái gì", `outstanding()` là "còn nợ
     * bao nhiêu tiền" — chúng tình cờ khớp nhau ở phần lớn ca, không phải luôn luôn, và không cần
     * phải luôn luôn.
     */
    public function outstanding(): int
    {
        return match ($this->status) {
            InstalmentStatus::Waived, InstalmentStatus::Cancelled, InstalmentStatus::Paid => 0,
            InstalmentStatus::Pending => max(0, $this->amount - $this->collectedAmount()),
        };
    }

    private function collectedAmount(): int
    {
        return (int) $this->payments()->whereNull('voided_at')->sum('amount');
    }

    /**
     * SQL kin của nhánh CUỐI trong {@see self::pendingState()} — "quá hạn" bằng đúng điều kiện đó,
     * viết lại bằng truy vấn cho các trang tổng hợp (widget, trang "Công nợ", tác vụ nhắc quá hạn
     * hằng ngày, Task 8/9/11). `InstalmentTest` ("agrees with state() on the boundary set") khẳng
     * định hai cách cho cùng kết quả trên tập biên INSTALMENT (đến hạn hôm nay, đã miễn, đã huỷ,
     * thu một phần) của các đợt thuộc hợp đồng `active`.
     *
     * Bốn điều kiện, đúng thứ tự `pendingState()` đọc chúng:
     *  - `status = pending` — ba trạng thái LƯU khác không bao giờ "quá hạn" (`state()` trả chúng
     *    trước khi chạm tới nhánh ngày tháng).
     *  - `due_date` khác `null` — chưa có hạn thì không thể "quá hạn" một thứ chưa có hạn.
     *  - `due_date < hôm nay` — hôm nay so bằng NGÀY, không phải thời khắc (cùng lý do `state()`
     *    dùng `today()` chứ không `isPast()`); đến hạn ĐÚNG hôm nay là `due`, chưa `overdue`.
     *  - **Chưa thu đồng nào.** `pendingState()` trả `overdue` CHỈ khi `collected === 0` — thu một
     *    phần (dù đã quá hạn) là `partially_paid`, không phải `overdue` (mất "đã thu một phần" để
     *    đổi lấy "quá hạn" là một lựa chọn tệ hơn khi kế toán đang cần biết còn bao nhiêu để giục).
     *    Đây KHÔNG phải "amount > collected": một đợt đã thu 1 đồng trên 10 triệu vẫn không nằm
     *    trong `scopeOverdue()`, đúng như `state()` không gọi nó `overdue`.
     *
     * **Constraint (a), mang từ Task 4 (xem docblock `App\Actions\Billing\CancelContract`): chỉ
     * hợp đồng `active`.** Một đợt `pending` của hợp đồng đã `cancelled`/`completed` (data model
     * cho phép trạng thái này tồn tại — `CancelContract`/`CompleteContract` không chạm tới đợt)
     * VẪN có thể khớp bốn điều kiện trên nếu tính theo `state()` của riêng nó, nhưng KHÔNG được
     * lọt vào truy vấn công nợ tổng hợp — nếu không, lịch thu lịch sử của một hợp đồng đã đóng sẽ
     * hiện thành nợ quá hạn đang đòi. Đây là điểm DUY NHẤT `scopeOverdue()` và `state()` (gọi trên
     * một instance, không biết gì về trạng thái hợp đồng cha) có thể lệch nhau, có chủ đích — xem
     * `InstalmentTest`, "excludes a pending instalment of a cancelled/completed contract even
     * though its own state() would call it overdue".
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->where($this->qualifyColumn('status'), InstalmentStatus::Pending->value)
            ->whereNotNull($this->qualifyColumn('due_date'))
            ->where($this->qualifyColumn('due_date'), '<', today()->toDateString())
            ->whereHas('contract', fn (Builder $contract) => $contract->where('status', ContractStatus::Active->value))
            ->whereRaw(
                '0 = coalesce((select sum(amount) from payments where payments.instalment_id = '.$this->qualifyColumn('id').' and payments.voided_at is null), 0)'
            );
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
