<?php

namespace App\Actions\Mcp\Write;

use App\Actions\Mcp\McpMatterScope;
use App\Actions\Mcp\UseStageLogDraft;
use App\Actions\Mcp\Write\Concerns\WritesIdempotentDrafts;
use App\Models\Matter;
use App\Models\StageLogDraft;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Tool `draft_progress_update` (kế hoạch M11, bảng tool 12; R5, R6): ghi một NHÁP dòng cập nhật
 * tiến độ không đổi giai đoạn vào `stage_log_drafts` — không bao giờ vào `stage_logs`, nên về cấu trúc
 * nó không tới được cổng khách, không công bố, không sinh thư. Một người trong `/admin` mở nháp ở tab
 * Tiến độ, sửa, rồi bấm "Thêm cập nhật" ({@see UseStageLogDraft}, Task 12) dưới tên của chính họ.
 *
 * Thứ tự:
 *  1. vụ trong tập R3 của người gọi, không thì `null` ("Không tìm thấy");
 *  2. cổng của web: `MatterPolicy::transitionStage` — cùng cổng với nút "Thêm cập nhật"
 *     (`TransitionMatterStage` bước 2); trợ lý không có quyền này (SPEC §5). Không qua thì
 *     `mcp.tool_errors.forbidden`;
 *  3. một transaction theo thứ tự khoá chung — dòng `matters` TRƯỚC (câu đầu tiên), rồi mới đọc khoá
 *     và ghi nháp. Vụ biến mất giữa bước 1 và bước 3 (xoá mềm) thì `null`;
 *  4. `idempotency_key` ({@see WritesIdempotentDrafts}): trúng khoá với đúng vụ và đúng nội dung thì
 *     trả nháp cũ; trúng khoá mà khác thì lỗi; chưa có thì ghi nháp mới.
 *
 * Server ÉP người soạn (`created_by` = `$actor`, ghi tường minh — không cột nào khác nhận người từ
 * tham số). `internal_note` được GHI vào nháp (R4: AI không đọc ghi chú nội bộ, nhưng soạn được); tool
 * không bao giờ trả nội dung của nó, chỉ cờ `has_internal_note`.
 */
final class DraftProgressUpdate
{
    use WritesIdempotentDrafts;

    /**
     * Trần mỗi ô nội dung: các cột tương ứng của `stage_logs` là `TEXT` (65.535 byte); 16.383 ký tự
     * utf8mb4 (tối đa 4 byte mỗi ký tự) luôn vừa, để nháp luôn dùng được nguyên văn trên MariaDB strict.
     */
    public const TEXT_MAX_LENGTH = 16383;

    public function __construct(private readonly McpMatterScope $scope) {}

    /**
     * @param  array{public_content: string, next_step: ?string, client_action: ?string, expected_next_update_at: ?string, internal_note: ?string}  $content
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, int $matterId, array $content, string $idempotencyKey): ?DraftOutcome
    {
        $matter = $this->scope->find($actor, $matterId);

        if ($matter === null) {
            return null;
        }

        if (Gate::forUser($actor)->denies('transitionStage', $matter)) {
            throw new AuthorizationException(__('mcp.tool_errors.forbidden'));
        }

        $key = $this->normalizedKey($idempotencyKey);
        $values = [
            'public_content' => $this->optional($content['public_content']),
            'next_step' => $this->optional($content['next_step']),
            'client_action' => $this->optional($content['client_action']),
            'expected_next_update_at' => $content['expected_next_update_at'],
            'internal_note' => $this->optional($content['internal_note']),
        ];

        return $this->writeOnce(
            fn (): ?DraftOutcome => DB::transaction(function () use ($actor, $matter, $key, $values): ?DraftOutcome {
                // Câu ĐẦU TIÊN chạm CSDL: khoá vụ (thứ tự khoá chung). `Matter::query()` loại vụ đã xoá mềm.
                $locked = $this->scopelessly(Matter::query())->lockForUpdate()->find($matter->getKey());

                if ($locked === null) {
                    return null;
                }

                $existing = $this->existingDraft(StageLogDraft::class, $actor, $key);

                if ($existing !== null) {
                    return $this->replay($existing, $locked, $values);
                }

                $draft = new StageLogDraft([...$values, 'matter_id' => $locked->getKey(), 'idempotency_key' => $key]);
                $draft->forceFill(['created_by' => $actor->getKey()])->save();

                return new DraftOutcome($draft->refresh()->setRelation('matter', $locked), created: true);
            }),
            fn (): DraftOutcome => $this->replay(
                $this->existingDraft(StageLogDraft::class, $actor, $key) ?? throw $this->conflict(),
                $matter,
                $values,
            ),
        );
    }

    /** @param  array<string, ?string>  $values */
    private function replay(StageLogDraft $existing, Matter $matter, array $values): DraftOutcome
    {
        $same = (int) $existing->matter_id === (int) $matter->getKey()
            && $existing->public_content === $values['public_content']
            && $existing->next_step === $values['next_step']
            && $existing->client_action === $values['client_action']
            && $existing->expected_next_update_at?->toDateString() === $values['expected_next_update_at']
            && $existing->internal_note === $values['internal_note'];

        if (! $same) {
            throw $this->conflict();
        }

        return new DraftOutcome($existing->setRelation('matter', $matter), created: false);
    }
}
