<?php

namespace App\Mcp\Tools;

use App\Actions\Mcp\Write\DraftOutcome;
use App\Actions\Mcp\Write\DraftProgressUpdate;
use App\Mcp\Tools\Concerns\CrmWriteTool;
use App\Mcp\Tools\Concerns\OutputSchemas;
use App\Models\StageLogDraft;
use App\Support\Mcp\McpIds;
use App\Support\Mcp\Presenters\McpDraftPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * Tool `draft_progress_update` (kế hoạch M11, bảng tool 12; R5, R6): soạn một NHÁP dòng cập nhật tiến
 * độ không đổi giai đoạn. Một bước, bắt buộc `idempotency_key`. Nghiệp vụ ở {@see DraftProgressUpdate};
 * nháp hiện ở khối "Nháp từ AI (n)" của tab Tiến độ, nơi một người mở, sửa và bấm "Thêm cập nhật".
 *
 * Không nhận `to_stage`, `publish`, ngày xảy ra hay người soạn (R5): nháp không chuyển giai đoạn,
 * không công bố; ngày xảy ra và công tắc công bố do người bấm chọn trên web.
 */
final class DraftProgressUpdateTool extends CrmWriteTool
{
    protected string $name = 'draft_progress_update';

    /** Ngày dạng `YYYY-MM-DD`. */
    public const DATE_MAX_LENGTH = 10;

    public function handle(Request $request, DraftProgressUpdate $draft): Response|ResponseFactory
    {
        $text = ['sometimes', 'nullable', 'string', 'max:'.DraftProgressUpdate::TEXT_MAX_LENGTH];
        $rules = [
            'matter_id' => ['required', 'string', 'max:'.FetchTool::ID_MAX_LENGTH],
            'public_content' => ['required', 'string', 'max:'.DraftProgressUpdate::TEXT_MAX_LENGTH],
            'next_step' => $text,
            'client_action' => $text,
            'expected_next_update_at' => ['sometimes', 'nullable', 'string', 'bail', 'date_format:Y-m-d', 'after_or_equal:today'],
            'internal_note' => $text,
            'idempotency_key' => ['required', 'string', 'regex:'.DraftProgressUpdate::IDEMPOTENCY_KEY_PATTERN],
        ];

        $input = $this->validated($request, $rules, [
            'expected_next_update_at.date_format' => __('mcp.write.validation.date_format', ['attribute' => 'expected_next_update_at']),
            'expected_next_update_at.after_or_equal' => __('mcp.write.validation.next_update_past', ['today' => today()->toDateString()]),
            'idempotency_key.regex' => __('mcp.write.validation.idempotency_key'),
        ], $this->attributeNames($rules));

        $matterId = McpIds::decode($input['matter_id'], McpIds::MATTER);

        if ($matterId === null) {
            return $this->notFound();
        }

        $outcome = $draft->handle($this->actor($request), $matterId, [
            'public_content' => $input['public_content'],
            'next_step' => $input['next_step'] ?? null,
            'client_action' => $input['client_action'] ?? null,
            'expected_next_update_at' => $input['expected_next_update_at'] ?? null,
            'internal_note' => $input['internal_note'] ?? null,
        ], $input['idempotency_key']);

        return $outcome === null ? $this->notFound() : $this->result(self::present($outcome));
    }

    /** @return array<string, mixed> */
    private static function present(DraftOutcome $outcome): array
    {
        /** @var StageLogDraft $draft */
        $draft = $outcome->draft;
        $shown = McpDraftPresenter::stageLog($draft);

        return [
            'status' => $outcome->created ? 'created' : 'existing',
            'message' => __($outcome->created ? 'mcp.write.draft_progress_update.created' : 'mcp.write.draft_progress_update.existing', [
                'code' => $shown['matter']['code'],
                'state' => $shown['state_label'],
            ]),
            'draft' => $shown,
        ];
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        $text = fn (string $param) => $schema->string()->max(DraftProgressUpdate::TEXT_MAX_LENGTH)
            ->description(__("mcp.tools.draft_progress_update.params.{$param}"));

        return [
            'matter_id' => $schema->string()->max(FetchTool::ID_MAX_LENGTH)
                ->description(__('mcp.tools.draft_progress_update.params.matter_id'))->required(),
            'public_content' => $text('public_content')->required(),
            'next_step' => $text('next_step'),
            'client_action' => $text('client_action'),
            'expected_next_update_at' => $schema->string()->format('date')->max(self::DATE_MAX_LENGTH)
                ->description(__('mcp.tools.draft_progress_update.params.expected_next_update_at')),
            'internal_note' => $text('internal_note'),
            'idempotency_key' => $schema->string()
                ->min(DraftProgressUpdate::IDEMPOTENCY_KEY_MIN_LENGTH)
                ->max(DraftProgressUpdate::IDEMPOTENCY_KEY_MAX_LENGTH)
                ->pattern('^[A-Za-z0-9._:\-]{8,64}$')
                ->description(__('mcp.write.params.idempotency_key'))->required(),
        ];
    }

    /** @return array<string, mixed> */
    public function outputSchema(JsonSchema $schema): array
    {
        return OutputSchemas::required([
            'status' => $schema->string()->enum(['created', 'existing']),
            'message' => $schema->string(),
            'draft' => OutputSchemas::stageLogDraft($schema),
        ]);
    }
}
