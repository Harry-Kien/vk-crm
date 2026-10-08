<?php

namespace App\Mcp\Tools;

use App\Actions\Deadline\AddMatterDeadline;
use App\Actions\Mcp\Write\CreateAiDeadline;
use App\Actions\Mcp\Write\TwoStepOutcome;
use App\Enums\DeadlineSeverity;
use App\Mcp\Tools\Concerns\CrmWriteTool;
use App\Mcp\Tools\Concerns\OutputSchemas;
use App\Models\Deadline;
use App\Support\Mcp\ConfirmationToken;
use App\Support\Mcp\McpIds;
use App\Support\Mcp\Presenters\DeadlinePresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * Tool `create_deadline` (kế hoạch M11, bảng tool 14; R5, R6): thêm một mốc thời hạn cho một vụ,
 * trong hai bước — lần gọi không `confirmation_token` không ghi gì và trả bản xem trước kèm mã; lần
 * gọi lại với đúng các tham số đó và mã thì ghi. Nghiệp vụ ở {@see CreateAiDeadline} (qua
 * `AddMatterDeadline`); tool chỉ kiểm tham số, đổi id có tiền tố, và trình bày.
 *
 * Không nhận `is_published`, `created_via`, người tạo hay "hoàn thành": server ép (R5).
 */
final class CreateDeadlineTool extends CrmWriteTool
{
    protected string $name = 'create_deadline';

    /** Ngày dạng `YYYY-MM-DD`. */
    public const DATE_MAX_LENGTH = 10;

    /** `deadlines.severity` là `string(20)`. */
    public const SEVERITY_MAX_LENGTH = 20;

    public function handle(Request $request, CreateAiDeadline $create): Response|ResponseFactory
    {
        $rules = [
            'matter_id' => ['required', 'string', 'max:'.FetchTool::ID_MAX_LENGTH],
            'name' => ['required', 'string', 'max:'.AddMatterDeadline::NAME_MAX_LENGTH],
            'due_date' => ['required', 'string', 'bail', 'date_format:Y-m-d', 'after_or_equal:today'],
            'severity' => ['sometimes', 'string', Rule::enum(DeadlineSeverity::class)],
            'responsible_id' => ['sometimes', 'nullable', 'string', 'max:'.FetchTool::ID_MAX_LENGTH],
            'confirmation_token' => ['sometimes', 'nullable', 'string', 'max:'.ConfirmationToken::MAX_LENGTH],
        ];

        $input = $this->validated($request, $rules, [
            'name.required' => __('deadlines.validation.name_required'),
            'due_date.date_format' => __('mcp.write.validation.date_format', ['attribute' => 'due_date']),
            'due_date.after_or_equal' => __('mcp.write.validation.due_date_past', ['today' => today()->toDateString()]),
            'severity.enum' => __('mcp.write.validation.one_of', ['attribute' => 'severity', 'values' => self::severities()]),
        ], $this->attributeNames($rules));

        $matterId = McpIds::decode($input['matter_id'], McpIds::MATTER);

        if ($matterId === null) {
            return $this->notFound();
        }

        $responsibleId = isset($input['responsible_id'])
            ? (McpIds::decode($input['responsible_id'], McpIds::USER) ?? 0)
            : null;

        $outcome = $create->handle(
            actor: $this->actor($request),
            matterId: $matterId,
            name: $input['name'],
            dueDate: $input['due_date'],
            severity: DeadlineSeverity::from($input['severity'] ?? DeadlineSeverity::Normal->value),
            responsibleId: $responsibleId,
            confirmationToken: $input['confirmation_token'] ?? null,
        );

        return $outcome === null ? $this->notFound() : $this->result(self::present($outcome));
    }

    /** @return array<string, mixed> */
    private static function present(TwoStepOutcome $outcome): array
    {
        if ($outcome->needsConfirmation()) {
            /** @var Deadline $preview */
            $preview = $outcome->preview;
            $shown = DeadlinePresenter::preview($preview);

            return [
                'status' => 'confirmation_required',
                'message' => __('mcp.write.create_deadline.preview', [
                    'name' => $shown['name'],
                    'due_date' => $shown['due_date'],
                    'severity' => $shown['severity_label'],
                    'responsible' => $shown['responsible']['name'] ?? '',
                    'code' => $shown['matter']['code'],
                    'minutes' => intdiv(ConfirmationToken::TTL_SECONDS, 60),
                ]),
                'preview' => $shown,
                'confirmation_token' => $outcome->token,
                'expires_at' => $outcome->expiresAt?->toIso8601String(),
                'deadline' => null,
            ];
        }

        /** @var Deadline $deadline */
        $deadline = $outcome->record;
        $shown = DeadlinePresenter::present($deadline);

        return [
            'status' => 'created',
            'message' => __($outcome->replayed ? 'mcp.write.create_deadline.replayed' : 'mcp.write.create_deadline.created', [
                'name' => $shown['name'],
                'due_date' => $shown['due_date'],
            ]),
            'preview' => null,
            'confirmation_token' => null,
            'expires_at' => null,
            'deadline' => $shown,
        ];
    }

    private static function severities(): string
    {
        return implode(', ', array_map(fn (DeadlineSeverity $severity): string => $severity->value, DeadlineSeverity::cases()));
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'matter_id' => $schema->string()->max(FetchTool::ID_MAX_LENGTH)
                ->description(__('mcp.tools.create_deadline.params.matter_id'))->required(),
            'name' => $schema->string()->max(AddMatterDeadline::NAME_MAX_LENGTH)
                ->description(__('mcp.tools.create_deadline.params.name'))->required(),
            'due_date' => $schema->string()->format('date')->max(self::DATE_MAX_LENGTH)
                ->description(__('mcp.tools.create_deadline.params.due_date'))->required(),
            'severity' => $schema->string()
                ->enum(array_map(fn (DeadlineSeverity $severity): string => $severity->value, DeadlineSeverity::cases()))
                ->max(self::SEVERITY_MAX_LENGTH)
                ->description(__('mcp.tools.create_deadline.params.severity')),
            'responsible_id' => $schema->string()->max(FetchTool::ID_MAX_LENGTH)
                ->description(__('mcp.tools.create_deadline.params.responsible_id')),
            'confirmation_token' => $schema->string()->max(ConfirmationToken::MAX_LENGTH)
                ->description(__('mcp.write.params.confirmation_token')),
        ];
    }

    /** @return array<string, mixed> */
    public function outputSchema(JsonSchema $schema): array
    {
        return OutputSchemas::required([
            'status' => $schema->string()->enum(['confirmation_required', 'created']),
            'message' => $schema->string(),
            'preview' => OutputSchemas::deadlinePreview($schema)->nullable(),
            'confirmation_token' => $schema->string()->nullable(),
            'expires_at' => $schema->string()->nullable(),
            'deadline' => OutputSchemas::deadline($schema)->nullable(),
        ]);
    }
}
