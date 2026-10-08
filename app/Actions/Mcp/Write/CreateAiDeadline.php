<?php

namespace App\Actions\Mcp\Write;

use App\Actions\Deadline\AddMatterDeadline;
use App\Actions\Mcp\McpMatterScope;
use App\Actions\Mcp\Write\Concerns\ConfirmsInTwoSteps;
use App\Enums\CreatedVia;
use App\Enums\DeadlineSeverity;
use App\Models\Deadline;
use App\Models\User;
use App\Support\Mcp\ConfirmationToken;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Tool `create_deadline` (kế hoạch M11, bảng tool 14; R5, R6): thêm một mốc thời hạn qua ĐÚNG
 * {@see AddMatterDeadline} — Action của nút "Thêm nhanh" ở tab Mốc thời hạn — trong hai bước
 * ({@see ConfirmsInTwoSteps}).
 *
 * Thứ tự, giống nhau ở cả hai bước:
 *  1. vụ phải trong tập R3 của người gọi ({@see McpMatterScope::find()}), không thì `null` — tool trả
 *     "Không tìm thấy" y như tool đọc;
 *  2. cổng của web: `MatterPolicy::update` — đúng câu `AddMatterDeadline` hỏi; không qua thì
 *     `AuthorizationException` với câu `mcp.tool_errors.forbidden` (vụ đã thấy được qua MCP, nên nói
 *     thẳng "không có quyền ghi" không lộ gì);
 *  3. người phụ trách: không chỉ định thì `AddMatterDeadline` lấy luật sư phụ trách; chỉ định một id
 *     không có thật thì cùng câu với một người không giữ được mốc (`responsible_cannot_open`), để id
 *     người dùng không dò được;
 *  4. lần một: chạy thử (không ghi gì) rồi cấp mã; lần hai: kiểm mã ({@see ConfirmationToken}) rồi ghi
 *     đúng một lần.
 *
 * Server ÉP ba thứ, không nhận từ tham số (R5): `is_published = false`, `created_via = mcp`, và người
 * tạo là `$actor` — người sở hữu token, truyền tường minh tới `AddMatterDeadline` (không bao giờ đoán từ
 * `auth()`; trong request `/mcp` guard `web` rỗng). Mốc tạo qua AI mang nhãn "Tạo qua AI, chưa xác
 * nhận" trên web cho tới khi một người bấm "Xác nhận" (Task 12), và VẪN được nhắc hạn như mốc thường.
 *
 * Hạn trong quá khứ bị tool từ chối (thu hẹp so với web, nơi luật sư ghi lại mốc đã lỡ khi nhận bàn
 * giao): một mốc đã qua do AI tạo nhiều khả năng là nhầm năm, và người cần ghi mốc đã qua có màn hình.
 */
final class CreateAiDeadline
{
    use ConfirmsInTwoSteps;

    public const TOOL = 'create_deadline';

    public function __construct(
        private readonly McpMatterScope $scope,
        private readonly AddMatterDeadline $add,
    ) {}

    /**
     * @param  int|null  $responsibleId  `null` = luật sư phụ trách; `0` = id không đọc được
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(
        User $actor,
        int $matterId,
        string $name,
        string $dueDate,
        DeadlineSeverity $severity,
        ?int $responsibleId,
        ?string $confirmationToken,
    ): ?TwoStepOutcome {
        $matter = $this->scope->find($actor, $matterId);

        if ($matter === null) {
            return null;
        }

        if (Gate::forUser($actor)->denies('update', $matter)) {
            throw new AuthorizationException(__('mcp.tool_errors.forbidden'));
        }

        $responsible = $this->responsible($responsibleId);
        $signed = [
            'matter_id' => $matterId,
            'name' => trim($name),
            'due_date' => $dueDate,
            'severity' => $severity->value,
            'responsible_id' => $responsibleId,
        ];

        $write = fn (): Deadline => $this->add->handle(
            matter: $matter,
            actor: $actor,
            name: $name,
            dueDate: $dueDate,
            severity: $severity,
            responsible: $responsible,
            isPublished: false,
            createdVia: CreatedVia::Mcp,
        );

        if ($confirmationToken === null) {
            /** @var Deadline $preview */
            $preview = $this->dryRun($write);
            $preview->setRelation('matter', $matter);
            $preview->setRelation('responsible', User::query()->withTrashed()->find($preview->responsible_user_id));

            ['token' => $token, 'expires_at' => $expiresAt] = ConfirmationToken::issue($actor, self::TOOL, $signed);

            return TwoStepOutcome::preview($preview, $token, $expiresAt);
        }

        $jti = ConfirmationToken::verify($confirmationToken, $actor, self::TOOL, $signed)
            ?? throw ValidationException::withMessages(['confirmation_token' => [__('mcp.tool_errors.invalid_confirmation')]]);

        [$deadline, $replayed] = $this->confirmOnce($actor, self::TOOL, $matter, $jti, $write, fn (int $id): ?Deadline => $this->find($actor, $id));

        // Đọc lại qua tập R3 sau commit (kèm vụ và người phụ trách cho presenter).
        $deadline = $deadline === null ? null : $this->find($actor, (int) $deadline->getKey());

        return $deadline === null ? null : TwoStepOutcome::confirmed($deadline, $replayed);
    }

    /** Người phụ trách được chỉ định, hoặc `null` (mặc định của `AddMatterDeadline`). */
    private function responsible(?int $responsibleId): ?User
    {
        if ($responsibleId === null) {
            return null;
        }

        return User::query()->whereKey($responsibleId)->first()
            ?? throw ValidationException::withMessages([
                'responsible_id' => [__('deadlines.validation.responsible_cannot_open')],
            ]);
    }

    /** Một mốc của vụ trong tập R3, chưa xoá, nạp sẵn vụ và người phụ trách cho presenter. */
    private function find(User $actor, int $deadlineId): ?Deadline
    {
        return $this->scope->constrain(Deadline::query(), $actor)
            ->whereKey($deadlineId)
            ->with([
                'matter' => fn (BelongsTo $matter) => $matter->withoutGlobalScope(ClientPortalScope::class),
                'responsible' => fn (BelongsTo $responsible) => $responsible->withTrashed(),
            ])
            ->first();
    }
}
