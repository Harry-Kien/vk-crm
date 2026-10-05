<?php

namespace App\Mcp\Tools;

use App\Actions\Mcp\Read\ClientRequestListFilters;
use App\Actions\Mcp\Read\ListClientRequests;
use App\Mcp\Tools\Concerns\CrmReadTool;
use App\Mcp\Tools\Concerns\OutputSchemas;
use App\Mcp\Tools\Concerns\PaginatesByCursor;
use App\Support\Mcp\McpIds;
use App\Support\Mcp\Presenters\ClientRequestListPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * Tool `list_client_requests` (kế hoạch M11, bảng tool 10 [DC:56]): yêu cầu từ khách trên các vụ trong
 * tập MCP của người gọi, hoạt động gần nhất trước. Lọc: `open` (`true` chưa đóng, `false` đã đóng, bỏ
 * trống cả hai), `mine` (giao cho tôi), `matter_id` (một vụ; vụ ngoài tập R3 hay id sai cho "Không tìm
 * thấy"). Đọc qua {@see ListClientRequests}, trình bày qua {@see ClientRequestListPresenter}: tiêu đề
 * khách viết chỉ trong `untrusted_client_content` (R11), không email hay tên người gửi.
 *
 * Phân trang {@see PaginatesByCursor}; cursor gắn với bộ lọc.
 */
final class ListClientRequestsTool extends CrmReadTool
{
    use PaginatesByCursor;

    protected string $name = 'list_client_requests';

    public function handle(Request $request, ListClientRequests $list): Response|ResponseFactory
    {
        $input = $this->validated($request, [
            'matter_id' => ['sometimes', 'nullable', 'string', 'max:'.FetchTool::ID_MAX_LENGTH],
            'open' => ['sometimes', 'nullable', 'boolean'],
            'mine' => ['sometimes', 'boolean'],
            ...$this->paginationRules(),
        ]);

        $actor = $this->actor();
        $matterId = null;

        if (($input['matter_id'] ?? null) !== null) {
            $matterId = McpIds::decode($input['matter_id'], McpIds::MATTER);

            if ($matterId === null) {
                return $this->notFound();
            }
        }

        $filters = new ClientRequestListFilters(
            matterId: $matterId,
            open: isset($input['open']) ? (bool) $input['open'] : null,
            mine: (bool) ($input['mine'] ?? false),
        );

        $after = $this->after($input, $actor, $filters->toArray());

        if ($after === false) {
            return $this->invalidCursor();
        }

        $page = $list->handle($actor, $filters, $this->limit($input), $after);

        return $page === null
            ? $this->notFound()
            : $this->result(ClientRequestListPresenter::present($page, $this->nextCursor($actor, $filters->toArray(), $page->next)));
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'matter_id' => $schema->string()->max(FetchTool::ID_MAX_LENGTH)->description(__('mcp.tools.list_client_requests.params.matter_id')),
            'open' => $schema->boolean()->description(__('mcp.tools.list_client_requests.params.open')),
            'mine' => $schema->boolean()->description(__('mcp.tools.list_client_requests.params.mine')),
            ...$this->paginationSchema($schema),
        ];
    }

    /** @return array<string, mixed> */
    public function outputSchema(JsonSchema $schema): array
    {
        return OutputSchemas::required([
            'requests' => OutputSchemas::listOf($schema, OutputSchemas::closed($schema, OutputSchemas::clientRequestProperties($schema, ['subject']))),
            'next_cursor' => $schema->string()->nullable(),
        ]);
    }
}
