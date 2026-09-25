<?php

namespace App\Models;

use App\Enums\BillingModel;
use App\Enums\ContractStatus;
use App\Exceptions\ContractNotDestroyable;
use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\HidesInternalAttributesFromPortal;
use App\Models\Concerns\RestrictedToClientPortal;
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

    protected static function booted(): void
    {
        static::deleting(function (Contract $contract): void {
            if ($contract->status !== ContractStatus::Draft) {
                throw ContractNotDestroyable::notDraft();
            }

            if ($contract->hasPayments()) {
                throw ContractNotDestroyable::hasPayments();
            }
        });
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
     * Cổng khách đóng kín ở Task 2 (P1: mở có chủ đích ở Task 10). `1 = 0` chặn sạch thay vì
     * trông vào việc chưa có màn hình nào đọc bảng này — cùng thiết bị với
     * `MatterArchive::applyClientPortalConstraints()`.
     */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereRaw('1 = 0');
    }

    /** `ended_reason` (lý do huỷ) và `note` là nội bộ (P1: khách không thấy lý do huỷ/ghi chú). */
    protected function internalAttributes(): array
    {
        return ['ended_reason', 'note'];
    }
}
