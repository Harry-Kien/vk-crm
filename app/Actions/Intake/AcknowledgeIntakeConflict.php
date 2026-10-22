<?php

namespace App\Actions\Intake;

use App\Actions\Intake\Concerns\HoldsConflictCheckLock;
use App\Enums\ConflictLevel;
use App\Exceptions\ConflictAcknowledgementRequired;
use App\Exceptions\ConflictBlocked;
use App\Models\IntakeRequest;
use App\Models\User;
use App\Support\Audit;
use App\Support\ConflictCheckResult;
use App\Support\ConflictMatch;
use App\Support\Intake\ShownConflictFingerprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Xác nhận đã xem đúng những khớp xung đột đang hiện — cổng VÀNG (và "thiếu định danh") của ô câu
 * chuyện (M10 R1). CÙNG cổng `OpenMatter` dùng: người xác nhận truyền `$acknowledged` bằng ĐÚNG
 * `level` của lần kiểm tra vừa chạy, để một xác nhận cho mức đã cũ (dữ liệu đổi từ lúc người đó nhìn
 * màn hình) không tự động hợp lệ.
 *
 * **Chạy lại kiểm tra ngay trước khi xác nhận**, dưới khoá `conflict-check`, thay vì tin kết quả đã
 * lưu: người bấm phải xác nhận cái đang đúng lúc bấm. Lần chạy đó được ghi (transaction riêng, commit
 * trước) nên bằng chứng "đã kiểm tra" không mất dù xác nhận bị từ chối. Sau đó:
 *  - Đỏ: từ chối bằng `ConflictBlocked` — Đỏ không xác nhận được, chỉ quản lý/admin xử lý
 *    ({@see ResolveIntakeRedConflict});
 *  - không có gì cần xác nhận (Xanh đủ định danh): từ chối, không ghi xác nhận (lần kiểm tra vừa
 *    chạy thì đã được ghi, như mọi trường hợp ở đây);
 *  - `$acknowledged` khác mức vừa chạy: `ConflictAcknowledgementRequired` mang kết quả mới.
 *
 * Xác nhận một Vàng KHÔNG mở một Đỏ dính (fix vòng 1, I2/C1): nếu bản ghi còn một Đỏ chưa xử lý mà
 * lần chạy vừa rồi không còn thấy (danh tính đã sửa), hoặc mang khoá của một lần gọi lại, xác nhận vẫn
 * được ghi nhưng ô câu chuyện vẫn khoá cho tới khi quản lý/admin ghi đè ({@see IntakeSummaryGate}).
 *
 * Xác nhận lưu người và thời điểm (`conflict_acknowledged_by/_at`), và dòng `intake_conflict_acknowledged`
 * mang `confirmed_pairs` (chữ ký + MỨC của từng khớp vừa được chấp nhận, R13c/C1): lần kiểm tra sau
 * không chặn lại một khớp đã xác nhận, nhưng một khớp MỚI thì vẫn đòi xác nhận lại. Dòng đó không
 * mang tên, SĐT hay nội dung nào.
 *
 * Quyền: người nhìn thấy được bản ghi (`IntakeRequestPolicy::update`); người nhập thường ngày (trợ lý
 * đã ghi bản ghi) xác nhận được Vàng — chỉ Đỏ mới đòi quản lý/admin.
 *
 * **Làn kd — người không được thấy các khớp** (`IntakeRequestPolicy::viewConflictMatches`, tức không có
 * `matter.view`: nhân viên kinh doanh) chỉ thấy mức, nên "đã xem các khớp" từ họ là một lời không có thật:
 * khi lần chạy lại ra mức khác Xanh, Action từ chối bằng câu "hãy chuyển lead cho luật sư" — TRƯỚC mọi lời
 * từ chối khác, vì lời từ chối Đỏ (`ConflictBlocked`) nêu mã hồ sơ. Lần chạy lại vẫn được ghi như mọi
 * trường hợp ở đây. Mức Xanh chỉ thiếu định danh (không có khớp nào để xem) thì họ xác nhận được như mọi
 * người nhập: thứ được xác nhận là "kiểm tra chạy trên tên các bên chưa có SĐT/CCCD", không phải hồ sơ nào.
 */
class AcknowledgeIntakeConflict
{
    use HoldsConflictCheckLock;

    /**
     * @param  string|null  $shownFingerprint  `ShownConflictFingerprint::of()` của bảng mà trang
     *                                         đã hiện (rà soát 2026-10-22, làn harden): lần chạy lại ra bảng
     *                                         khác — một khớp mới cùng mức — thì từ chối bằng
     *                                         `ConflictAcknowledgementRequired::changed()`. `null` giữ hợp đồng
     *                                         cũ chỉ so mức (seeder).
     */
    public function handle(User $actor, IntakeRequest $intake, ConflictLevel $acknowledged, ?string $shownFingerprint = null): IntakeRequest
    {
        Gate::forUser($actor)->authorize('update', $intake);

        return $this->underConflictCheckLock(function () use ($actor, $intake, $acknowledged, $shownFingerprint): IntakeRequest {
            // Dạng của bảng đã hiện đọc TRƯỚC lần chạy lại — xem `ShownConflictFingerprint`.
            $heldWhenShown = IntakeRequest::query()->findOrFail($intake->getKey())->isHeldByRepeatCallLock();
            $result = $this->checkAndRecord($actor, $intake);

            if ($result->level !== ConflictLevel::Green && Gate::forUser($actor)->denies('viewConflictMatches', $intake)) {
                throw ValidationException::withMessages(['acknowledged' => [__('leads.errors.acknowledge_needs_lawyer')]]);
            }

            if ($result->isBlocking()) {
                throw ConflictBlocked::make($result);
            }

            if (! $result->requiresAcknowledgement()) {
                throw ValidationException::withMessages(['acknowledged' => [__('intake.errors.acknowledgement_not_needed')]]);
            }

            if ($shownFingerprint !== null
                && ! hash_equals(ShownConflictFingerprint::of(IntakeRequest::query()->findOrFail($intake->getKey()), $result->toArray(), $heldWhenShown), $shownFingerprint)) {
                throw ConflictAcknowledgementRequired::changed($result);
            }

            if ($acknowledged !== $result->level) {
                throw ConflictAcknowledgementRequired::make($result);
            }

            return DB::transaction(function () use ($actor, $intake, $result): IntakeRequest {
                $locked = IntakeRequest::query()->whereKey($intake->getKey())->lockForUpdate()->firstOrFail();

                $locked->fill([
                    'conflict_acknowledged_by' => $actor->getKey(),
                    'conflict_acknowledged_at' => now(),
                ])->blameOn($actor)->save();

                Audit::record('intake_conflict_acknowledged', $locked, [
                    'level' => $result->level->value,
                    'incomplete_parties' => $result->incompleteParties->count(),
                    'confirmed_pairs' => self::confirmedPairs($result),
                ], $actor);

                return $locked;
            });
        });
    }

    /**
     * Chữ ký + mức của MỌI khớp mới (bản CHƯA gộp hiển thị, xem `ConflictCheckResult::$allNewMatches`).
     *
     * @return list<array{pair_key: string, level: string}>
     */
    public static function confirmedPairs(ConflictCheckResult $result): array
    {
        return $result->allNewMatches
            ->map(fn (ConflictMatch $match): array => ['pair_key' => $match->pairKey(), 'level' => $match->level->value])
            ->filter(fn (array $pair): bool => $pair['pair_key'] !== null)
            ->values()
            ->all();
    }
}
