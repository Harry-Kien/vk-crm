<?php

namespace App\Mcp\Tools;

use App\Actions\Mcp\Read\ListMatters;
use App\Actions\Mcp\Read\MatterListFilters;
use App\Actions\Search\SearchMatters;
use App\Mcp\Tools\Concerns\CrmReadTool;
use App\Mcp\Tools\Concerns\OutputSchemas;
use App\Support\Mcp\McpCursor;
use App\Support\Mcp\Presenters\MatterListPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * Tool `search_matters` (kế hoạch M11, bảng tool 4 [DC:50]): danh sách vụ trong tập MCP của người
 * gọi, lọc theo chữ, loại vụ, giai đoạn, "vụ tôi phụ trách", đang mở/đã kết thúc. Đọc qua
 * {@see ListMatters}, trình bày qua {@see MatterListPresenter}.
 *
 * Phân trang ("Quy ước chung"): `limit` mặc định {@see ListMatters::DEFAULT_LIMIT}, quá
 * {@see ListMatters::MAX_LIMIT} thì bị kẹp về {@see ListMatters::MAX_LIMIT} (không báo lỗi);
 * `cursor` là chuỗi {@see McpCursor} gắn người gọi, tool và bộ lọc. Cursor không dùng được thì trả
 * MỘT thông điệp riêng — nó nói về cursor, không về bản ghi nào, nên không đụng luật R3.
 *
 * `maxLength` theo cột: `matters.stage` 40, `matter_types.name` 150 (bộ lọc loại vụ nhận cả mã 10 ký
 * tự lẫn tên); `query` theo ô tìm của web ({@see SearchMatters::MAX_TERM_LENGTH}).
 */
final class SearchMattersTool extends CrmReadTool
{
    protected string $name = 'search_matters';

    public const STAGE_MAX_LENGTH = 40;

    public const MATTER_TYPE_MAX_LENGTH = 150;

    public const CURSOR_MAX_LENGTH = 1024;

    public function handle(Request $request, ListMatters $list): Response|ResponseFactory
    {
        $input = $this->validated($request, [
            'query' => ['sometimes', 'nullable', 'string', 'max:'.SearchMatters::MAX_TERM_LENGTH],
            'matter_type' => ['sometimes', 'nullable', 'string', 'max:'.self::MATTER_TYPE_MAX_LENGTH],
            'stage' => ['sometimes', 'nullable', 'string', 'max:'.self::STAGE_MAX_LENGTH],
            'mine' => ['sometimes', 'boolean'],
            'open' => ['sometimes', 'nullable', 'boolean'],
            'limit' => ['sometimes', 'integer'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:'.self::CURSOR_MAX_LENGTH],
        ]);

        $actor = $this->actor();

        $filters = new MatterListFilters(
            query: self::filled($input['query'] ?? null),
            matterType: self::filled($input['matter_type'] ?? null),
            stage: self::filled($input['stage'] ?? null),
            mine: (bool) ($input['mine'] ?? false),
            open: isset($input['open']) ? (bool) $input['open'] : null,
        );

        $afterId = null;

        if (($input['cursor'] ?? null) !== null) {
            $afterId = McpCursor::decode($input['cursor'], $actor, $this->name(), $filters->toArray());

            if ($afterId === null) {
                return Response::error(__('mcp.tool_errors.invalid_cursor'));
            }
        }

        $page = $list->handle($actor, $filters, (int) ($input['limit'] ?? ListMatters::DEFAULT_LIMIT), $afterId);

        $nextCursor = $page->lastId === null
            ? null
            : McpCursor::encode($actor, $this->name(), $filters->toArray(), $page->lastId);

        return $this->result(MatterListPresenter::present($page, $nextCursor));
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->max(SearchMatters::MAX_TERM_LENGTH)->description(__('mcp.tools.search_matters.params.query')),
            'matter_type' => $schema->string()->max(self::MATTER_TYPE_MAX_LENGTH)->description(__('mcp.tools.search_matters.params.matter_type')),
            'stage' => $schema->string()->max(self::STAGE_MAX_LENGTH)->description(__('mcp.tools.search_matters.params.stage')),
            'mine' => $schema->boolean()->description(__('mcp.tools.search_matters.params.mine')),
            'open' => $schema->boolean()->description(__('mcp.tools.search_matters.params.open')),
            'limit' => $schema->integer()->min(1)->max(ListMatters::MAX_LIMIT)->description(__('mcp.tools.search_matters.params.limit')),
            'cursor' => $schema->string()->max(self::CURSOR_MAX_LENGTH)->description(__('mcp.tools.search_matters.params.cursor')),
        ];
    }

    /** @return array<string, mixed> */
    public function outputSchema(JsonSchema $schema): array
    {
        return OutputSchemas::required([
            'matters' => OutputSchemas::listOf($schema, OutputSchemas::closed($schema, [
                ...OutputSchemas::matterRowProperties($schema),
                'next_deadline' => OutputSchemas::deadline($schema)->nullable(),
            ])),
            'next_cursor' => $schema->string()->nullable(),
        ]);
    }

    /** Chuỗi rỗng hay chỉ khoảng trắng là "không lọc". */
    private static function filled(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === null || $value === '' ? null : $value;
    }
}
