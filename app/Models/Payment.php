<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Exceptions\PaymentNotDestroyable;
use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\HidesInternalAttributesFromPortal;
use App\Models\Concerns\RestrictedToClientPortal;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một khoản tiền THẬT SỰ nhận được, thuộc đúng MỘT {@see Instalment} (M9 quyết định 4 — "tán
 * thành tuyệt đối"). `created_by` (qua `HasBlameable` + `blameOn($actor)`, không phải cột riêng)
 * trả lời "ai ghi khoản này"; `attributed_lawyer_id` trả lời một câu KHÁC — "doanh thu này tính
 * cho luật sư nào" — nên hai cột không trùng nhau (P2, sổ controller câu hỏi 3).
 *
 * **`attributed_lawyer_id` chốt tại lúc ghi, không bao giờ cập nhật sau đó** — kể cả khi vụ việc
 * được bàn giao cho luật sư khác (`ReassignMatter` không dời tiền đã thu). Task 2 chỉ khai báo
 * cột và quan hệ; Action ghi khoản thu (task khác) chịu trách nhiệm đọc đúng `lead_lawyer_id`
 * của `matters` đang khoá trong transaction lúc tạo dòng này.
 *
 * **KHÔNG `SoftDeletes`, và hook `deleting` từ chối MỌI lần xoá, vô điều kiện.** Một khoản thu
 * ghi nhầm không biến mất — nó được HUỶ (`voided_at`/`voided_by`/`void_reason`, Action ở task
 * khác) và vẫn nằm đó, đúng tinh thần `stage_logs` (chỉ thêm).
 */
class Payment extends Model
{
    use HasBlameable;

    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    use HidesInternalAttributesFromPortal;
    use RestrictedToClientPortal;

    protected $fillable = [
        'instalment_id', 'amount', 'paid_on', 'method', 'reference', 'receipt_document_id',
        'attributed_lawyer_id', 'note', 'voided_at', 'voided_by', 'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_on' => 'date',
            'method' => PaymentMethod::class,
            'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw PaymentNotDestroyable::make();
        });
    }

    public function instalment(): BelongsTo
    {
        return $this->belongsTo(Instalment::class);
    }

    public function receiptDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'receipt_document_id');
    }

    public function attributedLawyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attributed_lawyer_id');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /**
     * Tầng TRUY VẤN của cổng khách (M9 Task 10, P1): khoản thu chưa huỷ —
     * {@see self::scopeShownToClient()} — của một đợt khách thấy được. `whereHas('instalment')` trần
     * kế thừa scope cổng của {@see Instalment}, và qua nó của `Contract` và `Matter`. Khoản thu đã
     * huỷ không bao giờ hiện, kể cả khi đợt của nó hiện. Tầng QUYỀN: `PaymentPolicy::view()`.
     */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $this->scopeShownToClient($query);

        $query->whereHas('instalment');
    }

    /**
     * Khoản thu nào khách được thấy, xét trên CHÍNH dòng khoản thu: chưa huỷ (`voided_at` null, P1).
     * Một định nghĩa SQL cho cổng và cho bảng kê trong gói bàn giao — cùng lý do với
     * `Contract::scopeShownToClient()`.
     */
    public function scopeShownToClient(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('voided_at'));
    }

    /**
     * Tầng SERIALIZE (P1, kế hoạch Task 10 điểm 3). `note` là nội bộ. `void_reason`, `voided_by` là
     * nội bộ (khách không thấy lý do huỷ, người huỷ). `receipt_document_id` trỏ bản scan biên lai —
     * LUÔN nhóm D (SPEC §4.11: không vào gói bàn giao M7 R8, không lên cổng) — nên ẩn cột trỏ tới nó
     * cũng là một lớp phòng thủ nữa, dù bản thân Document nhóm D đã tự chặn ở tầng riêng của nó.
     * `attributed_lawyer_id` (doanh thu tính cho ai, P2), `created_by`, `updated_by` là chuyện nội bộ
     * của văn phòng.
     */
    protected function internalAttributes(): array
    {
        return ['note', 'void_reason', 'voided_by', 'receipt_document_id', 'attributed_lawyer_id', 'created_by', 'updated_by'];
    }
}
