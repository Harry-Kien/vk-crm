<?php

namespace App\Support\Billing;

/**
 * Ngân hàng nhận chuyển khoản mà trang "Nhắc thanh toán cho khách" cho chọn (làn nt Task 1), khoá
 * theo MÃ NGÂN HÀNG NAPAS (BIN sáu chữ số, `acquirer ID` trong chuỗi VietQR — {@see VietQr}).
 *
 * Mã này là thứ ứng dụng ngân hàng của khách đọc từ mã QR để biết chuyển vào ngân hàng nào; tên chỉ
 * để người đọc nhận ra. Vì vậy ô "Ngân hàng" là một danh sách chọn chứ không phải một ô gõ tự do:
 * từ một tên gõ tay không suy ra được mã. Danh sách chỉ gồm các ngân hàng trong nước phổ biến có mã
 * NAPAS ổn định; quản trị viên kiểm lại bằng cách quét mã QR xem thử trên chính trang cấu hình bằng
 * ứng dụng ngân hàng của mình trước khi bật công tắc.
 */
final class VietQrBanks
{
    /** @var array<string, string> mã NAPAS => tên hiển thị */
    public const NAMES = [
        '970436' => 'Vietcombank — Ngân hàng TMCP Ngoại thương Việt Nam',
        '970415' => 'VietinBank — Ngân hàng TMCP Công Thương Việt Nam',
        '970418' => 'BIDV — Ngân hàng TMCP Đầu tư và Phát triển Việt Nam',
        '970405' => 'Agribank — Ngân hàng Nông nghiệp và Phát triển Nông thôn Việt Nam',
        '970407' => 'Techcombank — Ngân hàng TMCP Kỹ Thương Việt Nam',
        '970422' => 'MB — Ngân hàng TMCP Quân đội',
        '970416' => 'ACB — Ngân hàng TMCP Á Châu',
        '970432' => 'VPBank — Ngân hàng TMCP Việt Nam Thịnh Vượng',
        '970423' => 'TPBank — Ngân hàng TMCP Tiên Phong',
        '970403' => 'Sacombank — Ngân hàng TMCP Sài Gòn Thương Tín',
        '970437' => 'HDBank — Ngân hàng TMCP Phát triển TP. Hồ Chí Minh',
        '970441' => 'VIB — Ngân hàng TMCP Quốc tế Việt Nam',
        '970443' => 'SHB — Ngân hàng TMCP Sài Gòn – Hà Nội',
        '970431' => 'Eximbank — Ngân hàng TMCP Xuất Nhập khẩu Việt Nam',
        '970426' => 'MSB — Ngân hàng TMCP Hàng Hải Việt Nam',
        '970448' => 'OCB — Ngân hàng TMCP Phương Đông',
        '970440' => 'SeABank — Ngân hàng TMCP Đông Nam Á',
        '970449' => 'LPBank — Ngân hàng TMCP Lộc Phát Việt Nam',
        '970428' => 'Nam A Bank — Ngân hàng TMCP Nam Á',
        '970409' => 'Bac A Bank — Ngân hàng TMCP Bắc Á',
        '970425' => 'ABBANK — Ngân hàng TMCP An Bình',
        '970454' => 'BVBank — Ngân hàng TMCP Bản Việt',
        '970412' => 'PVcomBank — Ngân hàng TMCP Đại Chúng Việt Nam',
        '970429' => 'SCB — Ngân hàng TMCP Sài Gòn',
        '970452' => 'KienlongBank — Ngân hàng TMCP Kiên Long',
        '970419' => 'NCB — Ngân hàng TMCP Quốc Dân',
        '970427' => 'VietABank — Ngân hàng TMCP Việt Á',
        '970400' => 'Saigonbank — Ngân hàng TMCP Sài Gòn Công Thương',
        '970430' => 'PGBank — Ngân hàng TMCP Thịnh vượng và Phát triển',
        '970438' => 'BaoViet Bank — Ngân hàng TMCP Bảo Việt',
        '970433' => 'Vietbank — Ngân hàng TMCP Việt Nam Thương Tín',
        '970406' => 'DongA Bank — Ngân hàng TMCP Đông Á',
        '970408' => 'GPBank — Ngân hàng TMCP Dầu khí Toàn Cầu',
    ];

    /** Tên của một mã, `null` khi mã không thuộc danh sách (giá trị lưu hỏng hay đã bị bỏ khỏi danh sách). */
    public static function name(?string $bin): ?string
    {
        return $bin === null ? null : (self::NAMES[$bin] ?? null);
    }
}
