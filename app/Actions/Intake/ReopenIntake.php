<?php

namespace App\Actions\Intake;

use App\Actions\Intake\Concerns\HoldsConflictCheckLock;
use App\Enums\IntakeStatus;
use App\Models\IntakeRequest;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Làn fa (kiểm tra nghiệp vụ 2026-10-09, mục nhỏ) — MỞ LẠI một lần tiếp nhận đã "Khách không theo tiếp"
 * (`lost`) hay "Văn phòng từ chối" (`declined`), kể cả khi bấm nhầm. Trước Action này hai trạng thái đó là
 * cuối (`ChangeIntakeStatus::OPEN` không gồm chúng): khách quay lại sau một tuần thì phải ghi một bản mới,
 * câu chuyện cũ nằm ở bản đã đóng, và đồng hồ ẩn danh (R7b) vẫn chạy trên bản cũ.
 *
 * **Quyền:** `IntakeRequestPolicy::resolveConflict` (quản lý/admin, thấy được bản ghi) — cùng người xử
 * lý Đỏ, vì mở lại một bản từ chối vì xung đột là quyết định về xung đột. Bản đã xong việc
 * (`isClosedToChanges()`: đã gộp, đã ẩn danh, đã chuyển thành vụ) không mở lại được.
 *
 * **Làm gì** (trên dòng vừa khoá, dưới khoá `conflict-check` vì có thể đặt lại khoá Đỏ):
 *  - `status` về `contacted` (đã có lần phản hồi đầu: `first_response_at` giữ nguyên);
 *  - `retention_until` về null — bản ghi còn mở thì không bị ẩn danh hết hạn;
 *  - **từ chối vì xung đột**: KHÔNG thả khoá. Cờ `decline_reason_is_conflict` đổi thành dấu "Đỏ chờ xử
 *    lý" (`conflict_red_pending_since`, giữ thời điểm cũ nếu có): bản ghi tiếp tục khoá cuộc gọi lại và
 *    ô câu chuyện cho tới khi quản lý/admin ghi đè kèm lý do (`ResolveIntakeRedConflict`) — đúng hai
 *    cách xử lý Đỏ của R1. Chỉ đổi DẠNG khoá để nó gỡ được bằng đúng đường đó.
 *  - `decline_reason` giữ nguyên (lịch sử; chỉ hiện ở khối "Kết quả xử lý" của một bản đang từ chối).
 *
 * Lý do bắt buộc, {@see self::REASON_MIN}–{@see self::REASON_MAX} ký tự; dòng `intake_reopened` mang
 * trạng thái cũ và lý do (của người mở lại, không phải lý do từ chối cũ).
 */
class ReopenIntake
{
    use HoldsConflictCheckLock;

    public const REASON_MIN = 10;

    public const REASON_MAX = 2000;

    /** Trạng thái mở lại được — một định nghĩa cho Action và cho nút. */
    public const REOPENABLE = [IntakeStatus::Lost, IntakeStatus::Declined];

    public function handle(User $actor, IntakeRequest $intake, string $reason): IntakeRequest
    {
        Gate::forUser($actor)->authorize('resolveConflict', $intake);

        $reason = trim($reason);

        if (mb_strlen($reason) < self::REASON_MIN || mb_strlen($reason) > self::REASON_MAX) {
            throw ValidationException::withMessages(['reopen_reason' => [__('intake_reopen.errors.reason_length', [
                'min' => self::REASON_MIN, 'max' => self::REASON_MAX,
            ])]]);
        }

        return $this->underConflictCheckLock(fn (): IntakeRequest => DB::transaction(function () use ($actor, $intake, $reason): IntakeRequest {
            $locked = IntakeRequest::query()->whereKey($intake->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if ($locked->isClosedToChanges() || ! in_array($from, self::REOPENABLE, true)) {
                throw ValidationException::withMessages(['reopen_reason' => [__('intake_reopen.errors.not_reopenable', [
                    'status' => $from->label(),
                ])]]);
            }

            if ($locked->decline_reason_is_conflict) {
                $locked->conflict_red_pending_since ??= now();
                $locked->decline_reason_is_conflict = false;
            }

            $locked->status = IntakeStatus::Contacted;
            $locked->retention_until = null;
            $locked->blameOn($actor);
            $locked->disableLogging()->save();
            $locked->enableLogging();

            Audit::record('intake_reopened', $locked, ['from' => $from->value, 'reason' => $reason], $actor);

            return $locked;
        }));
    }
}
