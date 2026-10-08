<?php

namespace App\Mcp\Tools;

use App\Actions\Mcp\Read\ReadMatter;
use App\Mcp\Tools\Concerns\CrmReadTool;
use App\Mcp\Tools\Concerns\OutputSchemas;
use App\Support\Mcp\McpIds;
use App\Support\Mcp\Presenters\MatterOverviewPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * Tool `get_matter` (kế hoạch M11, bảng tool 5 [DC:51]): tổng quan một vụ việc theo id `matter_…`.
 * Đọc qua {@see ReadMatter} (`McpMatterScope` + Gate `view`), trình bày qua
 * {@see MatterOverviewPresenter}. Id sai định dạng, id không tồn tại, vụ ngoài tập R3: cùng "Không tìm
 * thấy".
 */
final class GetMatterTool extends CrmReadTool
{
    protected string $name = 'get_matter';

    public function handle(Request $request, ReadMatter $read): Response|ResponseFactory
    {
        $input = $this->validated($request, [
            'id' => ['required', 'string', 'max:'.FetchTool::ID_MAX_LENGTH],
        ]);

        $matterId = McpIds::decode($input['id'], McpIds::MATTER);
        $overview = $matterId === null ? null : $read->handle($this->actor($request), $matterId);

        return $overview === null ? $this->notFound() : $this->result(MatterOverviewPresenter::present($overview));
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()
                ->max(FetchTool::ID_MAX_LENGTH)
                ->description(__('mcp.tools.get_matter.params.id'))
                ->required(),
        ];
    }

    /** @return array<string, mixed> */
    public function outputSchema(JsonSchema $schema): array
    {
        return OutputSchemas::required([
            ...OutputSchemas::matterDetailProperties($schema),
            'next_deadlines' => OutputSchemas::listOf($schema, OutputSchemas::deadline($schema)),
            'checklist_progress' => OutputSchemas::checklistProgress($schema),
            'open_client_request_count' => $schema->integer(),
        ]);
    }
}
