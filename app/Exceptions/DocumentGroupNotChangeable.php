<?php

namespace App\Exceptions;

use DomainException;

/**
 * Một lần đổi nhóm tài liệu bị chặn ở tầng model (xem `Document::booted()`), tức là nó đã đi
 * vòng qua `RegroupDocument`. Từ M7 Task 7, `RegroupDocument` cũng ném lớp này cho hai lần chuyển
 * VÀO nhóm D mà một đường rút duy nhất không cho phép (hai factory cuối).
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

    /**
     * M7 Task 7 — một đường rút duy nhất. `RegroupDocument` từ chối đưa vào nhóm D một tài liệu
     * ĐANG ra tới khách (`Document::isReleasedToPortal()`): rút khỏi tầm mắt khách là việc của
     * `RetractDocument`, thứ ghi lý do khách đọc được và giữ bằng chứng tải. Câu chỉ tới nút
     * "Rút lại" và nói ai bấm được nó (trợ lý không có `document.publish`).
     */
    public static function releasedToClientUseRetract(): self
    {
        return new self(__('retraction.blocked.regroup_to_internal'));
    }

    /**
     * M7 Task 7 — `RegroupDocument` từ chối đưa vào nhóm D một tài liệu ĐÃ RÚT: dòng "Văn phòng đã
     * rút lại tài liệu này" trên cổng đọc từ chính bản ghi đó và không bao giờ trả nhóm D
     * (`Document::retractionNoticesFor()`), nên vào D là xoá lời giải thích khỏi tay khách. Cùng lý
     * lẽ `DocumentPolicy::delete()` từ chối tài liệu đã rút.
     */
    public static function retractedStaysVisibleToClient(): self
    {
        return new self(__('retraction.blocked.regroup_retracted_to_internal'));
    }

    /**
     * Việc sau gộp M9 + M10 (làn fu3, Task 1 mục D — N3 của rà soát cuối làn m9f): `RegroupDocument`
     * từ chối đưa RA khỏi nhóm D một tệp mà bản ghi tiền trỏ tới (biên lai của khoản thu, bản scan
     * phụ lục hợp đồng — `Document::isReferencedByBillingRecord()`). Cùng định nghĩa và cùng giọng
     * với lời từ chối của `RetractDocument` và `DocumentPolicy::delete`.
     */
    public static function referencedByBillingRecord(): self
    {
        return new self(__('documents.regroup.billing_reference'));
    }
}
