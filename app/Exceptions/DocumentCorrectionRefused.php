<?php

namespace App\Exceptions;

use DomainException;

/**
 * Làn fa (A4–A6): lời từ chối của các Action sửa sai trên tài liệu và danh mục hồ sơ —
 * `UpdateDocumentDetails`, `DeleteDocument`, `ReopenChecklistItem`, `KeepAcceptedChecklistVersion`. Một
 * `DomainException` mang câu tiếng Việt: màn hình (`ReportsActionFailures`) hiện nó thành thông báo.
 * Những lời từ chối gắn được vào một ô (tiêu đề, lý do…) là `ValidationException`, không ở đây.
 */
class DocumentCorrectionRefused extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    /** Tài liệu (hay hồ sơ chứa nó) đã bị xoá, hoặc không còn tồn tại khi đọc lại dưới khoá. */
    public static function documentUnavailable(): self
    {
        return new self(__('document_corrections.edit.errors.unavailable'));
    }

    /** `DocumentPolicy::delete` từ chối — câu riêng của policy (tệp làm bằng chứng tiền) nếu có. */
    public static function notDeletable(?string $policyMessage = null): self
    {
        return new self($policyMessage ?? __('document_corrections.delete.errors.not_deletable'));
    }

    public static function notReopenable(): self
    {
        return new self(__('document_corrections.reopen.errors.not_reopenable'));
    }

    public static function noAcceptedVersionToKeep(): self
    {
        return new self(__('document_corrections.keep_accepted.errors.not_available'));
    }
}
