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
 * đang có lúc bấm chứ không phải một kết quả đã cũ; lần chạy đó commit trước (bằng chứng không mất
 * nếu ghi đè bị từ chối). Sau đó, đọc từ dòng vừa khoá: chỉ ghi đè khi bản ghi có một Đỏ CHƯA XỬ LÝ
 * ({@see IntakeRequest::hasUnresolvedRed()}) — không ghi đè khống. Đỏ DÍNH (fix vòng 1, I2): đó có thể
 * là Đỏ của lần chạy vừa rồi, HOẶC một Đỏ đã có trước mà lần chạy vừa rồi không còn thấy (danh tính đã
 * sửa, bên đối lập đã gỡ), HOẶC khoá của một lần gọi lại (C1) — cả ba đều chỉ quản lý/admin mở được,
 * và lý do ghi đè là nơi họ nói vì sao.
 *
 * Ghi đè lưu người (`conflict_overridden_by`) và lý do (`conflict_override_reason`), và xoá dấu Đỏ đang
 * chờ (`conflict_red_pending_since`). Cột lý do chỉ là "ghi đè đang có hiệu lực": `CheckIntakeConflict`
 * xoá nó khi có khớp mới. **Bản lưu lâu dài của lý do là dòng `intake_conflict_overridden`** (fix vòng
 * 1, I1 — SPEC §6.10 "bắt buộc nhập lý do, ghi vào activity log", như `OpenMatter`/`AddMatterParty`
 * ghi `override_reason`): mỗi lần ghi đè một dòng, chỉ thêm, nên lý do của một ghi đè đã hết hiệu lực
 * vẫn còn đó. Dòng mang `level` (mức của lần chạy vừa rồi — có thể là Xanh khi Đỏ dính), lý do, và
 * `confirmed_pairs` (chữ ký + MỨC của MỌI khớp mới, kể cả Vàng đi kèm: ghi đè Đỏ che cả Vàng như ở
 * `OpenMatter`). Ai đọc được: người có `auditLog.view` — hôm nay manager và admin, đúng hai vai có
 * `intake.viewAny` (R8: lý do xung đột chỉ họ thấy). Lý do có thể nêu tên người: Task 7 (ẩn danh)
 * phải làm sạch khoá này cùng các dòng khác của bản ghi.
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

            return DB::transaction(function () use ($actor, $intake, $reason, $result): IntakeRequest {
                $locked = IntakeRequest::query()->whereKey($intake->getKey())->lockForUpdate()->firstOrFail();

                if (! $locked->hasUnresolvedRed()) {
                    throw ValidationException::withMessages(['reason' => [__('intake.errors.override_not_red')]]);
                }

                $locked->fill([
                    'conflict_overridden_by' => $actor->getKey(),
                    'conflict_override_reason' => $reason,
                ]);
                $locked->conflict_red_pending_since = null;
                $locked->blameOn($actor)->save();

                Audit::record('intake_conflict_overridden', $locked, [
                    'level' => $result->level->value,
                    'override_reason' => $reason,
                    'confirmed_pairs' => AcknowledgeIntakeConflict::confirmedPairs($result),
                ], $actor);

                return $locked;
            });
        });
    }
}
