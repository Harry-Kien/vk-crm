<?php

namespace App\Actions;

use App\Enums\Role;
use App\Events\StageLogPublished;
use App\Exceptions\InvalidStageTransition;
use App\Exceptions\MatterNotPublishedToPortal;
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
 *  3. `publish = true` đòi thêm ba điều kiện: (a) `matter.is_published_to_portal = true` — nếu
 *     không, một dòng công bố sẽ nằm im rồi lộ nguyên backlog ra portal ngay khi ai đó bật công
 *     tắc portal sau này, nên bị chặn từ gốc bằng `MatterNotPublishedToPortal` thay vì chỉ chặn
 *     tác dụng phụ ở bước 6; (b) `actor` phải có `stageLog.publish` — `StageLogPolicy::publish`
 *     được hỏi thật qua Gate, không giả định nó trùng với `matter.transitionStage` dù ma trận
 *     quyền hiện seed trùng nhau; (c) `public_content` tối thiểu 30 ký tự (mb_strlen, không phải
 *     byte) — ném lỗi xác thực, không cắt bớt.
 *  4. Tạo `StageLog`; `expected_next_update_at` để trống thì tự tính từ `default_next_update_days`
 *     của giai đoạn MỚI (giai đoạn hiện tại, trong trường hợp cùng giai đoạn). `created_by` /
 *     `updated_by` được gán TƯỜNG MINH từ `$actor` — Action nhận actor rõ ràng để kiểm tra quyền,
 *     nên dòng trong sổ pháp lý append-only này phải ghi đúng actor đó, không suy luận (có thể
 *     sai, hoặc rỗng) từ `auth()` ambient như `HasBlameable` mặc định làm.
 *  5. Cập nhật `matters.stage`; `stage_entered_at` CHỈ đổi khi giai đoạn thật sự thay đổi — một
 *     dòng cập nhật không đổi giai đoạn (§6.3) không được phép tua lại "đã ở giai đoạn này bao
 *     lâu", vì SPEC §6.4 (SLA 14 ngày) và widget quá hạn ở §7.1 đọc tín hiệu đó.
 *  6. Chỉ khi `publish` VÀ `matter.is_published_to_portal`: cập nhật `last_client_update_at` và
 *     dispatch `StageLogPublished` (listener + job gửi thông báo thuộc M6, không viết ở đây).
 *     Bước 5 và 6 dùng CHUNG một lệnh `update()` để không tạo hai dòng "updated" riêng của
 *     spatie/laravel-activitylog cho một thao tác của luật sư (xem bước 7).
 *  7. Ghi activity log — luôn ghi, kể cả khi không có gì bất thường, và ghi rõ nếu bước 1 đã bị
 *     một admin bỏ qua (`bypassed_allowed_next`), để dấu vết không bị mất. Causer được truyền
 *     tường minh là `$actor`, cùng lý do với bước 4.
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
            if ($publish) {
                // (b) StageLogPolicy::publish hỏi thật qua Gate — không giả định nó trùng
                // matter.transitionStage. Dùng một StageLog chưa lưu, gắn sẵn quan hệ matter, vì
                // policy cần $stageLog->matter để kiểm tra canSeeMatter().
                $transientStageLog = (new StageLog)->setRelation('matter', $matter);
                Gate::forUser($actor)->authorize('publish', $transientStageLog);

                // (a) Không cho một dòng công bố nằm chờ trên một vụ việc chưa bật portal.
                if (! $matter->is_published_to_portal) {
                    throw MatterNotPublishedToPortal::make($matter);
                }

                // (c) mb_strlen, không phải strlen: một chuỗi tiếng Việt 29 ký tự có thể dài hơn
                // 30 byte, và strlen sẽ sai chấp nhận nó.
                if (mb_strlen(trim($publicContent ?? '')) < 30) {
                    throw ValidationException::withMessages([
                        'public_content' => [__('actions.transition_matter_stage.public_content_too_short')],
                    ]);
                }
            }

            // Bước 4. Công thức này CỐ Ý trùng với
            // App\Filament\Admin\Resources\Matters\Actions\Concerns\BuildsStageUpdateSchema::stageDefaultNextUpdateAt()
            // (fix round 1, task 9, finding E) — bên đó chỉ tính để prefill/gợi ý trên form, đây mới
            // là nơi tính lại thật sự khi form gửi lên rỗng. Đổi công thức thì phải sửa cả hai nơi.
            $expectedNextUpdateAt ??= now()->addDays($targetStageConfig->default_next_update_days);

            $stageLog = new StageLog([
                'matter_id' => $matter->id,
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
            // Gán tường minh TRƯỚC khi save(): HasBlameable chỉ điền created_by/updated_by khi
            // còn trống (??=), nên giá trị đặt ở đây luôn thắng, bất kể auth('web') ambient có
            // khớp $actor hay không — kể cả khi không có phiên đăng nhập nào (lệnh console, job).
            $stageLog->created_by = $actor->id;
            $stageLog->updated_by = $actor->id;
            $stageLog->save();

            // Bước 5 + 6, gộp một lệnh update() (xem docblock lớp).
            $publishedToPortal = $publish && $matter->is_published_to_portal;

            $matterUpdates = ['stage' => $toStage];

            if (! $isSameStage) {
                $matterUpdates['stage_entered_at'] = $occurredAt;
            }

            if ($publishedToPortal) {
                $matterUpdates['last_client_update_at'] = now();
            }

            $matter->update($matterUpdates);

            if ($publishedToPortal) {
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
            ], $actor);

            return $stageLog;
        });
    }
}
