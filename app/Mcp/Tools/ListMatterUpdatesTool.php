<?php

namespace App\Mcp\Tools;

use App\Actions\Mcp\Read\ListMatterUpdates;
use App\Mcp\Tools\Concerns\CrmReadTool;
use App\Mcp\Tools\Concerns\OutputSchemas;
use App\Mcp\Tools\Concerns\PaginatesByCursor;
use App\Support\Mcp\McpIds;
use App\Support\Mcp\Presenters\MatterUpdatesPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * Tool `list_matter_updates` (kế hoạch M11, bảng tool 6 [DC:52]): dòng tiến độ của một vụ theo id
 * `matter_…`, mới nhất trước. Đọc qua {@see ListMatterUpdates} (`McpMatterScope` + Gate `view` trên vụ,
 * `StageLogPolicy::viewAny` + `view` từng dòng), trình bày qua {@see MatterUpdatesPresenter} — nội dung
 * `internal_note` không bao giờ ra, chỉ cờ `has_internal_note` (R4).
 *
 * Id sai định dạng, id không tồn tại, vụ ngoài tập R3: cùng "Không tìm thấy". Phân trang
 * {@see PaginatesByCursor}; cursor gắn với vụ.
 */
final class ListMatterUpdatesTool extends CrmReadTool
{
    use PaginatesByCursor;

    protected string $name = 'list_matter_updates';

    public function handle(Request $request, ListMatterUpdates $list): Response|ResponseFactory
    {
        $input = $this->validated($request, [
            'matter_id' => ['required', 'string', 'max:'.FetchTool::ID_MAX_LENGTH],
            ...$this->paginationRules(),
        ]);

        $actor = $this->actor($request);
        $matterId = McpIds::decode($input['matter_id'], McpIds::MATTER);

        if ($matterId === null) {
            return $this->notFound();
        }

        $filters = ['matter' => $matterId];
        $after = $this->after($input, $actor, $filters);

        if ($after === false) {
            return $this->invalidCursor();
        }

        $result = $list->handle($actor, $matterId, $this->limit($input), $after);

        return $result === null
            ? $this->notFound()
            : $this->result(MatterUpdatesPresenter::present($result, $this->nextCursor($actor, $filters, $result->page->next)));
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'matter_id' => $schema->string()
                ->max(FetchTool::ID_MAX_LENGTH)
                ->description(__('mcp.tools.list_matter_updates.params.matter_id'))
                ->required(),
            ...$this->paginationSchema($schema),
        ];
    }

    /** @return array<string, mixed> */
    public function outputSchema(JsonSchema $schema): array
    {
        return OutputSchemas::required([
            'matter' => OutputSchemas::matterReference($schema),
            'updates' => OutputSchemas::listOf($schema, OutputSchemas::stageLog($schema)),
            'next_cursor' => $schema->string()->nullable(),
        ]);
    }
}
