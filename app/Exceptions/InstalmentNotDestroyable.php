<?php

namespace App\Exceptions;

use DomainException;

/**
 * Chặn xoá cứng một đợt thanh toán (`Instalment::booted()`). Đợt của hợp đồng `draft` xoá cứng
 * được; từ `active` trở đi chỉ `cancelled` (qua `AmendContract`, Task khác) hoặc `waived`, không
 * bao giờ xoá — `instalments` cũng cố ý không có `deleted_at` (M9 quyết định 4).
 */
class InstalmentNotDestroyable extends DomainException
{
    public static function make(): self
    {
        return new self(__('exceptions.instalment_not_destroyable'));
    }
}
