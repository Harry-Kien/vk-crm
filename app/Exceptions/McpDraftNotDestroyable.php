<?php

namespace App\Exceptions;

use App\Models\Concerns\IsMcpDraft;
use DomainException;

/**
 * Chặn xoá một nháp do AI soạn (`stage_log_drafts`, `client_request_reply_drafts`), vô điều kiện
 * ({@see IsMcpDraft}). Nháp không được biến mất — nó được BỎ (`discarded_at`,
 * người bỏ, lý do; M6.5 R14 "sửa không xoá lịch sử") và vẫn nằm đó, để câu hỏi "AI đã soạn gì cho vụ
 * này và ai đã quyết định không dùng nó" luôn trả lời được. Hai bảng cố ý không có `deleted_at`.
 */
class McpDraftNotDestroyable extends DomainException
{
    public static function make(): self
    {
        return new self(__('exceptions.mcp_draft_not_destroyable'));
    }
}
