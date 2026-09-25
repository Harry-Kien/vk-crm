<?php

namespace App\Support\Billing;

use App\Enums\InstalmentState;
use App\Models\Instalment;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;

/**
 * Một dòng tiền — một đợt thanh toán — đúng như kế toán được thấy (SPEC §5, bổ sung 2026-09-19,
 * sửa 2026-09-24, đoạn "Ranh giới của kế toán"). Kế hoạch M9 giao nó làm kiểu dữ liệu duy nhất
 * của trang "Công nợ" (Task 8) và của thư nhắc đợt quá hạn (Task 11); cả hai chưa có lúc Task 3.
 *
 * **Ranh giới lộ thông tin có chủ đích**, tiền lệ `ConflictMatch` (SPEC §6.10): kế toán có
 * `billing.view` nhưng không có `matter.view`, nên đây là TOÀN BỘ những gì một màn hình tiền của
 * kế toán được mang: mã hồ sơ, loại vụ việc, tên khách hàng, tên đợt, các con số (số tiền, đã thu,
 * còn lại), ngày đến hạn, và trạng thái hiển thị. Object này KHÔNG được thêm trường nào khác —
 * tiêu đề vụ việc, tóm tắt, mô tả nội bộ, tài liệu, tiến độ, các bên, ghi chú nội bộ của hợp đồng
 * hay của đợt, id vụ việc (một id là nửa đường tới một liên kết vào trang vụ việc). Tên khách hàng
 * là điểm nới rộng THẬT: không có tên thì không lập được phiếu thu (SPEC §5 bổ sung M9).
 * `readonly` để không ai gắn thêm thuộc tính sau khi tạo; test ghim đúng chín trường.
 *
 * Nơi dùng cần khoá của đợt để ghi khoản thu thì giữ `Instalment` (hoặc khoá của collection) BÊN
 * CẠNH dòng này, không nhét vào nó.
 */
final readonly class AccountantBillingRow implements Arrayable
{
    public function __construct(
        public string $matterCode,
        public string $matterTypeName,
        public string $clientName,
        public string $instalmentName,
        public int $amount,
        public int $collected,
        public int $outstanding,
        public ?CarbonImmutable $dueDate,
        public InstalmentState $state,
    ) {}

    /**
     * Đường DUY NHẤT từ model sang dòng này: nó quyết định cái gì đi qua ranh giới, nên các nơi
     * dùng không tự chép từng trường từ `Matter`.
     *
     * **Ba giá trị suy ra do nơi gọi đưa vào**, từ định nghĩa duy nhất của chúng —
     * `Instalment::state()` (đã có từ M9 Task 2), và "đã thu" / "còn lại" mà M9 Task 5 định nghĩa
     * (`Instalment::outstanding()`, `BillingSummary`: còn lại trừ phần đã miễn — chưa tồn tại lúc
     * Task 3 viết lớp này). Lớp này không tính gì về tiền; nó cũng không chạy truy vấn tổng hợp
     * nào, để trang "Công nợ" có thể tính tổng một lần ở tầng SQL thay vì một lần mỗi dòng.
     *
     * **Cha đã nạp thì không truy vấn.** Vụ việc, loại vụ việc và khách hàng đều xoá mềm được;
     * nơi gọi nạp sẵn `contract.matter.matterType` và `contract.matter.client` thì không có truy
     * vấn nào ở đây, và nếu chưa nạp (hoặc đã nạp mà ra `null` vì bị xoá mềm) thì hỏi lại bằng
     * `withTrashed()` — kế toán vẫn phải lập được phiếu thu cho khách đã lưu hồ sơ.
     */
    public static function fromInstalment(
        Instalment $instalment,
        int $collected,
        int $outstanding,
        InstalmentState $state,
    ): self {
        $matter = self::parentOf($instalment->contract, 'matter');

        return new self(
            matterCode: $matter->code,
            matterTypeName: self::parentOf($matter, 'matterType')->name,
            clientName: self::parentOf($matter, 'client')->name,
            instalmentName: $instalment->name,
            amount: $instalment->amount,
            collected: $collected,
            outstanding: $outstanding,
            dueDate: $instalment->due_date?->toImmutable(),
            state: $state,
        );
    }

    public function toArray(): array
    {
        return [
            'matter_code' => $this->matterCode,
            'matter_type_name' => $this->matterTypeName,
            'client_name' => $this->clientName,
            'instalment_name' => $this->instalmentName,
            'amount' => $this->amount,
            'collected' => $this->collected,
            'outstanding' => $this->outstanding,
            'due_date' => $this->dueDate?->toDateString(),
            'state' => $this->state->value,
        ];
    }

    /** Chỉ cho quan hệ `BelongsTo` trỏ tới model có `SoftDeletes`. */
    private static function parentOf(Model $child, string $relation): Model
    {
        $loaded = $child->relationLoaded($relation) ? $child->getRelation($relation) : null;

        return $loaded ?? $child->{$relation}()->withTrashed()->firstOrFail();
    }
}
