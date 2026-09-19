<?php

namespace App\Exceptions;

use DomainException;

/**
 * Một lần đổi nhóm tài liệu bị chặn ở tầng model (xem `Document::booted()`), tức là nó đã đi
 * vòng qua `RegroupDocument`.
 *
 * Là `DomainException` cùng họ với `DocumentNotPublishable` và `MatterNotDestroyable`: mọi màn
 * hình M4 gọi Action đã được dặn bắt lớp cha đó và đổi thành lỗi trên form, nên nhánh này không
 * cần một chỗ bắt riêng. Người đọc câu này thường KHÔNG phải người thiếu quyền — họ có thể có
 * thừa quyền và chỉ đang bấm nhầm màn hình — nên câu nói ra đường đi đúng chứ không nói về quyền.
 */
class DocumentGroupNotChangeable extends DomainException
{
    public static function leavingInternalGroup(): self
    {
        return new self(__('documents.regroup.leaving_internal_group'));
    }
}
