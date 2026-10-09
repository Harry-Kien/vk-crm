<?php

namespace App\Support\Intake;

use App\Models\IntakeRequest;

/**
 * Câu thông báo bảo vệ dữ liệu đọc cho người liên hệ (M10, `lang/vi/intake.php` khoá
 * `privacy_notice`) và phiên bản ghi kèm mỗi lần ghi nhận (`intake_requests.privacy_notice_version`,
 * dòng nhật ký `intake_privacy_notice_recorded`) — MỘT chỗ, cho cả bốn nơi đọc: form tạo, khối thông
 * báo và hộp "Ghi nhận" của trang sửa, `RecordPrivacyNotice`.
 *
 * Lượt quét §10 trước bản 1.0 (việc mã của mục "chờ luật sư" ở Ghi chú M10): câu cũ viết cứng
 * "24 tháng" trong khi hạn lưu thật là {@see IntakeRequest::retentionMonths()} (`PROSPECT_RETENTION_MONTHS`).
 * Nay câu đọc chính con số đó (`:months`). Luật "đổi chữ thì đổi `version`" giữ bằng cách cho phiên bản
 * mang con số khi nó KHÁC {@see self::BASE_MONTHS} — số mà bản chữ gốc được viết với: `2026-09-nhap`
 * (24 tháng, đúng từng chữ câu cũ, nên mọi lần ghi nhận đã có vẫn hợp lệ) và `2026-09-nhap-36t` (36
 * tháng). Đổi biến thì nút "Ghi nhận thông báo" hiện lại trên bản ghi chưa đóng, vì người đó chưa nghe
 * câu mới. Cột dài 20 ký tự: phiên bản gốc 12 ký tự cộng tối đa "-1200t" (số tháng có trần
 * {@see IntakeRequest::MAX_RETENTION_MONTHS}) là 18, vẫn dưới trần.
 *
 * Câu chữ và con số vẫn là bản nháp CHỜ LUẬT SƯ (Ghi chú M10, "Cần chủ văn phòng quyết" của lượt quét):
 * luật sư sửa `text` thì đổi `version` gốc như cũ.
 */
final class PrivacyNotice
{
    /** Số tháng mà bản chữ gốc (`intake.privacy_notice.version`) được viết với. */
    public const BASE_MONTHS = 24;

    public static function text(): string
    {
        return (string) __('intake.privacy_notice.text', ['months' => IntakeRequest::retentionMonths()]);
    }

    public static function version(): string
    {
        $base = (string) __('intake.privacy_notice.version');
        $months = IntakeRequest::retentionMonths();

        return $months === self::BASE_MONTHS ? $base : "{$base}-{$months}t";
    }
}
