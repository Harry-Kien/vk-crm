<?php

namespace App\Exceptions;

use DomainException;

/**
 * Phụ lục hợp đồng chỉ thêm, không sửa, không xoá — cùng thiết bị với {@see StageLogViewImmutable}
 * trên `stage_log_views`: không một cột nào của bảng này là một trạng thái còn đi tiếp, toàn bộ
 * bốn cột (giá trị cũ, giá trị mới, lý do, ngày ký) đều là lời khai tại một thời điểm.
 */
class ContractAmendmentImmutable extends DomainException
{
    public static function make(): self
    {
        return new self(__('exceptions.contract_amendment_immutable'));
    }
}
