<?php

namespace App\Mcp\Tools;

use App\Actions\Mcp\Read\ReadChecklist;
use App\Mcp\Tools\Concerns\CrmReadTool;
use App\Mcp\Tools\Concerns\OutputSchemas;
use App\Support\Mcp\McpIds;
use App\Support\Mcp\Presenters\MatterChecklistPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * Tool `get_checklist` (kế hoạch M11, bảng tool 8 [DC:54]): danh mục hồ sơ của một vụ theo id
 * `matter_…` — tên mục, bắt buộc hay không, trạng thái, lý do từ chối, SỐ tài liệu đã gắn, và "Đã nộp
 * X/Y". Đọc qua {@see ReadChecklist}, trình bày qua {@see MatterChecklistPresenter}. Không tên tệp
 * khách tải lên, không đếm nhóm D (R4). Không phân trang (một danh mục là một phần của một vụ).
 *
 * Id sai định dạng, id không tồn tại, vụ ngoài tập R3: cùng "Không tìm thấy".
 */
final class GetChecklistTool extends CrmReadTool
{
    protected string $name = 'get_checklist';

    public function handle(Request $request, ReadChecklist $read): Response|ResponseFactory
    {
        $input = $this->validated($request, [
            'matter_id' => ['required', 'string', 'max:'.FetchTool::ID_MAX_LENGTH],
        ]);

        $matterId = McpIds::decode($input['matter_id'], McpIds::MATTER);
        $checklist = $matterId === null ? null : $read->handle($this->actor($request), $matterId);

        return $checklist === null ? $this->notFound() : $this->result(MatterChecklistPresenter::present($checklist));
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'matter_id' => $schema->string()
                ->max(FetchTool::ID_MAX_LENGTH)
                ->description(__('mcp.tools.get_checklist.params.matter_id'))
                ->required(),
        ];
    }

    /** @return array<string, mixed> */
    public function outputSchema(JsonSchema $schema): array
    {
        return OutputSchemas::required([
            'matter' => OutputSchemas::matterReference($schema),
            'items' => OutputSchemas::listOf($schema, OutputSchemas::checklistItem($schema)),
            'checklist_progress' => OutputSchemas::checklistProgress($schema),
        ]);
    }
}
