<?php

namespace App\Exceptions;

use App\Models\Document;
use DomainException;

/**
 * `PublishDocument` từ chối công bố (SPEC §6.5). Mỗi factory method ứng với đúng MỘT lý do, và
 * mỗi lý do có một câu tiếng Việt riêng nói rõ việc cần làm tiếp theo — cùng luật với
 * `FileRejected` và cùng điều SPEC §8.4 cấm ("không được nói Upload failed"). Người đọc những câu
 * này là một trợ lý đang thao tác vội giữa buổi làm việc, không phải người viết mã.
 *
 * Là một `DomainException` chứ không phải `ValidationException` vì không câu nào trong số này nói
 * về một ô nhập sai: chúng nói về TRẠNG THÁI của bản ghi (nhóm, vòng đời, đã xoá mềm). Việc mang
 * sang từ rà soát M2/M3 buộc mọi màn hình M4 gọi Action phải bắt `DomainException` và đổi thành
 * lỗi trên form, nên Task 6 bắt đúng lớp cha này là đủ cho cả năm nhánh.
 *
 * `$document` được giữ lại trên exception để chỗ bắt còn ghi log hoặc chỉ đúng dòng trên bảng
 * được. Thông điệp không bao giờ nhắc tới id, tiêu đề hay tên tệp — thứ duy nhất của bản ghi đi
 * vào câu chữ là NHÃN TRẠNG THÁI ở `notSignedAndFiled()`, vì không có nó thì người đọc không biết
 * mình đang đứng ở đâu trong vòng đời nhóm B và phải làm gì tiếp.
 */
class DocumentNotPublishable extends DomainException
{
    private function __construct(string $message, public readonly ?Document $document = null)
    {
        parent::__construct($message);
    }

    /**
     * Nhóm D — hồ sơ công việc nội bộ. SPEC §4.11 và §6.5 bước 1 gọi đây là chặn TUYỆT ĐỐI: không
     * trạng thái nào, không quyền nào, không lựa chọn nào của người công bố mở được nó.
     */
    public static function internalGroup(Document $document): self
    {
        return new self(__('documents.publish.internal_group'), $document);
    }

    /**
     * Nhóm B chưa đi hết vòng đời `internal_draft → pending_approval → signed_filed` (SPEC §4.11).
     * Thông điệp phải nói ra trạng thái hiện tại, vì việc cần làm tiếp theo khác nhau tuỳ chỗ
     * đang đứng — còn là bản nháp thì trình duyệt, đã trình duyệt thì chờ nộp và đánh dấu đã nộp.
     */
    public static function notSignedAndFiled(Document $document): self
    {
        return new self(__('documents.publish.not_signed_and_filed', [
            'status' => $document->status->label(),
        ]), $document);
    }

    /**
     * Công bố mà tắt `client_can_view`. Xem docblock `PublishDocument`: đó không phải một lần
     * công bố, đó là một lần thu hồi, và thu hồi không nằm trong SPEC §6.5.
     */
    public static function withoutClientView(Document $document): self
    {
        return new self(__('documents.publish.without_client_view'), $document);
    }

    /** Vụ việc chủ quản đã bị xoá mềm: không có gì để công bố ra tới khách nữa. */
    public static function matterUnavailable(Document $document): self
    {
        return new self(__('documents.publish.matter_unavailable'), $document);
    }

    /** Chính tài liệu đã bị xoá mềm. */
    public static function trashed(Document $document): self
    {
        return new self(__('documents.publish.trashed'), $document);
    }

    /**
     * Dòng dữ liệu không còn tồn tại khi `PublishDocument` đọc lại nó trong transaction — ai đó
     * vừa xoá cứng bản ghi giữa lúc màn hình mở và lúc bấm nút.
     */
    public static function missing(): self
    {
        return new self(__('documents.publish.missing'));
    }
}
