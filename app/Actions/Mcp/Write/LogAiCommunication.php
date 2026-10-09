<?php

namespace App\Actions\Mcp\Write;

use App\Actions\Communication\LogCommunication;
use App\Actions\Mcp\McpMatterScope;
use App\Actions\Mcp\Write\Concerns\ConfirmsInTwoSteps;
use App\Enums\CommunicationType;
use App\Enums\CreatedVia;
use App\Models\CommunicationLog;
use App\Models\User;
use App\Support\Mcp\ConfirmationToken;
use App\Support\Scopes\ClientPortalScope;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Tool `log_communication` (kế hoạch M11, bảng tool 15; R5, R6): ghi một dòng nhật ký liên lạc qua
 * ĐÚNG Action của M7 Task 8, {@see LogCommunication} — Action của tab "Liên lạc" — trong hai bước
 * ({@see ConfirmsInTwoSteps}).
 *
 * Cùng thứ tự với {@see CreateAiDeadline}: vụ trong tập R3 (không thì `null` → "Không tìm thấy"); cổng
 * của web `CommunicationLogPolicy::create($user, $matter)` — đúng câu `LogCommunication` hỏi — không
 * qua thì `mcp.tool_errors.forbidden`; lần một chạy thử rồi cấp mã, lần hai kiểm mã rồi ghi một lần.
 *
 * Server ÉP: `is_visible_to_client = false` (Action luôn ghi vậy, không có tham số), `created_via =
 * mcp`, người ghi là `$actor` truyền tường minh. Người liên lạc bỏ trống thì Action lấy tên khách của
 * vụ — bản xem trước nói trước tên đó.
 *
 * `occurred_at` nhận đã quy về múi giờ của ứng dụng (tool làm việc đó): cột `datetime` không mang múi
 * giờ, nên một thời điểm gửi kèm `Z` mà không quy đổi sẽ được lưu lệch đúng bằng độ lệch múi giờ.
 */
final class LogAiCommunication
{
    use ConfirmsInTwoSteps;

    public const TOOL = 'log_communication';

    public function __construct(
        private readonly McpMatterScope $scope,
        private readonly LogCommunication $log,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(
        User $actor,
        int $matterId,
        CommunicationType $type,
        string $summary,
        ?string $counterpart,
        CarbonImmutable $occurredAt,
        ?int $durationMinutes,
        ?string $confirmationToken,
    ): ?TwoStepOutcome {
        $matter = $this->scope->find($actor, $matterId);

        if ($matter === null) {
            return null;
        }

        if (Gate::forUser($actor)->inspect('create', [CommunicationLog::class, $matter])->denied()) {
            throw new AuthorizationException(__('mcp.tool_errors.forbidden'));
        }

        $counterpart = $counterpart === null || trim($counterpart) === '' ? null : trim($counterpart);
        $signed = [
            'matter_id' => $matterId,
            'type' => $type->value,
            'summary' => trim($summary),
            'counterpart' => $counterpart,
            'occurred_at' => $occurredAt->toIso8601String(),
            'duration_minutes' => $durationMinutes,
        ];

        $write = fn (): CommunicationLog => $this->log->handle(
            matter: $matter,
            actor: $actor,
            type: $type,
            summary: $summary,
            counterpart: $counterpart,
            occurredAt: $occurredAt,
            durationMinutes: $durationMinutes,
            createdVia: CreatedVia::Mcp,
        );

        if ($confirmationToken === null) {
            /** @var CommunicationLog $preview */
            $preview = $this->dryRun($write);
            $preview->setRelation('matter', $matter);

            ['token' => $token, 'expires_at' => $expiresAt] = ConfirmationToken::issue($actor, self::TOOL, $signed);

            return TwoStepOutcome::preview($preview, $token, $expiresAt);
        }

        $jti = ConfirmationToken::verify($confirmationToken, $actor, self::TOOL, $signed)
            ?? throw ValidationException::withMessages(['confirmation_token' => [__('mcp.tool_errors.invalid_confirmation')]]);

        [$log, $replayed] = $this->confirmOnce($actor, self::TOOL, $matter, $jti, $write, fn (int $id): ?CommunicationLog => $this->find($actor, $id));

        // Đọc lại qua tập R3 sau commit (kèm vụ cho presenter).
        $log = $log === null ? null : $this->find($actor, (int) $log->getKey());

        return $log === null ? null : TwoStepOutcome::confirmed($log, $replayed);
    }

    /** Một dòng nhật ký liên lạc của vụ trong tập R3, chưa xoá, nạp sẵn vụ cho presenter. */
    private function find(User $actor, int $logId): ?CommunicationLog
    {
        return $this->scope->constrain(CommunicationLog::query(), $actor)
            ->whereKey($logId)
            ->with(['matter' => fn (BelongsTo $matter) => $matter->withoutGlobalScope(ClientPortalScope::class)])
            ->first();
    }
}
