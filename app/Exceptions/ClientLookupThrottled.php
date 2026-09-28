<?php

namespace App\Exceptions;

use DomainException;

/**
 * Fix round 1, I1 (Important, M6.5 Task 6): `App\Support\ClientLookupThrottle` đã chạm trần 20
 * lần/giờ cho actor này — xem docblock lớp đó cho lý do một bộ đếm dùng chung cho cả tra
 * (`FindClientByIdentifier`) lẫn dò trùng khi tạo khách mới (`CreateClient`).
 */
class ClientLookupThrottled extends DomainException
{
    public static function make(): self
    {
        return new self(__('exceptions.client_lookup_throttled'));
    }
}
