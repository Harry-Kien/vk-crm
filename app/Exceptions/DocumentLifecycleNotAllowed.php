<?php

namespace App\Exceptions;

use App\Enums\DocumentStatus;
use App\Models\Document;
use DomainException;

/**
 * Vòng đời văn bản nhóm B (SPEC §4.11): `internal_draft` → `pending_approval` → `signed_filed` →
 * `published`. SPEC dựng luật chuyển trạng thái nhưng im lặng về AI trình duyệt và AI đánh dấu đã
 * ký, đã nộp — phán quyết R9 (Task 16) lấp chỗ đó: "Trình duyệt" đòi `document.update`, "Đã ký,
 * đã nộp" đòi `document.publish`, "Trả về bản nháp" (vòng sửa 1) cũng đòi `document.publish`.
 * `SubmitDocumentForApproval`, `MarkDocumentSignedFiled` và `ReturnDocumentToDraft` ném lớp này
 * khi một trong ba bước đó bị gọi sai chỗ; `RegroupDocument` cũng ném nó khi ai đó cố đưa một tài
 * liệu RA KHỎI nhóm B (sang A hoặc C) mà chưa từng ký, nộp và không kèm lý do hợp lệ — đường
 * "giặt" một bản nháp đơn thành nhóm C rồi công bố thẳng mà `docs/docs-2` ghi lại.
 *
 * **Vòng sửa 1: nhóm D không còn nằm trong cổng rời-nhóm-B.** Phán quyết R9 mở rộng nói thẳng:
 * chuyển VÀO nhóm D luôn được phép với `document.update` — nó chỉ SIẾT lại (khách mất quyền xem
 * ngay, xem hook `saving` của `Document`), và đó là đường DUY NHẤT để rút một tài liệu nhóm B đã
 * lỡ công bố ra khỏi tầm mắt khách trước khi `M7` có `RetractDocument` thật. `RegroupDocument` vì
 * vậy chỉ ném lớp này khi nhóm ĐÍCH là A hoặc C, không bao giờ khi nhóm đích là D.
 *
 * Cùng họ `DomainException` với `DocumentNotPublishable` và `DocumentGroupNotChangeable`, nên
 * `ReportsActionFailures` bắt được nó mà không cần thêm một nhánh `catch` nào — xem docblock của
 * trait đó cho lý do bốn họ exception đi bốn đường khác nhau.
 *
 * Không câu nào nhắc tới quyền: mọi factory method đều nói về TRẠNG THÁI của bản ghi, không về
 * người hỏi — cùng lý lẽ với `DocumentNotPublishable::notSignedAndFiled()`. Mỗi câu nêu trạng thái
 * HIỆN TẠI (SPEC §8.4) vì việc cần làm tiếp theo khác nhau tuỳ chỗ đang đứng.
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
     *
     * **Hai câu, tuỳ trạng thái HIỆN TẠI — vòng sửa 2.** `pending_approval` có một đường quay lại
     * thật (`ReturnDocumentToDraft`, ruling vòng sửa 1): câu từ chối phải trỏ TỚI đường đó, không
     * đẩy người dùng đi tải một bản trùng lặp không cần thiết. `signed_filed`/`published` không có
     * đường quay lại nào (`ReturnDocumentToDraft` chỉ chấp nhận `pending_approval`), nên câu cũ —
     * tải lên một bản mới — vẫn đúng cho hai trạng thái đó.
     */
    public static function notInternalDraft(Document $document): self
    {
        $key = $document->status === DocumentStatus::PendingApproval
            ? 'documents.lifecycle.not_internal_draft_pending'
            : 'documents.lifecycle.not_internal_draft';

        return new self(__($key, [
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
     * R9 mở rộng (vòng sửa 1): chuyển nhóm RA KHỎI B, SANG A HOẶC C (không phải D — xem docblock
     * lớp), đòi `document.publish` VÀ MỘT TRONG HAI: tài liệu đã `wasPublishedToClient()` hoặc
     * `signed_filed`, HOẶC người chuyển nhập một lý do sửa nhầm nhóm hợp lệ (`RegroupDocument`
     * đã tự kiểm độ dài trước khi tới đây — ném lớp này nghĩa là CẢ HAI điều kiện đều không đạt).
     * Không có cổng này, `RegroupDocument` là đường vòng qua toàn bộ vòng đời nhóm B — đổi B → C
     * rồi công bố ngay, đúng lỗ hổng `docs/docs-2` ghi lại.
     */
    public static function notReadyToLeaveGroupB(Document $document): self
    {
        return new self(__('documents.lifecycle.not_ready_to_leave_group_b', [
            'status' => $document->status->label(),
        ]), $document);
    }

    /**
     * Lý do sửa nhầm nhóm có nhập nhưng chưa đủ dài (vòng sửa 1, R9 mở rộng phần (b)). Tách riêng
     * khỏi `notReadyToLeaveGroupB()` vì đây là một CÂU KHÁC: người dùng đã hiểu đúng đường (đang
     * gõ lý do), chỉ cần gõ thêm — không phải đi tìm nút "Trình duyệt"/"Đánh dấu đã ký, đã nộp".
     */
    public static function misfilingReasonTooShort(Document $document): self
    {
        return new self(__('documents.lifecycle.misfiling_reason_too_short'), $document);
    }

    /**
     * `ReturnDocumentToDraft` (ruling, vòng sửa 1) chỉ trả về bản nháp được một tài liệu đang
     * `pending_approval` — cùng hình dạng với `notPendingApproval()` của `MarkDocumentSignedFiled`
     * (cùng điều kiện trạng thái), nhưng câu chữ khác: một câu nói "chưa đánh dấu được" không hợp
     * với thao tác ĐI NGƯỢC vòng đời, nên tách riêng để không nói sai hướng.
     */
    public static function notPendingApprovalToReturn(Document $document): self
    {
        return new self(__('documents.lifecycle.not_pending_approval_to_return', [
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
