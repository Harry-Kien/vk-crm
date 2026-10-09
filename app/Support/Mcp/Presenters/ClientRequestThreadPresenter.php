<?php

namespace App\Support\Mcp\Presenters;

use App\Actions\Mcp\Read\ClientRequestThread;

/**
 * Một luồng yêu cầu từ khách trọn vẹn (nhánh yêu cầu của `fetch`; tool `get_client_request` của
 * Task 11): {@see ClientRequestPresenter::detail()} — tiêu đề, nội dung và trả lời của khách chỉ trong
 * `untrusted_client_content` (R11), trả lời của văn phòng ra thẳng — cộng `pending_reply_draft_count`,
 * SỐ nháp trả lời do AI soạn đang chờ người duyệt (bảng tool 11: "chỉ số lượng"; nội dung nháp không
 * ra).
 */
final class ClientRequestThreadPresenter
{
    public const FIELDS = [...ClientRequestPresenter::DETAIL_FIELDS, 'pending_reply_draft_count'];

    /**
     * Cần nạp sẵn: như {@see ClientRequestPresenter::detail()}.
     *
     * @return array<string, mixed>
     */
    public static function present(ClientRequestThread $thread): array
    {
        return [
            ...ClientRequestPresenter::detail($thread->request),
            'pending_reply_draft_count' => $thread->pendingReplyDraftCount,
        ];
    }
}
