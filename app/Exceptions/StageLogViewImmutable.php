<?php

namespace App\Exceptions;

use DomainException;

/**
 * Biên bản "khách đã đọc dòng tiến độ này" (SPEC §4.18) là BẰNG CHỨNG, không phải số liệu: nó
 * tồn tại để văn phòng chứng minh mình đã báo, và dấu thời gian của nó là toàn bộ giá trị đó.
 * Một dòng sửa được là một dòng không chứng minh được gì. Cùng thiết bị với
 * {@see StageLogImmutable} trên nhật ký tiến độ.
 */
class StageLogViewImmutable extends DomainException
{
    public static function make(): self
    {
        return new self(__('exceptions.stage_log_view_immutable'));
    }
}
