<?php

namespace App\Exceptions;

use App\Models\Concerns\IsMcpDraft;
use DomainException;

/**
 * Một lần BỎ nháp do AI soạn mà thiếu người bỏ (`discarded_by`) hoặc thiếu lý do (`discard_reason`
 * rỗng hay chỉ khoảng trắng) — {@see IsMcpDraft}. Action "Bỏ nháp" (Task 12) tự
 * kiểm lý do bằng một lỗi validation trên ô nhập; đây là chốt cuối ở tầng model, cho mọi đường ghi
 * khác.
 */
class McpDraftDiscardIncomplete extends DomainException
{
    public static function make(): self
    {
        return new self(__('exceptions.mcp_draft_discard_incomplete'));
    }
}
