<?php

namespace App\Support\Billing;

use App\Enums\PaymentMethod;
use App\Models\Contract;
use App\Models\Matter;
use App\Models\Payment;
use App\Policies\Concerns\ChecksBillingAccess;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Một KHOẢN THU — đúng như kế toán được thấy (lượt rà soát cuối M9, I2: mục "Khoản thu gần đây"
 * của trang "Công nợ"). Anh em của {@see AccountantBillingRow} (một ĐỢT), cùng ranh giới lộ thông
 * tin có chủ đích (SPEC §5, "Ranh giới của kế toán"; tiền lệ `ConflictMatch`, SPEC §6.10): kế toán
 * có `billing.view` nhưng không có `matter.view`, nên đây là TOÀN BỘ những gì dòng đó được mang —
 * mã hồ sơ, tên khách hàng, tên đợt, số tiền, ngày thu, cách nhận, mã giao dịch/số biên lai.
 *
 * KHÔNG thêm trường nào khác: tiêu đề vụ việc, ghi chú nội bộ của khoản thu/đợt/hợp đồng, lý do
 * huỷ, luật sư được ghi doanh thu, id vụ việc (một id là nửa đường tới một liên kết vào trang vụ
 * việc). `readonly` để không ai gắn thêm thuộc tính sau khi tạo; test ghim đúng bảy trường.
 *
 * Nơi dùng cần khoá của khoản thu để huỷ nó thì giữ `Payment` BÊN CẠNH dòng này, không nhét vào nó.
 */
final readonly class AccountantPaymentRow implements Arrayable
{
    public function __construct(
        public string $matterCode,
        public string $clientName,
        public string $instalmentName,
        public int $amount,
        public CarbonImmutable $paidOn,
        public PaymentMethod $method,
        public ?string $reference,
    ) {}

    /**
     * Đường DUY NHẤT từ model sang dòng này. Cha đã nạp thì không truy vấn. Cùng hai quy tắc của
     * {@see AccountantBillingRow::fromInstalment()}: khách hàng xoá mềm vẫn đọc được tên (kế toán
     * vẫn phải lập phiếu thu); vụ việc thì KHÔNG đi qua `withTrashed()` — một vụ đã xoá mềm ra
     * ngoài ở đây (`firstOrFail()`), đúng ranh giới {@see ChecksBillingAccess::canSeeBilling()} đã
     * đóng.
     */
    public static function fromPayment(Payment $payment): self
    {
        $instalment = $payment->instalment;
        $matter = self::matterOf($instalment->contract);
        $client = $matter->relationLoaded('client') && $matter->client !== null
            ? $matter->client
            : $matter->client()->withTrashed()->firstOrFail();

        return new self(
            matterCode: $matter->code,
            clientName: $client->name,
            instalmentName: $instalment->name,
            amount: $payment->amount,
            paidOn: $payment->paid_on->toImmutable(),
            method: $payment->method,
            reference: $payment->reference,
        );
    }

    public function toArray(): array
    {
        return [
            'matter_code' => $this->matterCode,
            'client_name' => $this->clientName,
            'instalment_name' => $this->instalmentName,
            'amount' => $this->amount,
            'paid_on' => $this->paidOn->toDateString(),
            'method' => $this->method->value,
            'reference' => $this->reference,
        ];
    }

    /** `Contract::matter()`, KHÔNG `withTrashed()` — xem docblock `fromPayment()`. */
    private static function matterOf(Contract $contract): Matter
    {
        $loaded = $contract->relationLoaded('matter') ? $contract->getRelation('matter') : null;

        return $loaded ?? $contract->matter()->firstOrFail();
    }
}
