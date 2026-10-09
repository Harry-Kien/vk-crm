<?php

namespace App\Actions\Mcp\Read;

use App\Models\ClientRequest;

/**
 * Kết quả của {@see ReadClientRequest}: yêu cầu đã nạp đủ cho `ClientRequestPresenter::detail()`
 * (`matter`, `assignee`, `replies`, `author` của trả lời văn phòng), cộng SỐ nháp trả lời đang chờ
 * người duyệt — chỉ số, không nội dung nháp.
 */
final readonly class ClientRequestThread
{
    public function __construct(
        public ClientRequest $request,
        public int $pendingReplyDraftCount,
    ) {}
}
