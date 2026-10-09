<?php

namespace App\Mcp\Tools;

use App\Actions\Mcp\Read\ReadClientRequest;
use App\Mcp\Tools\Concerns\CrmReadTool;
use App\Mcp\Tools\Concerns\OutputSchemas;
use App\Support\Mcp\McpIds;
use App\Support\Mcp\Presenters\ClientRequestThreadPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * Tool `get_client_request` (kế hoạch M11, bảng tool 11 [DC:57]): toàn bộ luồng hỏi và trả lời của một
 * yêu cầu theo id `request_…`. Đọc qua CHÍNH {@see ReadClientRequest} của nhánh yêu cầu của `fetch`
 * (`ClientRequestPolicy::view` + `ClientRequestReplyPolicy::view`), trình bày qua
 * {@see ClientRequestThreadPresenter}: tiêu đề, nội dung và trả lời của khách chỉ trong
 * `untrusted_client_content`, đã qua `UntrustedText` (R11); trả lời của văn phòng ra thẳng; số nháp
 * trả lời đang chờ người duyệt (chỉ số lượng).
 *
 * Id sai định dạng, id của loại khác, id không tồn tại, yêu cầu ngoài tập R3 hay đã rút: cùng "Không
 * tìm thấy".
 */
final class GetClientRequestTool extends CrmReadTool
{
    protected string $name = 'get_client_request';

    public function handle(Request $request, ReadClientRequest $read): Response|ResponseFactory
    {
        $input = $this->validated($request, [
            'id' => ['required', 'string', 'max:'.FetchTool::ID_MAX_LENGTH],
        ]);

        $requestId = McpIds::decode($input['id'], McpIds::REQUEST);
        $thread = $requestId === null ? null : $read->handle($this->actor($request), $requestId);

        return $thread === null ? $this->notFound() : $this->result(ClientRequestThreadPresenter::present($thread));
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()
                ->max(FetchTool::ID_MAX_LENGTH)
                ->description(__('mcp.tools.get_client_request.params.id'))
                ->required(),
        ];
    }

    /** @return array<string, mixed> */
    public function outputSchema(JsonSchema $schema): array
    {
        return OutputSchemas::required([
            ...OutputSchemas::clientRequestProperties($schema, ['subject', 'content']),
            'replies' => OutputSchemas::listOf($schema, OutputSchemas::reply($schema)),
            'pending_reply_draft_count' => $schema->integer(),
        ]);
    }
}
