<?php

namespace App\Enums;

/**
 * Tiêu chí đã khớp một `ConflictMatch` (SPEC §6.10 bước 2), theo thứ tự tin cậy giảm dần:
 * `Hash` (số căn cước, "chắc chắn") > `Phone` (số điện thoại, "rất khả nghi") > `Name` (tên đã
 * chuẩn hoá, "cần người xem xét"). Mô tả cách CHÚNG TA so khớp — không phải nội dung của hồ sơ
 * kia — nên lộ ra ngoài Action không vi phạm ranh giới lộ thông tin của SPEC §6.10 đoạn cuối.
 * Chỉ `Hash` và `Phone` đủ tin cậy để một `ConflictMatch` lên mức đỏ; xem `RunConflictCheck`.
 *
 * `SameMatter` (M6.5 Task 8, R13b): không phải một khớp với LỊCH SỬ (một bản ghi ở vụ việc KHÁC),
 * mà là hai khách hàng của văn phòng ở hai vai đối lập NGAY TRONG vụ việc đang xét — luôn Đỏ,
 * không có "tầng tin cậy" nào để đo vì đây không phải một phép so khớp định danh.
 */
enum ConflictMatchTier: string
{
    case Hash = 'hash';
    case Phone = 'phone';
    case Name = 'name';
    case SameMatter = 'same_matter';

    public function label(): string
    {
        return __('conflicts.tier.'.$this->value);
    }
}
