<?php

namespace App\Support\Billing;

use App\Enums\ContractStatus;
use App\Filament\Admin\Pages\ContractEntry;
use App\Models\Contract;
use App\Models\Matter;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;

/**
 * Một dòng của trang "Hợp đồng" ({@see ContractEntry}, làn kt) — một vụ việc đúng như kế toán được
 * thấy khi nhập hợp đồng đã ký: mã hồ sơ, loại vụ việc, tên khách hàng, luật sư phụ trách, số và trạng
 * thái hợp đồng hiện hành, tổng giá trị, đã thu, còn phải thu.
 *
 * **Ranh giới lộ thông tin có chủ đích**, cùng tinh thần {@see AccountantBillingRow} (SPEC §5, "Ranh
 * giới của kế toán"): KHÔNG tiêu đề vụ việc, tóm tắt, mô tả nội bộ, tài liệu, tiến độ, các bên (kể cả
 * bên đối lập), ghi chú của hợp đồng hay của đợt. Điểm nới rộng so với `AccountantBillingRow` là **tên
 * luật sư phụ trách** — người nhận chuông khi kế toán nhập hợp đồng (SPEC §5 bổ sung 2026-10-15), và
 * là người kế toán cần hỏi lại khi con số trên giấy không rõ; tên nhân sự không phải nội dung hồ sơ.
 * `readonly` để không ai gắn thêm thuộc tính sau khi tạo; test ghim đúng chín trường.
 *
 * Nơi dùng cần vụ việc để chạy một Action thì giữ `Matter` BÊN CẠNH dòng này, không nhét vào nó.
 */
final readonly class AccountantContractRow implements Arrayable
{
    public function __construct(
        public string $matterCode,
        public string $matterTypeName,
        public string $clientName,
        public string $leadLawyerName,
        public ?string $contractCode,
        public ?ContractStatus $contractStatus,
        public ?int $totalAmount,
        public int $collected,
        public int $outstanding,
    ) {}

    /**
     * Đường DUY NHẤT từ model sang dòng này. Hai con số suy ra do nơi gọi đưa vào (trang tính cả hai
     * bằng biểu thức SQL cho mọi dòng trong MỘT câu SELECT, như `ClosedWithBalanceWidget`); lớp này không
     * tính gì về tiền. Cha đã nạp thì không truy vấn; loại vụ việc, khách hàng và luật sư phụ trách xoá
     * mềm được nên chưa nạp (hay nạp ra `null`) thì hỏi lại bằng `withTrashed()` — kế toán vẫn nhập được
     * hợp đồng của vụ có khách đã lưu hồ sơ hay luật sư đã nghỉ việc.
     */
    public static function fromMatter(Matter $matter, int $collected, int $outstanding): self
    {
        /** @var Contract|null $contract */
        $contract = $matter->relationLoaded('contract') ? $matter->getRelation('contract') : $matter->contract()->first();

        return new self(
            matterCode: $matter->code,
            matterTypeName: self::parentOf($matter, 'matterType')->name,
            clientName: self::parentOf($matter, 'client')->name,
            leadLawyerName: self::parentOf($matter, 'leadLawyer')->name,
            contractCode: $contract?->code,
            contractStatus: $contract?->status,
            totalAmount: $contract?->total_amount,
            collected: $collected,
            outstanding: $outstanding,
        );
    }

    public function toArray(): array
    {
        return [
            'matter_code' => $this->matterCode,
            'matter_type_name' => $this->matterTypeName,
            'client_name' => $this->clientName,
            'lead_lawyer_name' => $this->leadLawyerName,
            'contract_code' => $this->contractCode,
            'contract_status' => $this->contractStatus?->value,
            'total_amount' => $this->totalAmount,
            'collected' => $this->collected,
            'outstanding' => $this->outstanding,
        ];
    }

    private static function parentOf(Model $child, string $relation): Model
    {
        $loaded = $child->relationLoaded($relation) ? $child->getRelation($relation) : null;

        return $loaded ?? $child->{$relation}()->withTrashed()->firstOrFail();
    }
}
