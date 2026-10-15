<?php

namespace App\Actions\Intake;

use App\Actions\Intake\Concerns\HoldsConflictCheckLock;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Enums\IntakeStatus;
use App\Models\IntakeRequest;
use App\Models\User;
use App\Notifications\Staff\IntakeRedPending;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
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
 *    cách xử lý Đỏ của R1. Chỉ đổi DẠNG khoá để nó gỡ được bằng đúng đường đó. Làn fi (B3): khi dấu đó
 *    được đặt LẦN ĐẦU ở đây (trước đó null), trưởng phòng/quản trị nhận {@see IntakeRedPending} như
 *    đường thường của `CheckIntakeConflict` — người nhận
 *    {@see ResolveStaffRecipients::forIntakeConflictResolution()}, sau commit, chỉ mã bản ghi;
 *  - `decline_reason` giữ nguyên (lịch sử; chỉ hiện ở khối "Kết quả xử lý" của một bản đang từ chối);
 *  - làn fi (B1): hẹn liên hệ lại (`next_follow_up_at`) đã qua thì xoá — lead vừa mở lại không hiện
 *    "quá hạn chăm sóc" vì một hẹn từ trước khi nó đóng; hẹn còn ở tương lai thì giữ;
 *  - làn fi, vòng sửa 1 (I2): luật sư tư vấn (`consulting_lawyer_id`) không còn nhận được lead
 *    ({@see HandIntakeToLawyer::canReceive()}: đã nghỉ, bị khoá, đổi sang vai không mở được vụ) thì bỏ,
 *    cùng `handed_to_lawyer_at` — lead đã đóng không chặn ai nghỉ việc hay đổi vai (`OpenLeads`), nên lúc
 *    mở lại là lúc duy nhất bắt được; lead về tay người phụ trách, họ "Chuyển cho luật sư" lại nếu cần.
 *
 * Lý do bắt buộc, {@see self::REASON_MIN}–{@see self::REASON_MAX} ký tự; dòng `intake_reopened` mang
 * trạng thái cũ và lý do (của người mở lại, không phải lý do từ chối cũ), và `consulting_lawyer_dropped`
 * (id luật sư vừa bỏ) khi có.
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

        /** @var array{0: IntakeRequest, 1: bool} $outcome */
        $outcome = $this->underConflictCheckLock(fn (): array => DB::transaction(function () use ($actor, $intake, $reason): array {
            $locked = IntakeRequest::query()->whereKey($intake->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if ($locked->isClosedToChanges() || ! in_array($from, self::REOPENABLE, true)) {
                throw ValidationException::withMessages(['reopen_reason' => [__('intake_reopen.errors.not_reopenable', [
                    'status' => $from->label(),
                ])]]);
            }

            $redJustPending = false;

            if ($locked->decline_reason_is_conflict) {
                $redJustPending = $locked->conflict_red_pending_since === null;
                $locked->conflict_red_pending_since ??= now();
                $locked->decline_reason_is_conflict = false;
            }

            if ($locked->next_follow_up_at !== null && ! $locked->next_follow_up_at->isFuture()) {
                $locked->next_follow_up_at = null;
            }

            $properties = ['from' => $from->value, 'reason' => $reason];

            // Làn fi, vòng sửa 1 (I2) — xem docblock lớp.
            if ($locked->consulting_lawyer_id !== null) {
                $consulting = User::withTrashed()->find($locked->consulting_lawyer_id);

                if ($consulting === null || ! HandIntakeToLawyer::canReceive($consulting)) {
                    $properties['consulting_lawyer_dropped'] = $locked->consulting_lawyer_id;
                    $locked->consulting_lawyer_id = null;
                    $locked->handed_to_lawyer_at = null;
                }
            }

            $locked->status = IntakeStatus::Contacted;
            $locked->retention_until = null;
            $locked->blameOn($actor);
            $locked->disableLogging()->save();
            $locked->enableLogging();

            Audit::record('intake_reopened', $locked, $properties, $actor);

            return [$locked, $redJustPending];
        }));

        [$reopened, $redJustPending] = $outcome;

        // Làn fi (B3): báo SAU khi transaction trên đã commit (ngoài nó; `afterCommit` còn đợi một
        // transaction bao ngoài nếu người gọi có).
        if ($redJustPending) {
            DB::afterCommit(fn () => Notification::send(
                app(ResolveStaffRecipients::class)->forIntakeConflictResolution($reopened),
                new IntakeRedPending($reopened),
            ));
        }

        return $reopened;
    }
}
