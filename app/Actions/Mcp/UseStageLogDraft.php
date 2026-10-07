<?php

namespace App\Actions\Mcp;

use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Actions\TransitionMatterStage;
use App\Exceptions\McpDraftNotPending;
use App\Models\Matter;
use App\Models\StageLog;
use App\Models\StageLogDraft;
use App\Models\User;
use App\Support\Audit;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Gửi một dòng tiến độ từ một nháp do AI soạn (M11 R5, Task 12) — bước "người bấm nút trong
 * `/admin`" của tool `draft_progress_update`.
 *
 * Dòng tiến độ thật đi qua ĐÚNG `TransitionMatterStage` với `to_stage` = giai đoạn hiện tại ("Thêm
 * cập nhật", SPEC §6.3 — nháp không bao giờ đổi giai đoạn), dưới tên `$actor` là NGƯỜI BẤM, không
 * phải người đã soạn nháp qua AI. Nội dung là thứ người bấm gửi lên từ form (đã sửa hay chưa), không
 * phải bản trong nháp; công tắc công bố cũng do người bấm quyết, và mọi luật của SPEC §7.3 (≥ 30 ký
 * tự, vụ đã bật portal, `StageLogPolicy::publish`) vẫn do `TransitionMatterStage` giữ.
 *
 * Một transaction, theo thứ tự khoá chung — dòng `matters` TRƯỚC, rồi dòng nháp:
 *  1. khoá vụ; vụ đã xoá mềm, hay người bấm không có `transitionStage` trên vụ → câu từ chối chung,
 *     TRƯỚC khi nói gì về nháp (SPEC §10.10);
 *  2. khoá nháp; nháp không thuộc vụ này → cùng câu từ chối; nháp đã dùng hoặc đã bỏ (người khác
 *     vừa bấm ở tab khác) → {@see McpDraftNotPending}, không ghi gì;
 *  3. `TransitionMatterStage` (transaction lồng; nó khoá lại vụ — đã giữ khoá — và tự kiểm giai đoạn
 *     không trôi so với `$matter` người bấm đã thấy, ném `MatterStageChanged` nếu trôi);
 *  4. nháp trỏ `used_stage_log_id` tới dòng vừa sinh, và một dòng audit `mcp_draft_used` (chủ thể
 *     là dòng tiến độ, nên trang nhật ký lọc nó theo quyền xem vụ) mang id nháp và người soạn nháp.
 *
 * Bước 3 và 4 cùng một transaction: không có dòng tiến độ nào sinh ra từ nháp mà nháp vẫn "đang
 * chờ", và không có nháp "đã dùng" nào trỏ tới một dòng bị rollback. Sự kiện `StageLogPublished`
 * (thư cho khách) đợi commit như mọi lần "Thêm cập nhật".
 */
class UseStageLogDraft
{
    use ReadsWithoutPortalScope;

    public function handle(
        StageLogDraft $draft,
        Matter $matter,
        User $actor,
        DateTimeInterface|string $occurredAt,
        ?string $internalNote,
        ?string $publicContent,
        ?string $nextStep,
        ?string $clientAction,
        DateTimeInterface|string|null $expectedNextUpdateAt,
        bool $publish,
    ): StageLog {
        return DB::transaction(function () use (
            $draft, $matter, $actor, $occurredAt, $internalNote, $publicContent, $nextStep, $clientAction,
            $expectedNextUpdateAt, $publish,
        ): StageLog {
            // Câu ĐẦU TIÊN chạm CSDL: khoá vụ. `Matter::query()` loại vụ đã xoá mềm.
            $lockedMatter = $this->scopelessly(Matter::query())->lockForUpdate()->find($matter->getKey());

            if ($lockedMatter === null || Gate::forUser($actor)->denies('transitionStage', $lockedMatter)) {
                $this->refuse();
            }

            $fresh = $this->scopelessly(StageLogDraft::query())->lockForUpdate()->find($draft->getKey());

            if ($fresh === null || $fresh->matter_id !== $lockedMatter->getKey()) {
                $this->refuse();
            }

            if (! $fresh->isPending()) {
                throw McpDraftNotPending::make();
            }

            $stageLog = app(TransitionMatterStage::class)->handle(
                matter: $matter,
                actor: $actor,
                toStage: $matter->stage,
                occurredAt: $occurredAt,
                internalNote: $internalNote,
                publicContent: $publicContent,
                nextStep: $nextStep,
                clientAction: $clientAction,
                expectedNextUpdateAt: $expectedNextUpdateAt,
                publish: $publish,
            );

            $fresh->forceFill([StageLogDraft::usedColumn() => $stageLog->getKey()])->save();

            Audit::record('mcp_draft_used', $stageLog, [
                'matter_id' => $lockedMatter->getKey(),
                'stage_log_id' => $stageLog->getKey(),
                'draft_type' => StageLogDraft::draftType(),
                'draft_id' => $fresh->getKey(),
                'draft_created_by' => $fresh->created_by,
            ], causer: $actor);

            return $stageLog;
        });
    }

    /** Vụ không còn, không có quyền, hay nháp không thuộc vụ: một câu (SPEC §10.10). */
    private function refuse(): never
    {
        throw new AuthorizationException(__('ai_drafts.unavailable'));
    }
}
