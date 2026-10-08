<?php

namespace App\Mcp\Tools;

use App\Actions\Mcp\Write\DraftOutcome;
use App\Actions\Mcp\Write\DraftRequestReply;
use App\Mcp\Tools\Concerns\CrmWriteTool;
use App\Mcp\Tools\Concerns\OutputSchemas;
use App\Models\ClientRequestReplyDraft;
use App\Support\Mcp\McpIds;
use App\Support\Mcp\Presenters\McpDraftPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * Tool `draft_request_reply` (kế hoạch M11, bảng tool 13; R5, R6, R11): soạn một NHÁP trả lời một yêu
 * cầu của khách. Một bước, bắt buộc `idempotency_key`. Nghiệp vụ ở {@see DraftRequestReply}; nháp hiện ở
 * tab "Yêu cầu từ khách" của trang vụ việc, nơi một người mở, sửa và bấm Gửi.
 *
 * Không nhận người nhận, trạng thái luồng hay người soạn (R5): không có gì tới khách khi chưa có người
 * bấm trong `/admin`.
 */
final class DraftRequestReplyTool extends CrmWriteTool
{
    protected string $name = 'draft_request_reply';

    public function handle(Request $request, DraftRequestReply $draft): Response|ResponseFactory
    {
        $rules = [
            'request_id' => ['required', 'string', 'max:'.FetchTool::ID_MAX_LENGTH],
            'content' => ['required', 'string', 'max:'.DraftRequestReply::CONTENT_MAX_LENGTH],
            'idempotency_key' => ['required', 'string', 'regex:'.DraftRequestReply::IDEMPOTENCY_KEY_PATTERN],
        ];

        $input = $this->validated($request, $rules, [
            'idempotency_key.regex' => __('mcp.write.validation.idempotency_key'),
        ], $this->attributeNames($rules));

        $requestId = McpIds::decode($input['request_id'], McpIds::REQUEST);

        if ($requestId === null) {
            return $this->notFound();
        }

        $outcome = $draft->handle($this->actor(), $requestId, $input['content'], $input['idempotency_key']);

        return $outcome === null ? $this->notFound() : $this->result(self::present($outcome));
    }

    /** @return array<string, mixed> */
    private static function present(DraftOutcome $outcome): array
    {
        /** @var ClientRequestReplyDraft $draft */
        $draft = $outcome->draft;
        $shown = McpDraftPresenter::reply($draft);

        return [
            'status' => $outcome->created ? 'created' : 'existing',
            'message' => __($outcome->created ? 'mcp.write.draft_request_reply.created' : 'mcp.write.draft_request_reply.existing', [
                'code' => $shown['matter']['code'],
                'state' => $shown['state_label'],
            ]),
            'draft' => $shown,
        ];
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'request_id' => $schema->string()->max(FetchTool::ID_MAX_LENGTH)
                ->description(__('mcp.tools.draft_request_reply.params.request_id'))->required(),
            'content' => $schema->string()->max(DraftRequestReply::CONTENT_MAX_LENGTH)
                ->description(__('mcp.tools.draft_request_reply.params.content'))->required(),
            'idempotency_key' => $schema->string()
                ->min(DraftRequestReply::IDEMPOTENCY_KEY_MIN_LENGTH)
                ->max(DraftRequestReply::IDEMPOTENCY_KEY_MAX_LENGTH)
                ->pattern('^[A-Za-z0-9._:\-]{8,64}$')
                ->description(__('mcp.write.params.idempotency_key'))->required(),
        ];
    }

    /** @return array<string, mixed> */
    public function outputSchema(JsonSchema $schema): array
    {
        return OutputSchemas::required([
            'status' => $schema->string()->enum(['created', 'existing']),
            'message' => $schema->string(),
            'draft' => OutputSchemas::replyDraft($schema),
        ]);
    }
}
