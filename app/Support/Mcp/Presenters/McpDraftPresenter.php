<?php

namespace App\Support\Mcp\Presenters;

use App\Enums\McpDraftState;
use App\Models\ClientRequest;
use App\Models\ClientRequestReplyDraft;
use App\Models\Matter;
use App\Models\StageLogDraft;
use App\Support\Mcp\AdminUrls;
use App\Support\Mcp\McpIds;
use App\Support\Mcp\Presenters\Concerns\ReadsLoadedRelations;

/**
 * Một nháp do AI soạn, trong kết quả của hai tool nháp `draft_progress_update` và
 * `draft_request_reply` (kế hoạch M11, R5; Task 13). Allowlist tường minh:
 *
 *  - nội dung là thứ CHÍNH người gọi vừa gửi qua tool (văn phòng viết, không phải chữ của khách), nên
 *    không bọc R11;
 *  - `internal_note` KHÔNG BAO GIỜ đọc ra (R4) — kể cả khi chính AI vừa ghi nó: chỉ cờ
 *    `has_internal_note`;
 *  - không người soạn, không id/khoá idempotency, không lý do bỏ nháp hay người bỏ;
 *  - `state` theo {@see McpDraftState}: lần gọi lại cùng khoá có thể trả một nháp đã dùng hay đã bỏ;
 *  - `url` về đúng tab trên trang vụ việc, nơi một người mở nháp, sửa và bấm nút của luồng web.
 */
final class McpDraftPresenter
{
    use ReadsLoadedRelations;

    public const STAGE_LOG_FIELDS = [
        'id', 'matter', 'public_content', 'next_step', 'client_action', 'expected_next_update_at',
        'has_internal_note', 'state', 'state_label', 'created_at', 'url',
    ];

    public const REPLY_FIELDS = [
        'id', 'request_id', 'matter', 'content', 'state', 'state_label', 'created_at', 'url',
    ];

    /**
     * Cần nạp sẵn: `matter`.
     *
     * @return array<string, mixed>
     */
    public static function stageLog(StageLogDraft $draft): array
    {
        /** @var Matter $matter */
        $matter = self::loaded($draft, 'matter');
        $state = McpDraftState::of($draft);

        return [
            'id' => McpIds::encode(McpIds::PROGRESS_DRAFT, (int) $draft->getKey()),
            'matter' => MatterPresenter::reference($matter),
            'public_content' => $draft->public_content,
            'next_step' => $draft->next_step,
            'client_action' => $draft->client_action,
            'expected_next_update_at' => $draft->expected_next_update_at?->toDateString(),
            'has_internal_note' => trim((string) $draft->internal_note) !== '',
            'state' => $state->value,
            'state_label' => $state->label(),
            'created_at' => $draft->created_at?->toIso8601String(),
            'url' => AdminUrls::stageLogDraft($draft),
        ];
    }

    /**
     * Cần nạp sẵn: `request`, và `matter` của yêu cầu đó.
     *
     * @return array<string, mixed>
     */
    public static function reply(ClientRequestReplyDraft $draft): array
    {
        /** @var ClientRequest $request */
        $request = self::loaded($draft, 'request');
        /** @var Matter $matter */
        $matter = self::loaded($request, 'matter');
        $state = McpDraftState::of($draft);

        return [
            'id' => McpIds::encode(McpIds::REPLY_DRAFT, (int) $draft->getKey()),
            'request_id' => McpIds::encode(McpIds::REQUEST, (int) $request->getKey()),
            'matter' => MatterPresenter::reference($matter),
            'content' => $draft->content,
            'state' => $state->value,
            'state_label' => $state->label(),
            'created_at' => $draft->created_at?->toIso8601String(),
            'url' => AdminUrls::clientRequest($request),
        ];
    }
}
