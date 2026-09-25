<?php

namespace App\Exceptions;

use App\Models\Document;
use DomainException;

/**
 * Vòng đời văn bản nhóm B (SPEC §4.11): `internal_draft` → `pending_approval` → `signed_filed` →
 * `published`. SPEC dựng luật chuyển trạng thái nhưng im lặng về AI trình duyệt và AI đánh dấu đã
 * ký, đã nộp — phán quyết R9 (Task 16) lấp chỗ đó: "Trình duyệt" đòi `document.update`, "Đã ký,
 * đã nộp" đòi `document.publish`. `SubmitDocumentForApproval` và `MarkDocumentSignedFiled` ném
 * lớp này khi một trong hai bước đó bị gọi sai chỗ; `RegroupDocument` cũng ném nó khi ai đó cố
 * đưa một tài liệu RA KHỎI nhóm B trước khi nó tới `signed_filed` — đường "giặt" một bản nháp đơn
 * thành nhóm C rồi công bố thẳng mà `docs/docs-2` ghi lại.
 *
 * Cùng họ `DomainException` với `DocumentNotPublishable` và `DocumentGroupNotChangeable`, nên
 * `ReportsActionFailures` bắt được nó mà không cần thêm một nhánh `catch` nào — xem docblock của
 * trait đó cho lý do bốn họ exception đi bốn đường khác nhau.
 *
 * Không câu nào nhắc tới quyền: `notGroupB()`, `notInternalDraft()`, `notPendingApproval()` và
 * `notReadyToLeaveGroupB()` đều nói về TRẠNG THÁI của bản ghi, không về người hỏi — cùng lý lẽ với
 * `DocumentNotPublishable::notSignedAndFiled()`. Mỗi câu nêu trạng thái HIỆN TẠI (SPEC §8.4) vì
 * việc cần làm tiếp theo khác nhau tuỳ chỗ đang đứng.
 */
class DocumentLifecycleNotAllowed extends DomainException
{
    private function __construct(string $message, public readonly ?Document $document = null)
    {
        parent::__construct($message);
    }

    /**
     * `SubmitDocumentForApproval` và `MarkDocumentSignedFiled` chỉ áp dụng cho nhóm B (văn bản do
     * văn phòng phát hành): nhóm C là văn bản của cơ quan nhà nước, văn phòng không soạn và không
     * ký nên không có gì để trình duyệt hay đánh dấu đã nộp; nhóm A và D chưa từng ở trạng thái
     * `internal_draft`/`pending_approval` theo nghĩa vòng đời này.
     */
    public static function notGroupB(Document $document): self
    {
        return new self(__('documents.lifecycle.not_group_b'), $document);
    }

    /**
     * `SubmitDocumentForApproval` chỉ trình duyệt được một bản thảo `internal_draft`. Gọi lại lần
     * hai trên một tài liệu đã ở `pending_approval` (hay xa hơn) không phải một thao tác — nó là
     * một dấu hiệu người dùng đang thao tác trên một trang mở đã lâu.
     */
    public static function notInternalDraft(Document $document): self
    {
        return new self(__('documents.lifecycle.not_internal_draft', [
            'status' => $document->status->label(),
        ]), $document);
    }

    /**
     * `MarkDocumentSignedFiled` chỉ đánh dấu được một tài liệu đang `pending_approval` — bước
     * trình duyệt phải chạy trước, đúng thứ tự SPEC §4.11 đòi.
     */
    public static function notPendingApproval(Document $document): self
    {
        return new self(__('documents.lifecycle.not_pending_approval', [
            'status' => $document->status->label(),
        ]), $document);
    }

    /**
     * R9: chuyển nhóm RA KHỎI B đòi `document.publish` VÀ tài liệu đã ở `signed_filed` hoặc
     * `published`. Không có cổng này, `RegroupDocument` là đường vòng qua toàn bộ vòng đời nhóm B
     * — đổi B → C (hoặc thẳng B → D → C) rồi công bố ngay, đúng lỗ hổng `docs/docs-2` ghi lại. Áp
     * dụng bất kể nhóm ĐÍCH là gì: một bản nháp B chưa ký không được rời khỏi B theo bất kỳ hướng
     * nào, kể cả vào nhóm D — nếu không, D chỉ là một trạm trung chuyển để rồi rời D (chỉ đòi
     * `document.publish`, không đòi trạng thái) sang C mà không qua vòng đời nào cả.
     */
    public static function notReadyToLeaveGroupB(Document $document): self
    {
        return new self(__('documents.lifecycle.not_ready_to_leave_group_b', [
            'status' => $document->status->label(),
        ]), $document);
    }

    /** Chính tài liệu đã bị xoá mềm. */
    public static function trashed(Document $document): self
    {
        return new self(__('documents.lifecycle.trashed'), $document);
    }

    /** Vụ việc chủ quản đã bị xoá mềm. */
    public static function matterUnavailable(Document $document): self
    {
        return new self(__('documents.lifecycle.matter_unavailable'), $document);
    }

    /** Dòng dữ liệu không còn tồn tại khi Action đọc lại nó trong transaction. */
    public static function missing(): self
    {
        return new self(__('documents.lifecycle.missing'));
    }
}
