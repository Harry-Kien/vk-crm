<?php

namespace App\Actions\Intake;

use App\Enums\ConflictLevel;
use App\Enums\IntakeSummaryBlocker;
use App\Models\IntakeRequest;

/**
 * NƠI DUY NHẤT quyết định ô CÂU CHUYỆN của một lần tiếp nhận đóng hay mở (M10 R1 + R7a). Đọc các cột
 * đã lưu, không chạy lại kiểm tra: `UpdateIntakeSummary` (cổng thật) và màn hình (Task 3, để nói
 * người nhập phải làm gì tiếp) cùng hỏi hàm này, nên hai nơi không thể lệch nhau.
 *
 * Ô mở khi KHÔNG còn điều nào trong {@see IntakeSummaryBlocker}:
 *  1. **Thông báo (R7a):** đã ghi nhận người liên hệ nghe thông báo và đồng ý
 *     (`privacy_notice_acknowledged_at`). Áp cho MỌI kết quả, kể cả Xanh.
 *  2. **Đã kiểm tra, và kiểm tra còn khớp danh tính:** có `conflict_checked_at`, và dấu vân tay danh
 *     tính lưu kèm `conflict_result` bằng dấu vân tay HIỆN TẠI ({@see IntakeRequest::identityFingerprint()}).
 *     Ai sửa danh tính mà chưa chạy lại kiểm tra thì kết quả cũ (kể cả Xanh) không còn là bằng chứng.
 *  3. **Đỏ chưa xử lý ({@see IntakeRequest::hasUnresolvedRed()}):** khoá cho tới khi quản lý/admin
 *     ghi đè kèm lý do. Đỏ DÍNH (fix vòng 1, I2): nó không theo `conflict_level` của lần chạy gần
 *     nhất, nên sửa hay gỡ bên đối lập rồi chạy lại ra Xanh — kể cả quản lý tự chạy — KHÔNG mở ô; một
 *     lần gọi lại của người có lần gọi trước còn Đỏ hay đã bị từ chối vì xung đột cũng bị khoá như thế
 *     (C1, `CheckIntakeConflict`).
 *  4. **Vàng, hoặc "thiếu định danh"** (`incomplete_parties` không rỗng): phải có xác nhận
 *     (`conflict_acknowledged_by`) — cùng cổng `OpenMatter` dùng. Một ghi đè còn hiệu lực
 *     ({@see IntakeRequest::hasConflictOverride()}: có người VÀ có lý do) che luôn bước này, như
 *     `OpenMatter`.
 *  5. Xanh đủ định danh: mở.
 *
 * Bản ghi đã ẩn danh hoặc đã gộp không phải chuyện của cổng này: `UpdateIntakeSummary` từ chối riêng.
 */
final class IntakeSummaryGate
{
    /** @return list<IntakeSummaryBlocker> */
    public static function blockers(IntakeRequest $intake): array
    {
        $blockers = [];

        if ($intake->privacy_notice_acknowledged_at === null) {
            $blockers[] = IntakeSummaryBlocker::PrivacyNotice;
        }

        $result = is_array($intake->conflict_result) ? $intake->conflict_result : [];

        if ($intake->conflict_checked_at === null
            || $intake->conflict_level === null
            || ($result['fingerprint'] ?? null) !== $intake->identityFingerprint()) {
            $blockers[] = IntakeSummaryBlocker::ConflictUnchecked;

            return $blockers;
        }

        if ($intake->hasUnresolvedRed()) {
            $blockers[] = IntakeSummaryBlocker::ConflictRed;

            return $blockers;
        }

        $needsAcknowledgement = $intake->conflict_level === ConflictLevel::Yellow
            || ($result['incomplete_parties'] ?? []) !== [];

        // Một ghi đè Đỏ còn hiệu lực cũng che cổng xác nhận (như OpenMatter): người ghi đè đã xem hết,
        // và một lần chạy lại không có gì mới không được làm cổng đóng lại sau khi vừa mở. "Còn hiệu
        // lực" = CÙNG định nghĩa với bước Đỏ ở trên (người VÀ lý do, `hasConflictOverride()`).
        if ($needsAcknowledgement && $intake->conflict_acknowledged_by === null && ! $intake->hasConflictOverride()) {
            $blockers[] = IntakeSummaryBlocker::ConflictAcknowledgement;
        }

        return $blockers;
    }

    public static function isOpen(IntakeRequest $intake): bool
    {
        return self::blockers($intake) === [];
    }
}
