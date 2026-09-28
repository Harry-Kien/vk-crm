<?php

namespace App\Exceptions;

use App\Models\Document;
use DomainException;

/**
 * Một tệp đang được một bản ghi tiền trỏ tới — bản scan phụ lục (`contract_amendments.document_id`)
 * hay biên lai (`payments.receipt_document_id`) — không xoá được (gộp M6.5 + M9, xung đột 5).
 *
 * Hai khoá ngoại ấy là `nullOnDelete`, và phụ lục lẫn khoản thu là bản ghi bất biến (không sửa,
 * không xoá — `ContractAmendmentImmutable`, `PaymentNotDestroyable`): xoá tệp là lặng lẽ cắt bằng
 * chứng khỏi một bản ghi tiền mà chính nó không còn cách nào nói ra điều đó. Ném từ hook
 * `Document::deleting` — chốt chặn cho MỌI đường xoá, mềm lẫn cứng; `DocumentPolicy::delete` trả
 * lời cùng câu cho nút xoá. Điều kiện nằm ở MỘT chỗ: {@see Document::isReferencedByBillingRecord()}.
 */
class DocumentReferencedByBillingRecord extends DomainException
{
    public static function make(): self
    {
        return new self(__('documents.delete_blocked_billing_reference'));
    }
}
