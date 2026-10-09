<?php

namespace App\Mcp\Tools;

use App\Actions\Mcp\Read\DeadlineListFilters;
use App\Actions\Mcp\Read\ListDeadlines;
use App\Enums\DeadlineSeverity;
use App\Mcp\Tools\Concerns\CrmReadTool;
use App\Mcp\Tools\Concerns\OutputSchemas;
use App\Mcp\Tools\Concerns\PaginatesByCursor;
use App\Models\User;
use App\Support\Mcp\McpIds;
use App\Support\Mcp\Presenters\DeadlineListPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * Tool `list_deadlines` (kế hoạch M11, bảng tool 7 [DC:53]). Gọi không tham số là "mốc tuần này" của
 * người gọi: mốc chưa xong do chính người đó phụ trách, hạn tới hết bảy ngày tới, quá hạn lên đầu.
 * Đọc qua {@see ListDeadlines}, trình bày qua {@see DeadlineListPresenter}.
 *
 * Tham số:
 *  - `matter_id`: chỉ một vụ (`matter_…`); id sai định dạng hay vụ ngoài tập R3 cho "Không tìm thấy";
 *  - `from` / `to` (`YYYY-MM-DD`, gồm hai đầu): bỏ cả hai là cửa sổ mặc định; có một thì đầu kia bỏ ngỏ;
 *    `to` trước `from` bị từ chối;
 *  - `severity`: `normal` hay `critical` ({@see DeadlineSeverity});
 *  - `responsible`: `me` (mặc định), `any` (mọi người), hoặc `user_…` (id có tiền tố trong kết quả);
 *  - `include_completed`: kèm mốc đã xong.
 *
 * Đầu ra nói lại bộ lọc người phụ trách đã áp (`responsible`, rà soát Task 11 r1): chỉ đưa `matter_id`
 * thì "của tôi" và cửa sổ 7 ngày VẪN áp, và AI phải thấy điều đó.
 *
 * `maxLength`: id 32 ({@see FetchTool::ID_MAX_LENGTH}), ngày 10, mức độ 20 (cột `deadlines.severity`).
 * Phân trang {@see PaginatesByCursor}; cursor gắn với bộ lọc đã giải (người phụ trách là id thật).
 */
final class ListDeadlinesTool extends CrmReadTool
{
    use PaginatesByCursor;

    protected string $name = 'list_deadlines';

    public const DATE_MAX_LENGTH = 10;

    /** Bằng cột `deadlines.severity` (`string(20)`), như `create_deadline` (rà soát Task 11 r3). */
    public const SEVERITY_MAX_LENGTH = 20;

    /** `me`, `any` hoặc đúng một id `user_…` (McpIds: số dương, không số 0 đứng đầu, ≤ 18 chữ số). */
    private const RESPONSIBLE_PATTERN = '/\A(me|any|user_[1-9][0-9]{0,17})\z/';

    public function handle(Request $request, ListDeadlines $list): Response|ResponseFactory
    {
        $input = $this->validated($request, [
            'matter_id' => ['sometimes', 'nullable', 'string', 'max:'.FetchTool::ID_MAX_LENGTH],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'severity' => ['sometimes', 'nullable', Rule::enum(DeadlineSeverity::class)],
            'responsible' => ['sometimes', 'nullable', 'string', 'max:'.FetchTool::ID_MAX_LENGTH, 'regex:'.self::RESPONSIBLE_PATTERN],
            'include_completed' => ['sometimes', 'boolean'],
            ...$this->paginationRules(),
        ]);

        $actor = $this->actor($request);
        $matterId = null;

        if (($input['matter_id'] ?? null) !== null) {
            $matterId = McpIds::decode($input['matter_id'], McpIds::MATTER);

            if ($matterId === null) {
                return $this->notFound();
            }
        }

        $filters = new DeadlineListFilters(
            matterId: $matterId,
            from: $input['from'] ?? null,
            to: $input['to'] ?? null,
            severity: isset($input['severity']) ? DeadlineSeverity::from($input['severity']) : null,
            responsibleId: self::responsible($input['responsible'] ?? null, $actor),
            includeCompleted: (bool) ($input['include_completed'] ?? false),
        );

        $after = $this->after($input, $actor, $filters->toArray());

        if ($after === false) {
            return $this->invalidCursor();
        }

        $result = $list->handle($actor, $filters, $this->limit($input), $after);

        return $result === null
            ? $this->notFound()
            : $this->result(DeadlineListPresenter::present($result, $input['responsible'] ?? 'me', $this->nextCursor($actor, $filters->toArray(), $result->page->next)));
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'matter_id' => $schema->string()->max(FetchTool::ID_MAX_LENGTH)->description(__('mcp.tools.list_deadlines.params.matter_id')),
            'from' => $schema->string()->max(self::DATE_MAX_LENGTH)->description(__('mcp.tools.list_deadlines.params.from')),
            'to' => $schema->string()->max(self::DATE_MAX_LENGTH)->description(__('mcp.tools.list_deadlines.params.to')),
            'severity' => $schema->string()
                ->enum(array_map(fn (DeadlineSeverity $severity): string => $severity->value, DeadlineSeverity::cases()))
                ->max(self::SEVERITY_MAX_LENGTH)
                ->description(__('mcp.tools.list_deadlines.params.severity')),
            'responsible' => $schema->string()->max(FetchTool::ID_MAX_LENGTH)->description(__('mcp.tools.list_deadlines.params.responsible')),
            'include_completed' => $schema->boolean()->description(__('mcp.tools.list_deadlines.params.include_completed')),
            ...$this->paginationSchema($schema),
        ];
    }

    /** @return array<string, mixed> */
    public function outputSchema(JsonSchema $schema): array
    {
        return OutputSchemas::required([
            'deadlines' => OutputSchemas::listOf($schema, OutputSchemas::deadline($schema)),
            'due_from' => $schema->string()->nullable(),
            'due_to' => $schema->string()->nullable(),
            'responsible' => $schema->string(),
            'next_cursor' => $schema->string()->nullable(),
        ]);
    }

    /**
     * `me` hay bỏ trống → người gọi; `any` → không lọc; `user_…` → đúng id đó (đã qua regex). Một giá
     * trị đọc không ra id thì lọc theo id 0 — không khớp mốc nào — chứ không rơi về "mọi người".
     */
    private static function responsible(?string $value, User $actor): ?int
    {
        return match ($value) {
            null, 'me' => (int) $actor->getKey(),
            'any' => null,
            default => McpIds::decode($value, McpIds::USER) ?? 0,
        };
    }
}
