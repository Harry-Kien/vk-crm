<?php

namespace App\Actions\Mcp\Write;

use App\Models\ClientRequestReplyDraft;
use App\Models\StageLogDraft;

/**
 * Kết quả của một tool nháp (M11 R5/R6) cho tool trình bày — DTO, không bao giờ serialize ra MCP.
 * `$created` là `false` khi lần gọi mang lại một `idempotency_key` đã dùng với đúng nội dung đó, và
 * `$draft` là nháp lần trước đã tạo (ở bất kỳ trạng thái nào: đang chờ, đã dùng, đã bỏ).
 */
final class DraftOutcome
{
    public function __construct(
        public readonly StageLogDraft|ClientRequestReplyDraft $draft,
        public readonly bool $created,
    ) {}
}
