<?php

namespace App\Support\Mcp\Presenters;

use App\Models\ClientRequestReply;
use App\Models\User;
use App\Support\Mcp\McpIds;
use App\Support\Mcp\Presenters\Concerns\ReadsLoadedRelations;
use App\Support\Mcp\UntrustedText;

/**
 * Một trả lời trong luồng yêu cầu từ khách (kế hoạch M11, tool `get_client_request`): ai viết —
 * `office` hay `client` —, lúc nào, và nội dung.
 *
 *  - Văn phòng viết: `content` ra thẳng, `author_name` là tên nhân sự, `untrusted_client_content`
 *    là `null`.
 *  - Khách viết: `content` và `author_name` là `null`, nội dung ra trong
 *    `untrusted_client_content.content` qua {@see UntrustedText} (R11). Tên, email người dùng cổng
 *    không ra.
 *
 * "Văn phòng" chỉ khi `author_type` đúng là alias morph của `User`; mọi giá trị khác (`client_user`,
 * rỗng, lạ) là "khách" — nội dung không rõ nguồn thì bọc, không tin.
 */
final class ClientRequestReplyPresenter
{
    use ReadsLoadedRelations;

    public const FIELDS = ['id', 'author', 'author_name', 'created_at', 'content', 'untrusted_client_content'];

    /** Nội dung trả lời là `text`; cắt cho vừa một câu trả lời của tool. */
    public const CONTENT_LIMIT = 4000;

    /**
     * Cần nạp sẵn: `author` (chỉ đọc khi là trả lời của văn phòng).
     *
     * @return array<string, mixed>
     */
    public static function present(ClientRequestReply $reply): array
    {
        $byOffice = $reply->author_type === (new User)->getMorphClass();

        $author = $byOffice ? self::loaded($reply, 'author') : null;

        return [
            'id' => McpIds::encode(McpIds::REPLY, (int) $reply->getKey()),
            'author' => $byOffice ? 'office' : 'client',
            'author_name' => $author instanceof User ? (string) $author->name : null,
            'created_at' => $reply->created_at?->toIso8601String(),
            'content' => $byOffice ? (string) $reply->content : null,
            'untrusted_client_content' => $byOffice
                ? null
                : ['content' => UntrustedText::from($reply->content, self::CONTENT_LIMIT)],
        ];
    }
}
