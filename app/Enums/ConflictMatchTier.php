<?php

namespace App\Enums;

/**
 * Tiêu chí đã khớp một `ConflictMatch` (SPEC §6.10 bước 2), theo thứ tự tin cậy giảm dần:
 * `Hash` (số căn cước, "chắc chắn") > `Phone` (số điện thoại, "rất khả nghi") > `Name` (tên đã
 * chuẩn hoá, "cần người xem xét"). Mô tả cách CHÚNG TA so khớp — không phải nội dung của hồ sơ
 * kia — nên lộ ra ngoài Action không vi phạm ranh giới lộ thông tin của SPEC §6.10 đoạn cuối.
 * Chỉ `Hash` và `Phone` đủ tin cậy để một `ConflictMatch` lên mức đỏ; xem `RunConflictCheck`.
 */
enum ConflictMatchTier: string
{
    case Hash = 'hash';
    case Phone = 'phone';
    case Name = 'name';

    public function label(): string
    {
        return __('conflicts.tier.'.$this->value);
    }
}
