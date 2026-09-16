<?php

namespace App\Exceptions;

use App\Models\Matter;
use DomainException;

/**
 * `publish = true` đòi `matter.is_published_to_portal = true` (SPEC §7.3: nút công bố chỉ bật
 * mặc định cho vụ việc đã công bố portal). Một `StageLog.is_published = true` trên một vụ việc
 * chưa công bố portal sẽ nằm chờ im lặng — không `notified_at`, không sự kiện nào từng dispatch
 * — rồi lộ ra NGUYÊN backlog cho khách ngay khoảnh khắc ai đó bật công tắc công bố portal sau
 * này. Chặn từ gốc thay vì chỉ chặn tác dụng phụ (SPEC §6.2 bước 6).
 */
class MatterNotPublishedToPortal extends DomainException
{
    public static function make(Matter $matter): self
    {
        return new self(__('exceptions.matter_not_published_to_portal', ['code' => $matter->code]));
    }
}
