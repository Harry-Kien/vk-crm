<?php

namespace App\Support\Intake;

use App\Models\IntakeRequest;
use Illuminate\Support\Collection;

/**
 * Kết quả dò trùng lúc nhập (M10 R4) — CHỈ những gì người nhập được phép biết.
 *
 * Ranh giới lộ thông tin, theo R4 và M6.5 R4:
 *  - `sameIdentity`: các bản ghi cũ khớp ĐÚNG SĐT hoặc dấu băm CCCD (không bao giờ chỉ tên) mà người
 *    nhập XEM ĐƯỢC. `hasHiddenSameIdentity` nói còn có bản khác khớp mà họ không xem được — không
 *    mã, không ngày, không đếm — để màn hình vẫn nói được "số này đã liên hệ văn phòng" mà không lộ
 *    thêm gì (khớp xung đột Vàng đã hiện mã `TN-…` và ngày cho người có quyền chạy kiểm tra).
 *  - `sameName`: khớp theo tên, CHỈ điền khi người nhập có `intake.viewAny`.
 *  - `isExistingClient`: một câu hỏi có/không "số này đã là khách của văn phòng" — không tên, không
 *    mã, không danh sách. Luôn `false` khi tra khách bị giới hạn tần suất hoặc khách đó thuộc vụ
 *    `restricted` mà người nhập không được biết (`clientLookupUnavailable` nói tra khách đã bị chặn
 *    vì giới hạn, để "false" không bị đọc thành "chắc chắn không phải khách").
 */
final readonly class IntakeDuplicates
{
    /**
     * @param  Collection<int, IntakeRequest>  $sameIdentity
     * @param  Collection<int, IntakeRequest>  $sameName
     */
    public function __construct(
        public Collection $sameIdentity,
        public bool $hasHiddenSameIdentity,
        public Collection $sameName,
        public bool $isExistingClient,
        public bool $clientLookupUnavailable,
    ) {}

    public function isEmpty(): bool
    {
        return $this->sameIdentity->isEmpty()
            && ! $this->hasHiddenSameIdentity
            && $this->sameName->isEmpty()
            && ! $this->isExistingClient;
    }
}
