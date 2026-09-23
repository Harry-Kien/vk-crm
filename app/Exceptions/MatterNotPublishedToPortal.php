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
 *
 * **Hai nhà máy, vì câu chữ nói với người dùng về hai thứ khác nhau.** M6 Task 5 mang đúng luật
 * này sang bảng `deadlines` (một mốc `is_published = true` trên một vụ việc chưa bật portal cũng
 * nằm chờ rồi lộ ra nguyên loạt). Lúc đó `make()` vẫn ném ra được, nhưng câu của nó mở đầu bằng
 * "Không thể công bố **dòng tiến độ**…" — một câu nói sai về thứ người dùng vừa bấm, trên đúng
 * màn hình họ đang nhìn. Nên `forDeadline()` có câu riêng; LUẬT thì vẫn là một, và lớp exception
 * cũng vậy, nên một `catch` duy nhất bắt được cả hai.
 */
class MatterNotPublishedToPortal extends DomainException
{
    public static function make(Matter $matter): self
    {
        return new self(__('exceptions.matter_not_published_to_portal', ['code' => $matter->code]));
    }

    public static function forDeadline(Matter $matter): self
    {
        return new self(__('exceptions.deadline_matter_not_published_to_portal', ['code' => $matter->code]));
    }
}
