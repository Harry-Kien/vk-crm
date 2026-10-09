<?php

namespace App\Support\Mcp\Presenters;

use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\Matter;
use App\Models\User;
use App\Support\Mcp\AdminUrls;
use App\Support\Mcp\McpIds;
use App\Support\Mcp\Presenters\Concerns\ReadsLoadedRelations;
use App\Support\Mcp\UntrustedText;
use Illuminate\Support\Collection;

/**
 * Một yêu cầu từ khách trong kết quả MCP (kế hoạch M11, tool `list_client_requests`,
 * `get_client_request`, và kết quả yêu cầu của `search`/`fetch`).
 *
 * Tiêu đề và nội dung do KHÁCH viết: chúng KHÔNG có khoá riêng ở cấp trên cùng, chỉ ra trong
 * `untrusted_client_content` qua {@see UntrustedText} (R11) — `subject` ở {@see self::row()}, thêm
 * `content` ở {@see self::detail()}. Không bao giờ: email, tên hay id người dùng cổng đã gửi
 * (`client_user_id`), nháp trả lời (Task 11 chỉ ghép SỐ nháp đang có).
 */
final class ClientRequestPresenter
{
    use ReadsLoadedRelations;

    public const ROW_FIELDS = [
        'id', 'matter', 'status', 'status_label', 'assignee', 'created_at', 'last_activity_at', 'answered_at',
        'untrusted_client_content', 'url',
    ];

    public const DETAIL_FIELDS = [...self::ROW_FIELDS, 'replies'];

    /** `client_requests.subject` là string(200). */
    public const SUBJECT_LIMIT = 200;

    /** `client_requests.content` là `text`; cắt cho vừa một câu trả lời của tool. */
    public const CONTENT_LIMIT = 4000;

    /**
     * Cần nạp sẵn: `matter`, `assignee`.
     *
     * @return array<string, mixed>
     */
    public static function row(ClientRequest $request): array
    {
        return self::build($request, [
            'subject' => UntrustedText::from($request->subject, self::SUBJECT_LIMIT),
        ]);
    }

    /**
     * Cần nạp sẵn: như {@see self::row()}, cộng `replies` (và `author` của trả lời do văn phòng viết).
     *
     * @return array<string, mixed>
     */
    public static function detail(ClientRequest $request): array
    {
        /** @var Collection<int, ClientRequestReply> $replies */
        $replies = self::loaded($request, 'replies');

        return [
            ...self::build($request, [
                'subject' => UntrustedText::from($request->subject, self::SUBJECT_LIMIT),
                'content' => UntrustedText::from($request->content, self::CONTENT_LIMIT),
            ]),
            'replies' => $replies->map(fn (ClientRequestReply $reply) => ClientRequestReplyPresenter::present($reply))->values()->all(),
        ];
    }

    /**
     * @param  array<string, array{text: string, truncated: bool}>  $untrusted
     * @return array<string, mixed>
     */
    private static function build(ClientRequest $request, array $untrusted): array
    {
        /** @var Matter|null $matter */
        $matter = self::loaded($request, 'matter');
        /** @var User|null $assignee */
        $assignee = self::loaded($request, 'assignee');

        return [
            'id' => McpIds::encode(McpIds::REQUEST, (int) $request->getKey()),
            'matter' => $matter === null ? null : MatterPresenter::reference($matter),
            'status' => $request->status?->value,
            'status_label' => $request->status?->label(),
            'assignee' => $assignee === null ? null : StaffPresenter::present($assignee),
            'created_at' => $request->created_at?->toIso8601String(),
            'last_activity_at' => $request->last_activity_at?->toIso8601String(),
            'answered_at' => $request->answered_at?->toIso8601String(),
            'untrusted_client_content' => $untrusted,
            'url' => AdminUrls::clientRequest($request),
        ];
    }
}
