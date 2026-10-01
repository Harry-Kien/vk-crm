<?php

namespace App\Actions\Intake;

use App\Actions\Intake\Concerns\HoldsConflictCheckLock;
use App\Models\IntakeRequest;
use App\Models\User;
use App\Support\Audit;
use App\Support\ConflictOverride;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Ghi đè xung đột ĐỎ của một lần tiếp nhận, kèm lý do bắt buộc (M10 R1) — CÙNG quy tắc ghi đè Đỏ của
 * `OpenMatter` và SPEC §6.10: chỉ quản lý hoặc admin ({@see ConflictOverride::allowedFor()},
 * qua `IntakeRequestPolicy::resolveConflict`, và phải xem được bản ghi). Người khác — trợ lý, luật sư
 * — nhận `AuthorizationException`, không lý do nào mở được. (Từ chối vụ việc vì xung đột, R8, là lối
 * thứ hai và thuộc màn hình Task 3.)
 *
 * **Chạy lại kiểm tra ngay trước khi ghi đè**, dưới khoá `conflict-check`, để ghi đè nhắm đúng cái
 * đang Đỏ lúc bấm chứ không phải một kết quả đã cũ; lần chạy đó commit trước (bằng chứng không mất
 * nếu ghi đè bị từ chối). Không còn Đỏ nào thì từ chối — không ghi đè khống.
 *
 * Ghi đè lưu người (`conflict_overridden_by`) và lý do (`conflict_override_reason`, cột riêng, chỉ
 * người có `intake.viewAny` đọc — lý do xung đột là loại nhạy cảm, R8). Dòng `intake_conflict_overridden`
 * mang `confirmed_pairs` (chữ ký + MỨC của MỌI khớp mới, kể cả Vàng đi kèm: ghi đè Đỏ che cả Vàng
 * như ở `OpenMatter`), KHÔNG mang lý do hay bất kỳ tên nào — nhật ký hệ thống đọc được rộng hơn cột.
 *
 * Người nhập không được biết vì sao Đỏ ngoài mã hồ sơ và vai của khớp (ranh giới `ConflictMatch`).
 */
class ResolveIntakeRedConflict
{
    use HoldsConflictCheckLock;

    private const REASON_MAX_LENGTH = 2000;

    public function handle(User $actor, IntakeRequest $intake, string $reason): IntakeRequest
    {
        Gate::forUser($actor)->authorize('resolveConflict', $intake);

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => [__('intake.errors.override_reason_required')]]);
        }

        if (mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            throw ValidationException::withMessages(['reason' => [__('intake.errors.override_reason_too_long')]]);
        }

        return $this->underConflictCheckLock(function () use ($actor, $intake, $reason): IntakeRequest {
            $result = $this->checkAndRecord($actor, $intake);

            if (! $result->isBlocking()) {
                throw ValidationException::withMessages(['reason' => [__('intake.errors.override_not_red')]]);
            }

            return DB::transaction(function () use ($actor, $intake, $reason, $result): IntakeRequest {
                $locked = IntakeRequest::query()->whereKey($intake->getKey())->lockForUpdate()->firstOrFail();

                $locked->fill([
                    'conflict_overridden_by' => $actor->getKey(),
                    'conflict_override_reason' => $reason,
                ])->blameOn($actor)->save();

                Audit::record('intake_conflict_overridden', $locked, [
                    'level' => $result->level->value,
                    'confirmed_pairs' => AcknowledgeIntakeConflict::confirmedPairs($result),
                ], $actor);

                return $locked;
            });
        });
    }
}
