<?php

namespace App\Mcp\Tools\Concerns;

use App\Actions\Mcp\Read\KeysetPosition;
use App\Actions\Mcp\Read\ListMatters;
use App\Mcp\Tools\SearchMattersTool;
use App\Models\User;
use App\Support\Mcp\McpCursor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Response;

/**
 * `limit` + `cursor` cho các tool danh sách của Task 11 (kế hoạch M11, "Quy ước chung": `limit` mặc
 * định 10, tối đa 25, `cursor`; không tool "export all"). Cùng quy ước với `search_matters` của Task
 * 10: `limit` quá 25 bị kẹp, không báo lỗi (Action kẹp, `App\Actions\Mcp\Read\KeysetOrder::clamp()`);
 * cursor là chuỗi `McpCursor` gắn người gọi, tên tool và bộ lọc — ở đây mang vị trí (giá trị cột sắp
 * xếp, id) của dòng cuối ({@see McpCursor::encodePosition()}).
 *
 * Một cursor không dùng được cho MỘT thông điệp riêng `mcp.tool_errors.invalid_cursor` (như Task 10):
 * nó nói về cursor, không về bản ghi nào, nên không đụng luật "Không tìm thấy" của R3.
 */
trait PaginatesByCursor
{
    /** @return array<string, list<string>> */
    protected function paginationRules(): array
    {
        return [
            'limit' => ['sometimes', 'integer'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:'.SearchMattersTool::CURSOR_MAX_LENGTH],
        ];
    }

    /** @return array<string, Type> */
    protected function paginationSchema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->min(1)->max(ListMatters::MAX_LIMIT)->description(__('mcp.pagination.limit')),
            'cursor' => $schema->string()->max(SearchMattersTool::CURSOR_MAX_LENGTH)->description(__('mcp.pagination.cursor')),
        ];
    }

    /** @param  array<string, mixed>  $input */
    protected function limit(array $input): int
    {
        return (int) ($input['limit'] ?? ListMatters::DEFAULT_LIMIT);
    }

    /**
     * Vị trí bắt đầu của trang: `null` khi không có cursor (trang đầu), `false` khi cursor không dùng
     * được cho đúng người, tool và bộ lọc này.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $filters
     */
    protected function after(array $input, User $actor, array $filters): KeysetPosition|false|null
    {
        if (($input['cursor'] ?? null) === null) {
            return null;
        }

        $position = McpCursor::decodePosition($input['cursor'], $actor, $this->name(), $filters);

        return $position === null ? false : new KeysetPosition($position['sort'], $position['id']);
    }

    protected function invalidCursor(): Response
    {
        return Response::error(__('mcp.tool_errors.invalid_cursor'));
    }

    /** @param  array<string, mixed>  $filters */
    protected function nextCursor(User $actor, array $filters, ?KeysetPosition $next): ?string
    {
        return $next === null ? null : McpCursor::encodePosition($actor, $this->name(), $filters, $next->sort, $next->id);
    }
}
