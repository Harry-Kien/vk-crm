<?php

namespace App\Mcp\Tools;

use App\Actions\Mcp\Read\ListDocuments;
use App\Mcp\Tools\Concerns\CrmReadTool;
use App\Mcp\Tools\Concerns\OutputSchemas;
use App\Mcp\Tools\Concerns\PaginatesByCursor;
use App\Support\Mcp\McpIds;
use App\Support\Mcp\Presenters\DocumentListPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * Tool `list_documents` (kế hoạch M11, bảng tool 9 [DC:55]): metadata tài liệu nhóm A/B/C của một vụ
 * theo id `matter_…`, mới nhất trước. Đọc qua {@see ListDocuments}, trình bày qua
 * {@see DocumentListPresenter}. Nhóm D không liệt kê, không đếm, bất kể `document.viewInternal`; không
 * nội dung tệp, không đường tải hay URL ký (R4); tiêu đề nhóm A chỉ trong `untrusted_client_content`
 * (R11).
 *
 * Id sai định dạng, id không tồn tại, vụ ngoài tập R3: cùng "Không tìm thấy". Phân trang
 * {@see PaginatesByCursor}; cursor gắn với vụ.
 */
final class ListDocumentsTool extends CrmReadTool
{
    use PaginatesByCursor;

    protected string $name = 'list_documents';

    public function handle(Request $request, ListDocuments $list): Response|ResponseFactory
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
            : $this->result(DocumentListPresenter::present($result, $this->nextCursor($actor, $filters, $result->page->next)));
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'matter_id' => $schema->string()
                ->max(FetchTool::ID_MAX_LENGTH)
                ->description(__('mcp.tools.list_documents.params.matter_id'))
                ->required(),
            ...$this->paginationSchema($schema),
        ];
    }

    /** @return array<string, mixed> */
    public function outputSchema(JsonSchema $schema): array
    {
        return OutputSchemas::required([
            'matter' => OutputSchemas::matterReference($schema),
            'documents' => OutputSchemas::listOf($schema, OutputSchemas::document($schema)),
            'next_cursor' => $schema->string()->nullable(),
        ]);
    }
}
