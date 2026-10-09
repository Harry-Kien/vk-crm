<?php

namespace App\Mcp\Tools;

use App\Actions\Mcp\Read\ReadClientRequest;
use App\Actions\Mcp\Read\ReadMatter;
use App\Mcp\Tools\Concerns\CrmReadTool;
use App\Mcp\Tools\Concerns\OutputSchemas;
use App\Support\Mcp\McpIds;
use App\Support\Mcp\Presenters\FetchPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * Tool `fetch` — hợp đồng ChatGPT `id` → `{id, title, text, url, metadata}` (kế hoạch M11, bảng tool
 * 3 [DC:49], [DC:644]). Nhận đúng hai loại id mà `search` trả: `matter_…` (đọc như `get_matter`, qua
 * {@see ReadMatter}) và `request_…` (đọc như `get_client_request`, qua {@see ReadClientRequest}).
 *
 * Mọi thứ khác — id của loại khác, id sai định dạng (`McpIds::parse()` đọc ngược chặt), id không tồn
 * tại, bản ghi ngoài tập R3 — cho cùng một "Không tìm thấy" (R3).
 */
final class FetchTool extends CrmReadTool
{
    protected string $name = 'fetch';

    /**
     * Trần độ dài của MỌI tham số id có tiền tố (`id`, `matter_id`, `request_id`, `responsible_id`…; các
     * tool khác dùng lại hằng này). `McpIds` đọc tối đa 18 chữ số, và tiền tố dài nhất nó dựng là
     * `communication_` (14 ký tự): 14 + 18 = 32. Rà soát Task 10 m1: docblock cũ nói `request_`.
     */
    public const ID_MAX_LENGTH = 32;

    public function handle(Request $request, ReadMatter $matters, ReadClientRequest $requests): Response|ResponseFactory
    {
        $input = $this->validated($request, [
            'id' => ['required', 'string', 'max:'.self::ID_MAX_LENGTH],
        ]);

        $id = McpIds::parse($input['id'], McpIds::MATTER, McpIds::REQUEST);

        if ($id === null) {
            return $this->notFound();
        }

        if ($id['type'] === McpIds::MATTER) {
            $overview = $matters->handle($this->actor($request), $id['id']);

            return $overview === null ? $this->notFound() : $this->result(FetchPresenter::matter($overview));
        }

        $thread = $requests->handle($this->actor($request), $id['id']);

        return $thread === null ? $this->notFound() : $this->result(FetchPresenter::clientRequest($thread));
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()
                ->max(self::ID_MAX_LENGTH)
                ->description(__('mcp.tools.fetch.params.id'))
                ->required(),
        ];
    }

    /**
     * `metadata` khác nhau theo loại: vụ việc có `code`, `stage_label`, `is_open`; yêu cầu có
     * `matter_id`, `matter_code`, `status`, `pending_reply_draft_count`. Chỉ `type` luôn có.
     * `untrusted_client_content` chỉ có ở kết quả là yêu cầu.
     *
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            ...OutputSchemas::required([
                'id' => $schema->string(),
                'title' => $schema->string(),
                'text' => $schema->string(),
                'url' => $schema->string(),
                'metadata' => $schema->object([
                    'type' => $schema->string()->enum(['matter', 'client_request'])->required(),
                    'code' => $schema->string(),
                    'stage_label' => $schema->string()->nullable(),
                    'is_open' => $schema->boolean(),
                    'matter_id' => $schema->string()->nullable(),
                    'matter_code' => $schema->string()->nullable(),
                    'status' => $schema->string()->nullable(),
                    'pending_reply_draft_count' => $schema->integer(),
                ])->withoutAdditionalProperties(),
            ]),
            'untrusted_client_content' => OutputSchemas::closed($schema, [
                'subject' => OutputSchemas::untrusted($schema),
                'content' => OutputSchemas::untrusted($schema),
            ]),
        ];
    }
}
