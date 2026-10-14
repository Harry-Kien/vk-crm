<?php

namespace App\Exceptions;

use App\Actions\Matter\RetractStageLog;
use DomainException;

/**
 * Làn fm, mục A2: {@see RetractStageLog} chỉ rút một dòng tiến độ ĐANG công bố cho khách. Dòng nội
 * bộ (chưa từng công bố) hay dòng đã rút rồi không có gì để rút; dòng không còn (hoặc thuộc vụ đã
 * huỷ) trả câu chung. Câu hỏi về TRẠNG THÁI bản ghi, không phải quyền — `DomainException` thuần,
 * cùng loại với `DocumentNotRetractable`.
 */
class StageLogNotRetractable extends DomainException
{
    public static function notPublished(): self
    {
        return new self(__('lifecycle.stage_log.not_published'));
    }

    public static function alreadyRetracted(): self
    {
        return new self(__('lifecycle.stage_log.already_retracted'));
    }

    public static function missing(): self
    {
        return new self(__('lifecycle.stage_log.missing'));
    }
}
