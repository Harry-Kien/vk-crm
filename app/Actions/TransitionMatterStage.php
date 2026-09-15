<?php

namespace App\Actions;

use App\Enums\Role;
use App\Events\StageLogPublished;
use App\Exceptions\InvalidStageTransition;
use App\Models\Matter;
use App\Models\StageLog;
use App\Models\User;
use App\Support\Audit;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Chuyển giai đoạn vụ việc, HOẶC thêm một dòng cập nhật không đổi giai đoạn — cùng một Action
 * (SPEC §6.2 và §6.3). Dòng cập nhật không đổi giai đoạn (§6.3) là trường hợp `toStage` bằng
 * đúng giai đoạn hiện tại của `$matter`; khi đó bước 1 (kiểm tra `allowed_next`) KHÔNG áp dụng —
 * cả `from_stage` lẫn `to_stage` của `StageLog` đều bằng giai đoạn hiện tại.
 *
 * Bảy bước SPEC §6.2, tất cả trong một transaction:
 *  1. `toStage` phải nằm trong `allowed_next` của giai đoạn hiện tại, TRỪ khi bằng giai đoạn
 *     hiện tại (§6.3). Vai trò `admin` được phép bỏ qua vế `allowed_next`, nhưng không bỏ qua
 *     việc `toStage` phải ứng với một giai đoạn có cấu hình thật của loại vụ việc.
 *  2. Kiểm tra quyền qua `MatterPolicy::transitionStage` — Action tự kiểm tra, không tin caller.
 *  3. `publish = true` đòi `public_content` tối thiểu 30 ký tự — ném lỗi xác thực, không cắt bớt.
 *  4. Tạo `StageLog`; `expected_next_update_at` để trống thì tự tính từ `default_next_update_days`
 *     của giai đoạn MỚI (giai đoạn hiện tại, trong trường hợp cùng giai đoạn).
 *  5. Cập nhật `matters.stage`, `stage_entered_at` (= `occurred_at`, thời điểm thực tế do người
 *     nhập chọn — kể cả khi giai đoạn không đổi, đúng như SPEC mô tả không có ngoại lệ).
 *  6. Chỉ khi `publish` VÀ `matter.is_published_to_portal`: cập nhật `last_client_update_at` và
 *     dispatch `StageLogPublished` (listener + job gửi thông báo thuộc M6, không viết ở đây).
 *  7. Ghi activity log — luôn ghi, kể cả khi không có gì bất thường, và ghi rõ nếu bước 1 đã bị
 *     một admin bỏ qua (`bypassed_allowed_next`), để dấu vết không bị mất.
 */
class TransitionMatterStage
{
    public function handle(
        Matter $matter,
        User $actor,
        string $toStage,
        DateTimeInterface|string $occurredAt,
        ?string $internalNote,
        ?string $publicContent,
        ?string $nextStep,
        ?string $clientAction,
        DateTimeInterface|string|null $expectedNextUpdateAt,
        bool $publish,
    ): StageLog {
        return DB::transaction(function () use (
            $matter, $actor, $toStage, $occurredAt, $internalNote, $publicContent,
            $nextStep, $clientAction, $expectedNextUpdateAt, $publish,
        ): StageLog {
            $fromStage = $matter->stage;
            $isSameStage = $toStage === $fromStage;
            $currentStageConfig = $matter->currentStage();

            // Giai đoạn mới phải có cấu hình thật, dù là dòng cùng giai đoạn (thì đó chính là
            // $currentStageConfig, luôn tồn tại) hay một chuyển giai đoạn thật sự.
            $targetStageConfig = $isSameStage ? $currentStageConfig : $matter->matterType->stage($toStage);

            if ($targetStageConfig === null) {
                throw InvalidStageTransition::make($matter, $fromStage, $toStage);
            }

            // Bước 1: allowed_next không áp dụng cho dòng cùng giai đoạn (SPEC §6.3).
            $bypassedAllowedNext = false;

            if (! $isSameStage && ! ($currentStageConfig?->allows($toStage) ?? false)) {
                if (! $actor->hasRole(Role::Admin->value)) {
                    throw InvalidStageTransition::make($matter, $fromStage, $toStage);
                }

                $bypassedAllowedNext = true;
            }

            // Bước 2: Action tự kiểm tra quyền, không dựa vào caller đã kiểm tra hay chưa.
            Gate::forUser($actor)->authorize('transitionStage', $matter);

            // Bước 3.
            if ($publish && mb_strlen(trim($publicContent ?? '')) < 30) {
                throw ValidationException::withMessages([
                    'public_content' => [__('actions.transition_matter_stage.public_content_too_short')],
                ]);
            }

            // Bước 4.
            $expectedNextUpdateAt ??= now()->addDays($targetStageConfig->default_next_update_days);

            $stageLog = $matter->stageLogs()->create([
                'from_stage' => $fromStage,
                'to_stage' => $toStage,
                'occurred_at' => $occurredAt,
                'internal_note' => $internalNote,
                'public_content' => $publicContent,
                'next_step' => $nextStep,
                'client_action' => $clientAction,
                'expected_next_update_at' => $expectedNextUpdateAt,
                'is_published' => $publish,
                'published_at' => $publish ? now() : null,
            ]);

            // Bước 5.
            $matter->update([
                'stage' => $toStage,
                'stage_entered_at' => $occurredAt,
            ]);

            // Bước 6.
            $publishedToPortal = $publish && $matter->is_published_to_portal;

            if ($publishedToPortal) {
                $matter->update(['last_client_update_at' => now()]);

                event(new StageLogPublished($stageLog));
            }

            // Bước 7.
            Audit::record('matter_stage_transitioned', $matter, [
                'stage_log_id' => $stageLog->id,
                'from_stage' => $fromStage,
                'to_stage' => $toStage,
                'same_stage' => $isSameStage,
                'publish' => $publish,
                'published_to_portal' => $publishedToPortal,
                'bypassed_allowed_next' => $bypassedAllowedNext,
            ]);

            return $stageLog;
        });
    }
}
