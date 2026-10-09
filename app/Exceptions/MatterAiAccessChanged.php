<?php

namespace App\Exceptions;

use App\Actions\Matter\SetMatterAiAccess;
use DomainException;

/**
 * Người dùng xác nhận "bật" (hay "tắt") truy cập qua AI cho một vụ việc, nhưng dưới khoá vụ việc nó
 * ĐÃ ở đúng trạng thái đó — một người khác vừa đổi trong lúc hộp xác nhận còn mở
 * ({@see SetMatterAiAccess}). Không làm gì cả, cùng luật với
 * {@see MatterPortalPublicationChanged}: lật cờ theo trạng thái hiện tại sẽ làm đúng điều NGƯỢC với
 * câu người dùng vừa đọc, và ghi một dòng audit cho một lần đổi không xảy ra.
 */
class MatterAiAccessChanged extends DomainException
{
    public static function make(): self
    {
        return new self(__('matters.ai_access.changed'));
    }
}
