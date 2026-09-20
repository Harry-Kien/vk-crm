<?php

namespace App\Exceptions;

use DomainException;

/**
 * Mã loại vụ việc đã có một dòng CÒN DÙNG mang cùng mã. Tính duy nhất này không còn ở DB (xem
 * migration 2026_09_20_000001 và docblock `MatterType::booted()`), nên thông điệp phải nói rõ
 * dòng nào đang chặn và cách gỡ, không chỉ "đã tồn tại".
 */
class DuplicateMatterTypeCode extends DomainException
{
    public static function make(string $code): self
    {
        return new self(__('exceptions.duplicate_matter_type_code', ['code' => $code]));
    }
}
