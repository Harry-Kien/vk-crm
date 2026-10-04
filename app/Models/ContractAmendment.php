<?php

namespace App\Models;

use App\Exceptions\ContractAmendmentImmutable;
use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\HidesInternalAttributesFromPortal;
use App\Models\Concerns\RestrictedToClientPortal;
use Database\Factories\ContractAmendmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phụ lục hợp đồng — CHỈ THÊM, không sửa, không xoá, đúng tinh thần {@see StageLog} /
 * `StageLogImmutable` (M9, mục "Chỗ tôi nghĩ một quyết định chưa đúng"). `contracts.total_amount`
 * vẫn là NGUỒN SỰ THẬT DUY NHẤT của giá trị hợp đồng hiện hành; mỗi lần con số đó đổi sinh một
 * dòng ở đây mang giá trị cũ, giá trị mới, lý do, ngày ký — không có "phiên bản hợp đồng", không
 * có câu hỏi "bản nào đang có hiệu lực".
 *
 * **Hook chỉ canh đường Eloquent — không canh query builder.** `static::updating`/`deleting` chỉ
 * chạy khi có một instance được lưu qua `save()`/`update()`/`delete()`. `ContractAmendment::query()
 * ->update([...])`, `DB::table('contract_amendments')->update(...)` và SQL thô đi vòng qua guard
 * này hoàn toàn — cùng giới hạn đã ghi ở `Client::booted()` cho `SyncClientPartyIdentities`. Quy
 * ước của dự án là mọi Action phải đi qua model; đây là một quy ước, không phải một ràng buộc mã
 * nguồn ép được.
 */
class ContractAmendment extends Model
{
    use HasBlameable;

    /** @use HasFactory<ContractAmendmentFactory> */
    use HasFactory;

    use HidesInternalAttributesFromPortal;
    use RestrictedToClientPortal;

    protected $fillable = [
        'contract_id', 'sequence', 'previous_total_amount', 'new_total_amount', 'reason',
        'signed_at', 'document_id',
    ];

    protected function casts(): array
    {
        return [
            'previous_total_amount' => 'integer',
            'new_total_amount' => 'integer',
            'signed_at' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw ContractAmendmentImmutable::make();
        });

        static::deleting(function (): void {
            throw ContractAmendmentImmutable::make();
        });
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Tầng TRUY VẤN của cổng khách (M9 Task 10): phụ lục của một hợp đồng khách thấy được —
     * `whereHas('contract')` trần kế thừa scope cổng của {@see Contract}. Không điều kiện riêng nào
     * trên dòng phụ lục: phụ lục chỉ thêm, không có trạng thái.
     *
     * **Mở theo chữ kế hoạch (phán quyết controller, B.6 của brief Task 10)**, dù khối "Hợp đồng và
     * thanh toán" trên cổng KHÔNG vẽ phụ lục (P1 không liệt kê nó): khách đã ký phụ lục đó, và giá
     * trị hiện hành của hợp đồng đã phản ánh nó. Lý do phụ lục và bản scan KHÔNG BAO GIỜ ra cổng —
     * {@see self::internalAttributes()}, và trang cổng không đọc bảng này. Tầng QUYỀN:
     * `ContractAmendmentPolicy::view()`.
     */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereHas('contract');
    }

    /**
     * Tầng SERIALIZE: `reason` là nội bộ (P1: khách không thấy lý do phụ lục). `document_id` trỏ bản
     * scan phụ lục, luôn nhóm D. `created_by`, `updated_by` là nhân sự của văn phòng.
     */
    protected function internalAttributes(): array
    {
        return ['reason', 'document_id', 'created_by', 'updated_by'];
    }
}
