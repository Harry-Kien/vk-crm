<?php

namespace App\Mcp\Tools;

use App\Actions\Mcp\Read\SearchRecords;
use App\Actions\Search\SearchMatters;
use App\Mcp\Tools\Concerns\CrmReadTool;
use App\Mcp\Tools\Concerns\OutputSchemas;
use App\Support\Mcp\Presenters\SearchResultPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;

/**
 * Tool `search` — hợp đồng ChatGPT `query` → `{results: [{id, title, url}]}` (kế hoạch M11, bảng tool
 * 2 [DC:48], [DC:644]), trên vụ việc và yêu cầu từ khách. Đọc qua {@see SearchRecords} (bốn nguồn R10
 * cho phép của `SearchMatters`, giao `McpMatterScope`; yêu cầu qua thêm `ClientRequestPolicy::view`
 * từng dòng, như `fetch`), trình bày qua {@see SearchResultPresenter}.
 *
 * `query` dài tối đa {@see SearchMatters::MAX_TERM_LENGTH} ký tự — đúng `maxlength` của ô tìm trên
 * web; dài hơn thì bị từ chối, không cắt im lặng.
 */
final class SearchTool extends CrmReadTool
{
    protected string $name = 'search';

    public function handle(Request $request, SearchRecords $search): ResponseFactory
    {
        $input = $this->validated($request, [
            'query' => ['required', 'string', 'max:'.SearchMatters::MAX_TERM_LENGTH],
        ]);

        return $this->result(SearchResultPresenter::present($search->handle($this->actor(), $input['query'])));
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->max(SearchMatters::MAX_TERM_LENGTH)
                ->description(__('mcp.tools.search.params.query'))
                ->required(),
        ];
    }

    /** @return array<string, mixed> */
    public function outputSchema(JsonSchema $schema): array
    {
        $result = $schema->object([
            'id' => $schema->string()->required(),
            'title' => $schema->string()->required(),
            'url' => $schema->string()->required(),
            // Chỉ kết quả là yêu cầu từ khách mang khoá này (R11).
            'untrusted_client_content' => OutputSchemas::closed($schema, [
                'subject' => OutputSchemas::untrusted($schema),
            ]),
        ])->withoutAdditionalProperties();

        return OutputSchemas::required([
            'results' => OutputSchemas::listOf($schema, $result),
        ]);
    }
}
