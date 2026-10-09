<?php

namespace App\Mcp\Tools;

use App\Actions\Communication\LogCommunication;
use App\Actions\Mcp\Write\LogAiCommunication;
use App\Actions\Mcp\Write\TwoStepOutcome;
use App\Enums\CommunicationType;
use App\Mcp\Tools\Concerns\CrmWriteTool;
use App\Mcp\Tools\Concerns\OutputSchemas;
use App\Models\CommunicationLog;
use App\Support\Mcp\ConfirmationToken;
use App\Support\Mcp\McpIds;
use App\Support\Mcp\Presenters\CommunicationLogPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * Tool `log_communication` (kế hoạch M11, bảng tool 15; R5, R6): ghi một cuộc gọi, buổi làm việc,
 * email, thư hay lần lên toà vào nhật ký liên lạc của một vụ, trong hai bước như `create_deadline`.
 * Nghiệp vụ ở {@see LogAiCommunication} (qua `LogCommunication` của M7 Task 8).
 *
 * Không nhận `is_visible_to_client`, `created_via` hay người ghi: server ép (R5). `occurred_at` bắt
 * buộc (khác tab "Liên lạc", nơi trống là "bây giờ"): AI thường ghi lại một cuộc trao đổi đã qua, và
 * một thời điểm tự điền ở lần ghi sẽ lệch với bản xem trước. Thời điểm được quy về múi giờ của ứng dụng
 * trước khi ký và ghi.
 */
final class LogCommunicationTool extends CrmWriteTool
{
    protected string $name = 'log_communication';

    /** ISO 8601 dài nhất hợp lý: `2026-10-07T14:30:00.000000+07:00` là 32 ký tự. */
    public const DATE_TIME_MAX_LENGTH = 40;

    /** `communication_logs.type` là `string(20)`. */
    public const TYPE_MAX_LENGTH = 20;

    public function handle(Request $request, LogAiCommunication $log): Response|ResponseFactory
    {
        $rules = [
            'matter_id' => ['required', 'string', 'max:'.FetchTool::ID_MAX_LENGTH],
            'type' => ['required', 'string', Rule::enum(CommunicationType::class)],
            'occurred_at' => ['required', 'string', 'max:'.self::DATE_TIME_MAX_LENGTH, 'date'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.LogCommunication::DURATION_MAX_MINUTES],
            'counterpart' => ['sometimes', 'nullable', 'string', 'max:'.LogCommunication::COUNTERPART_MAX_LENGTH],
            'summary' => ['required', 'string', 'max:'.LogCommunication::SUMMARY_MAX_LENGTH],
            'confirmation_token' => ['sometimes', 'nullable', 'string', 'max:'.ConfirmationToken::MAX_LENGTH],
        ];

        $input = $this->validated($request, $rules, [
            'type.enum' => __('mcp.write.validation.one_of', ['attribute' => 'type', 'values' => self::types()]),
            'occurred_at.date' => __('mcp.write.validation.date_time_format', ['attribute' => 'occurred_at']),
            'summary.required' => __('communications.validation.summary_required'),
        ], $this->attributeNames($rules));

        $matterId = McpIds::decode($input['matter_id'], McpIds::MATTER);

        if ($matterId === null) {
            return $this->notFound();
        }

        $outcome = $log->handle(
            actor: $this->actor($request),
            matterId: $matterId,
            type: CommunicationType::from($input['type']),
            summary: $input['summary'],
            counterpart: $input['counterpart'] ?? null,
            occurredAt: CarbonImmutable::parse($input['occurred_at'])->setTimezone((string) config('app.timezone')),
            durationMinutes: isset($input['duration_minutes']) ? (int) $input['duration_minutes'] : null,
            confirmationToken: $input['confirmation_token'] ?? null,
        );

        return $outcome === null ? $this->notFound() : $this->result(self::present($outcome));
    }

    /** @return array<string, mixed> */
    private static function present(TwoStepOutcome $outcome): array
    {
        if ($outcome->needsConfirmation()) {
            /** @var CommunicationLog $preview */
            $preview = $outcome->preview;
            $shown = CommunicationLogPresenter::preview($preview);

            return [
                'status' => 'confirmation_required',
                'message' => __('mcp.write.log_communication.preview', [
                    'type' => $shown['type_label'],
                    'occurred_at' => $shown['occurred_at'],
                    'counterpart' => $shown['counterpart'],
                    'code' => $shown['matter']['code'],
                    'minutes' => intdiv(ConfirmationToken::TTL_SECONDS, 60),
                ]),
                'preview' => $shown,
                'confirmation_token' => $outcome->token,
                'expires_at' => $outcome->expiresAt?->toIso8601String(),
                'communication' => null,
            ];
        }

        /** @var CommunicationLog $record */
        $record = $outcome->record;
        $shown = CommunicationLogPresenter::present($record);

        return [
            'status' => 'created',
            'message' => __($outcome->replayed ? 'mcp.write.log_communication.replayed' : 'mcp.write.log_communication.created', [
                'type' => $shown['type_label'],
                'code' => $shown['matter']['code'],
            ]),
            'preview' => null,
            'confirmation_token' => null,
            'expires_at' => null,
            'communication' => $shown,
        ];
    }

    private static function types(): string
    {
        return implode(', ', array_map(fn (CommunicationType $type): string => $type->value, CommunicationType::cases()));
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'matter_id' => $schema->string()->max(FetchTool::ID_MAX_LENGTH)
                ->description(__('mcp.tools.log_communication.params.matter_id'))->required(),
            'type' => $schema->string()
                ->enum(array_map(fn (CommunicationType $type): string => $type->value, CommunicationType::cases()))
                ->max(self::TYPE_MAX_LENGTH)
                ->description(__('mcp.tools.log_communication.params.type'))->required(),
            'occurred_at' => $schema->string()->format('date-time')->max(self::DATE_TIME_MAX_LENGTH)
                ->description(__('mcp.tools.log_communication.params.occurred_at'))->required(),
            'duration_minutes' => $schema->integer()->min(0)->max(LogCommunication::DURATION_MAX_MINUTES)
                ->description(__('mcp.tools.log_communication.params.duration_minutes')),
            'counterpart' => $schema->string()->max(LogCommunication::COUNTERPART_MAX_LENGTH)
                ->description(__('mcp.tools.log_communication.params.counterpart')),
            'summary' => $schema->string()->max(LogCommunication::SUMMARY_MAX_LENGTH)
                ->description(__('mcp.tools.log_communication.params.summary'))->required(),
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
            'preview' => OutputSchemas::closed($schema, OutputSchemas::communicationPreviewProperties($schema))->nullable(),
            'confirmation_token' => $schema->string()->nullable(),
            'expires_at' => $schema->string()->nullable(),
            'communication' => OutputSchemas::communication($schema)->nullable(),
        ]);
    }
}
