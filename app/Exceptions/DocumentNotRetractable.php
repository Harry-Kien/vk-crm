<?php

namespace App\Exceptions;

use App\Actions\Document\RetractDocument;
use App\Models\Document;
use DomainException;

/**
 * M7 Task 7: {@see RetractDocument} từ chối vì TRẠNG THÁI của tài liệu (không vì quyền — lời từ
 * chối về quyền là `AuthorizationException`, và `ReportsActionFailures` vẽ nó bằng một câu chung).
 * Cùng họ `DomainException` với `DocumentNotPublishable`, nên màn hình hiện nguyên câu mà không cần
 * thêm nhánh `catch` nào. Không câu nào nhắc id, tiêu đề hay tên tệp.
 */
class DocumentNotRetractable extends DomainException
{
    private function __construct(string $message, public readonly ?Document $document = null)
    {
        parent::__construct($message);
    }

    /** Dòng tài liệu không còn tồn tại khi Action đọc lại nó dưới khoá. */
    public static function missing(): self
    {
        return new self(__('retraction.exceptions.missing'));
    }

    public static function trashed(Document $document): self
    {
        return new self(__('retraction.exceptions.trashed'), $document);
    }

    public static function matterUnavailable(Document $document): self
    {
        return new self(__('retraction.exceptions.matter_unavailable'), $document);
    }

    /** Đã rút rồi: quyết định đầu (người, lúc, lý do) giữ nguyên, không ghi đè. */
    public static function alreadyRetracted(Document $document): self
    {
        return new self(__('retraction.exceptions.already_retracted'), $document);
    }

    /** Tài liệu không đang ra tới khách (`Document::isReleasedToPortal()` sai): không có gì để rút. */
    public static function notReleased(Document $document): self
    {
        return new self(__('retraction.exceptions.not_released'), $document);
    }

    /**
     * Gộp M7 vào `main` (M9): tài liệu là biên lai của một khoản thu hay bản scan phụ lục hợp đồng
     * (`Document::isReferencedByBillingRecord()`) — bằng chứng của một bản ghi tiền bất biến.
     */
    public static function referencedByBillingRecord(Document $document): self
    {
        return new self(__('retraction.exceptions.billing_reference'), $document);
    }
}
