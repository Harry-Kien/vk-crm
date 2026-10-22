<?php

namespace App\Support\Intake;

use App\Models\IntakeRequest;
use App\Support\ConflictCheckResult;
use App\Support\Normalizer;

/**
 * Dấu vân tay của bảng kết quả kiểm tra mà trang một bản ghi tiếp nhận ĐÃ HIỆN (rà soát toàn hệ thống
 * 2026-10-22, làn harden) — một định nghĩa cho trang (`EditIntakeRequest`, lúc vẽ khối kiểm tra) và hai Action
 * so với nó (`AcknowledgeIntakeConflict`, `ResolveIntakeRedConflict`, trên kết quả chạy lại).
 *
 * **Bản ghi thường:** đúng {@see ConflictCheckResult::displayFingerprintOf()} — bảng lúc lưu khác bảng đã
 * hiện là có thứ người bấm chưa thấy.
 *
 * **Bản ghi đang bị giữ như một cuộc gọi lại** ({@see IntakeRequest::isHeldByRepeatCallLock()}): bảng đã
 * lưu được tính TRƯỚC khi lần gọi kia của cùng người khoá, nên nó không có — và trang nói rõ là không có —
 * Đỏ đang giữ bản này ("Đỏ chờ ngoài lần kiểm tra gần nhất"). Lần chạy lại lúc xử lý mang sang bên đối lập
 * của lần gọi kia và chính lần gọi kia (mã `TN-…`): những dòng đó là NỘI DUNG của khoá người bấm đã được
 * báo, không phải khớp mới. Nên dấu gồm: các dòng của CHÍNH bản ghi (bên ở phía mình là người liên hệ, tổ
 * chức hay một bên đối lập của nó), trừ dòng chỉ về một lần gọi đang khoá; tên các bên thiếu định danh; và
 * dấu các khoá đang giữ ({@see IntakeRequest::repeatCallLocks()}). Một khớp mới trên bên của chính bản ghi,
 * hay một khoá mới (lần gọi khác bắt đầu khoá, một đợt Đỏ mới), vẫn làm dấu đổi. Bản ghi chuyển giữa "bị
 * giữ" và "không" giữa hai lúc cũng làm dấu đổi (hai dạng dấu khác tiền tố).
 */
final class ShownConflictFingerprint
{
    /**
     * @param  array<string, mixed>  $result  Hình dạng `ConflictCheckResult::toArray()` (kể cả bản đã lưu).
     * @param  bool  $held  {@see IntakeRequest::isHeldByRepeatCallLock()} của bản ghi lúc bảng được hiện —
     *                      trang hỏi lúc vẽ, Action hỏi trên bản đọc lại TRƯỚC lần chạy lại.
     */
    public static function of(IntakeRequest $intake, array $result, bool $held): string
    {
        if (! $held) {
            return ConflictCheckResult::displayFingerprintOf($result);
        }

        $role = $intake->conflictContactRole();
        $own = collect([$intake->contact_name, $intake->organization_name, ...$intake->parties()->pluck('name')->all()])
            ->map(fn (?string $name): ?string => Normalizer::name($name))
            ->filter()
            ->all();
        $lockingCodes = $intake->sameCallerIntakes($role)
            ->filter(fn (IntakeRequest $other): bool => $other->locksRepeatCalls())
            ->pluck('code')
            ->all();

        $ownMatches = array_values(array_filter(
            $result['matches'] ?? [],
            fn (mixed $match): bool => is_array($match)
                && in_array(Normalizer::name($match['our_party_name'] ?? null), $own, true)
                && ! in_array($match['matter_code'] ?? null, $lockingCodes, true),
        ));

        return hash('sha256', 'held|'.ConflictCheckResult::displayFingerprintOf([
            'level' => null,
            'matches' => $ownMatches,
            'incomplete_parties' => array_values(array_filter(
                $result['incomplete_parties'] ?? [],
                fn (mixed $name): bool => in_array(Normalizer::name(is_string($name) ? $name : null), $own, true),
            )),
        ]).'|'.implode(',', $intake->repeatCallLocks($role)));
    }
}
