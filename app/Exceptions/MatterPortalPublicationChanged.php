<?php

namespace App\Exceptions;

use DomainException;

/**
 * Final review C-M1: người dùng xác nhận "công bố" (hay "thôi công bố") nhưng dưới khoá vụ việc
 * ĐÃ ở đúng trạng thái đó — một người khác vừa đổi trong lúc hộp xác nhận còn mở. Không làm gì cả:
 * lật công tắc theo trạng thái hiện tại sẽ làm đúng điều NGƯỢC với câu người dùng vừa đọc.
 */
class MatterPortalPublicationChanged extends DomainException
{
    public static function make(): self
    {
        return new self(__('matters.actions.portal_publication_changed'));
    }
}
