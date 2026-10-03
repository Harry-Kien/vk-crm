<?php

namespace App\Models;

use App\Enums\BillingModel;
use App\Enums\ContractStatus;
use App\Exceptions\ContractNotDestroyable;
use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\HidesInternalAttributesFromPortal;
use App\Models\Concerns\RestrictedToClientPortal;
use App\Support\Billing\ScheduleTotal;
use Database\Factories\ContractFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Một hợp đồng dịch vụ pháp lý cho một vụ việc — giá trị thoả thuận MỘT LẦN (`total_amount`),
 * chia thành các đợt thanh toán ({@see Instalment}). `matter_id` UNIQUE THẬT ở migration (M9
 * quyết định 1), không phải một quy ước tầng ứng dụng.
 *
 * **KHÔNG `SoftDeletes` — deviation so với §4 của SPEC, có chủ đích (M9 quyết định 4).** Lý do:
 * (a) `matter_id` là unique; một hợp đồng xoá mềm vẫn chiếm chỗ index, và "xoá mềm rồi tạo lại"
 * đúng là lỗ hổng dự án đã vấp HAI lần (`matter_type_stages.key`, `MatterType.code` — cả hai đều
 * bị bỏ ràng buộc unique thật ở DB vì lý do này). (b) Một hợp đồng chỉ có MỘT cửa biến mất: xoá
 * CỨNG, và chỉ khi còn `draft` và chưa có khoản thu nào (`booted()` bên dưới) — từ `active` trở
 * đi nó chỉ đóng lại bằng `completed` hoặc `cancelled`, không bao giờ xoá.
 */
class Contract extends Model
{
    use HasBlameable;

    /** @use HasFactory<ContractFactory> */
    use HasFactory;

    use HidesInternalAttributesFromPortal;
    use RestrictedToClientPortal;

    protected $fillable = [
        'matter_id', 'code', 'status', 'billing_model', 'total_amount', 'vat_rate_percent',
        'signed_at', 'activated_by', 'ended_at', 'ended_reason', 'note',
    ];

    protected function casts(): array
    {
        return [
            'status' => ContractStatus::class,
            'billing_model' => BillingModel::class,
            // unsignedBigInteger ở MariaDB chứa tới 18446744073709551615 đồng — vượt xa
            // PHP_INT_MAX (9223372036854775807) trên nền 64-bit. Cast 'integer' vẫn đúng cho MỌI
            // giá trị THẬT của một văn phòng luật (không hợp đồng nào chạm 9 tỷ tỷ đồng), nên
            // không cần cast 'string' hay decimal — nhưng một dòng dữ liệu hỏng/nhập tay thẳng
            // vào DB vượt biên sẽ tràn số âm thầm. Không phải một rủi ro Task 2 phải chặn (không
            // Action nào ghi số đó), chỉ ghi lại để lần sau đọc cast này không tưởng nó an toàn
            // tuyệt đối.
            'total_amount' => 'integer',
            'vat_rate_percent' => 'integer',
            'signed_at' => 'date',
            'ended_at' => 'date',
        ];
    }

    /**
     * `updating` — phía hợp đồng của tầng 2 bất biến tổng M9
     * ({@see ScheduleTotal::assertContractWriteKeepsBalance()}): đưa hợp đồng vào `active`, hay đổi
     * `total_amount` khi đang `active`, qua model mà để lại tổng các đợt lệch thì bị từ chối — đường
     * đi vòng qua `ActivateContract` và `AmendContract`. Là `updating` chứ không `saving`: tạo thẳng
     * một hợp đồng `active` là cách factory dựng fixture và không Action nào làm vậy (xem
     * {@see ScheduleTotal}).
     *
     * `deleting` — chỉ xoá được bản nháp chưa có khoản thu nào (M9 Task 2), qua
     * {@see self::assertDestroyable()}.
     */
    protected static function booted(): void
    {
        static::updating(function (Contract $contract): void {
            ScheduleTotal::assertContractWriteKeepsBalance($contract);
        });

        static::deleting(function (Contract $contract): void {
            $contract->assertDestroyable();
        });
    }

    /**
     * "Khi nào xoá được một hợp đồng" — MỘT định nghĩa (M9 Task 2): còn `draft` VÀ chưa có khoản thu
     * nào. Hook `deleting` gọi hàm này; `App\Actions\Billing\DeleteDraftContract` gọi nó TRƯỚC khi
     * xoá các đợt, để một lần từ chối nói đúng câu của HỢP ĐỒNG (không phải câu "đợt không xoá
     * được" của `Instalment::deleting`) và không đợt nào bị xoá dở.
     *
     * @throws ContractNotDestroyable
     */
    public function assertDestroyable(): void
    {
        if ($this->status !== ContractStatus::Draft) {
            throw ContractNotDestroyable::notDraft();
        }

        if ($this->hasPayments()) {
            throw ContractNotDestroyable::hasPayments();
        }
    }

    /** Có khoản thu nào (chưa huỷ hay đã huỷ đều tính — một dòng đã tồn tại là đủ) trên bất kỳ đợt nào của hợp đồng này. */
    public function hasPayments(): bool
    {
        return Payment::query()->whereHas(
            'instalment',
            fn (Builder $query) => $query->where('contract_id', $this->getKey()),
        )->exists();
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function activator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function instalments(): HasMany
    {
        return $this->hasMany(Instalment::class)->orderBy('sequence');
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(ContractAmendment::class)->orderBy('sequence');
    }

    /**
     * Tầng TRUY VẤN của cổng khách (M9 Task 10, P1 — Task 2 đóng kín bằng `1 = 0`, Task 10 mở có
     * chủ đích): khách thấy hợp đồng khi (a) nó đã ký — {@see self::scopeShownToClient()} — và (b) vụ
     * việc của nó hiển thị trên cổng với đúng khách đó.
     *
     * (b) là `whereHas('matter')` TRẦN, không một điều kiện vụ việc nào viết lại ở đây: truy vấn con
     * chạy khi `ClientPortalScope` đang hoạt động, nên nó mang nguyên năm điều kiện của
     * {@see Matter::applyClientPortalConstraints()} — đúng khách, đã công bố, vụ chưa xoá mềm, khách
     * hàng chưa xoá mềm, chưa hết `client_access_until` (phán quyết controller: ranh giới cổng của
     * tiền PHẢI là ranh giới của vụ việc). Một ngày ranh giới vụ việc thêm điều kiện thứ sáu thì tiền
     * theo luôn, không ai phải nhớ sửa chỗ này.
     *
     * Tầng QUYỀN nói lại (a) bằng thuộc tính và hỏi `MatterPolicy::view` cho (b) — xem
     * `ContractPolicy::view()`; hai tầng không chung một câu lệnh nào.
     *
     * Hết hạn tra cứu hay lưu trữ vụ việc làm khối tiền biến khỏi cổng, nhưng KHÔNG đổi sổ tiền: đây
     * chỉ là một điều kiện đọc của phiên cổng, không có gì được ghi.
     */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $this->scopeShownToClient($query);

        $query->whereHas('matter');
    }

    /**
     * Hợp đồng nào khách được thấy, xét trên CHÍNH dòng hợp đồng: `active` hoặc `completed` (P1).
     * Bản nháp chưa ai ký, bản đã huỷ không còn là cam kết — cả hai không bao giờ lên cổng hay vào
     * bảng kê của gói bàn giao.
     *
     * Một định nghĩa SQL cho HAI nơi: tầng truy vấn của cổng ({@see self::applyClientPortalConstraints()})
     * và bảng kê thanh toán trong `MUC-LUC.pdf` (`RenderHandoverIndex::billingStatement()`), nơi
     * không có phiên cổng nào nên scope cổng không chạy. Không nói gì về vụ việc — nơi gọi tự giới
     * hạn theo vụ.
     */
    public function scopeShownToClient(Builder $query): Builder
    {
        return $query->whereIn($this->qualifyColumn('status'), [
            ContractStatus::Active->value,
            ContractStatus::Completed->value,
        ]);
    }

    /**
     * Tầng SERIALIZE (P1, kế hoạch Task 10 điểm 3): `ended_reason` (lý do huỷ) và `note` là nội bộ;
     * `activated_by`, `created_by`, `updated_by` là nhân sự của văn phòng — khách biết hợp đồng đã
     * ký ngày nào, không cần biết ai bấm nút.
     */
    protected function internalAttributes(): array
    {
        return ['ended_reason', 'note', 'activated_by', 'created_by', 'updated_by'];
    }
}
