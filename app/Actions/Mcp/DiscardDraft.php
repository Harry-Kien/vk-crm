<?php

namespace App\Actions\Mcp;

use App\Actions\Concerns\ChecksAccountActive;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Exceptions\McpDraftNotPending;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\ClientRequestReplyDraft;
use App\Models\Matter;
use App\Models\StageLogDraft;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * "Bỏ nháp" (M11 R5, Task 12): một người trong `/admin` quyết định không dùng một nháp do AI soạn.
 * Nháp không bị xoá (M6.5 R14) — nó mang `discarded_at`, người bỏ và lý do, và một dòng audit
 * `mcp_draft_discarded` ghi lại việc đó cùng lý do.
 *
 * Cổng là cổng của chính việc dùng nháp — ai được gửi từ nháp thì được bỏ nó:
 *  - nháp tiến độ: `MatterPolicy::transitionStage` trên vụ (cùng nút "Thêm cập nhật");
 *  - nháp trả lời: `ClientRequestReplyPolicy::create` với cuộc trao đổi (cùng nút "Trả lời"). Một
 *    cuộc trao đổi đã đóng vẫn bỏ nháp được: không có gì để gửi, nhưng nháp không được treo mãi.
 *
 * Lý do bắt buộc (khoá lỗi `reason`; trần {@see self::REASON_MAX_LENGTH} như lý do xoá nhật ký liên
 * lạc, vì nó nằm cả trong `activity_log.properties`), kiểm TRƯỚC transaction. Trong transaction, thứ
 * tự khoá chung: dòng cha (`matters` hay `client_requests`) TRƯỚC, rồi dòng nháp. Tài khoản đã vô
 * hiệu hoá, không có quyền, hay nháp không thuộc dòng cha → câu từ chối chung; nháp đã dùng hoặc đã
 * bỏ (người khác vừa bấm) → {@see McpDraftNotPending}. Chủ thể audit là vụ hay cuộc trao đổi — hai thứ
 * trang nhật ký đã biết lọc theo quyền xem vụ — chứ không phải nháp.
 */
class DiscardDraft
{
    use ChecksAccountActive;
    use ReadsWithoutPortalScope;

    public const REASON_MAX_LENGTH = 1000;

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(StageLogDraft|ClientRequestReplyDraft $draft, User $actor, string $reason): StageLogDraft|ClientRequestReplyDraft
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => [__('ai_drafts.validation.reason_required')]]);
        }

        if (mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            throw ValidationException::withMessages([
                'reason' => [__('ai_drafts.validation.reason_max', ['max' => self::REASON_MAX_LENGTH])],
            ]);
        }

        return DB::transaction(fn (): StageLogDraft|ClientRequestReplyDraft => $draft instanceof StageLogDraft
            ? $this->discardStageLogDraft($draft, $actor, $reason)
            : $this->discardReplyDraft($draft, $actor, $reason));
    }

    private function discardStageLogDraft(StageLogDraft $draft, User $actor, string $reason): StageLogDraft
    {
        // Câu ĐẦU TIÊN chạm CSDL: khoá vụ (`matter_id` đọc từ nháp đã có trong tay, không truy vấn).
        $matter = $this->scopelessly(Matter::query())->lockForUpdate()->find($draft->matter_id);

        if ($matter === null || ! $this->accountIsActive($actor)
            || Gate::forUser($actor)->denies('transitionStage', $matter)) {
            $this->refuse();
        }

        $fresh = $this->lockPendingDraft(StageLogDraft::class, $draft, 'matter_id', $matter->getKey());

        $this->markDiscarded($fresh, $actor, $reason);

        Audit::record('mcp_draft_discarded', $matter, [
            'matter_id' => $matter->getKey(),
            'draft_type' => StageLogDraft::draftType(),
            'draft_id' => $fresh->getKey(),
            'draft_created_by' => $fresh->created_by,
            'reason' => $reason,
        ], causer: $actor);

        return $fresh;
    }

    private function discardReplyDraft(ClientRequestReplyDraft $draft, User $actor, string $reason): ClientRequestReplyDraft
    {
        // Câu ĐẦU TIÊN chạm CSDL: khoá cuộc trao đổi.
        $thread = $this->scopelessly(ClientRequest::query())->lockForUpdate()->find($draft->request_id);

        if ($thread === null || ! $this->accountIsActive($actor)) {
            $this->refuse();
        }

        $thread->setRelation('matter', $this->scopelessly(Matter::query())->find($thread->matter_id));

        if (Gate::forUser($actor)->denies('create', [ClientRequestReply::class, $thread])) {
            $this->refuse();
        }

        $fresh = $this->lockPendingDraft(ClientRequestReplyDraft::class, $draft, 'request_id', $thread->getKey());

        $this->markDiscarded($fresh, $actor, $reason);

        Audit::record('mcp_draft_discarded', $thread, [
            'matter_id' => $thread->matter_id,
            'client_request_id' => $thread->getKey(),
            'draft_type' => ClientRequestReplyDraft::draftType(),
            'draft_id' => $fresh->getKey(),
            'draft_created_by' => $fresh->created_by,
            'reason' => $reason,
        ], causer: $actor);

        return $fresh;
    }

    /**
     * Đọc lại nháp DƯỚI KHOÁ, sau khi dòng cha đã khoá: không thuộc dòng cha → câu chung; đã dùng hay
     * đã bỏ → {@see McpDraftNotPending}.
     *
     * @param  class-string<StageLogDraft|ClientRequestReplyDraft>  $model
     */
    private function lockPendingDraft(string $model, StageLogDraft|ClientRequestReplyDraft $draft, string $parentColumn, int $parentId): StageLogDraft|ClientRequestReplyDraft
    {
        $fresh = $this->scopelessly($model::query())->lockForUpdate()->find($draft->getKey());

        if ($fresh === null || $fresh->getAttribute($parentColumn) !== $parentId) {
            $this->refuse();
        }

        if (! $fresh->isPending()) {
            throw McpDraftNotPending::make();
        }

        return $fresh;
    }

    private function markDiscarded(StageLogDraft|ClientRequestReplyDraft $draft, User $actor, string $reason): void
    {
        $draft->forceFill([
            'discarded_at' => now(),
            'discarded_by' => $actor->getKey(),
            'discard_reason' => $reason,
        ])->save();
    }

    /** Tài khoản không còn hiệu lực, không có quyền, hay nháp không thuộc dòng cha: một câu (SPEC §10.10). */
    private function refuse(): never
    {
        throw new AuthorizationException(__('ai_drafts.unavailable'));
    }
}
