<?php

namespace App\Support\Billing;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Mã QR chuyển khoản theo chuẩn VietQR của NAPAS (làn nt Task 1) — chuỗi EMVCo "Merchant Presented
 * Mode" mà mọi ứng dụng ngân hàng trong nước đọc được, và ảnh QR của chuỗi đó.
 *
 * # Không bên thứ ba nào
 *
 * Chuỗi dựng ở đây, ảnh vẽ tại chỗ bằng `chillerlan/php-qrcode` — gói ĐÃ có trong `composer.lock`
 * (Filament đòi nó cho xác thực hai lớp), không thêm gói composer nào. Không gọi dịch vụ vẽ QR bên
 * ngoài: số tài khoản, số tiền và mã hồ sơ của khách không rời máy chủ.
 *
 * # Cấu trúc chuỗi (mỗi trường: hai chữ số mã, hai chữ số độ dài, giá trị)
 *
 *  - `00` = `01` (phiên bản định dạng);
 *  - `01` = `12` khi có số tiền (mã dùng cho đúng một lần chuyển), `11` khi không (mã tĩnh — chỉ dùng
 *    cho bản xem thử trên trang cấu hình);
 *  - `38` = thông tin tài khoản nhận: `00` = `A000000727` (định danh VietQR của NAPAS), `01` = {`00`
 *    mã ngân hàng NAPAS sáu chữ số, `01` số tài khoản}, `02` = `QRIBFTTA` (chuyển nhanh tới TÀI KHOẢN,
 *    không phải tới thẻ);
 *  - `53` = `704` (VND), `54` = số tiền (đồng, chỉ chữ số — đồng không có phần lẻ), `58` = `VN`;
 *  - `62` = {`08` nội dung chuyển khoản} khi có nội dung;
 *  - `63` = CRC-16/CCITT-FALSE (đa thức 0x1021, khởi tạo 0xFFFF) của TOÀN chuỗi tới hết `6304`, bốn
 *    chữ số hex viết hoa.
 *
 * Đầu vào sai chuẩn ném `InvalidArgumentException` (lỗi lập trình hay giá trị lưu hỏng — Action cấu
 * hình đã kiểm cùng các luật này trước khi lưu): mã ngân hàng đúng sáu chữ số; số tài khoản 1…19 chữ
 * và số không dấu; số tiền dương tới {@see Money::MAX}; nội dung tối đa {@see self::MAX_NOTE_LENGTH} ký
 * tự trong tập {@see self::NOTE_ALPHABET} (EMVCo giới hạn mỗi trường con của `62` ở 25 ký tự, và ngân
 * hàng bỏ dấu tiếng Việt trong nội dung chuyển khoản — nội dung khách thấy trên cổng phải đúng nội
 * dung ngân hàng ghi lại để kế toán đối chiếu).
 */
final class VietQr
{
    /** Độ dài tối đa của nội dung chuyển khoản trong mã (trường con `08` của `62`). */
    public const MAX_NOTE_LENGTH = 25;

    /** Độ dài tối đa của số tài khoản (trường con `01` của tài khoản nhận). */
    public const MAX_ACCOUNT_LENGTH = 19;

    /** Biểu thức của nội dung chuyển khoản hợp lệ: chữ hoa không dấu, chữ số, khoảng trắng, gạch ngang. */
    public const NOTE_ALPHABET = '/^[A-Z0-9 \-]+$/';

    /** Định danh VietQR của NAPAS. */
    private const GUID = 'A000000727';

    /** Dịch vụ chuyển nhanh tới tài khoản. */
    private const SERVICE_TO_ACCOUNT = 'QRIBFTTA';

    public static function payload(string $bankBin, string $accountNumber, ?int $amount, ?string $note): string
    {
        if (preg_match('/^\d{6}$/', $bankBin) !== 1) {
            throw new InvalidArgumentException('Mã ngân hàng NAPAS phải đúng sáu chữ số.');
        }

        if (preg_match('/^[A-Za-z0-9]{1,'.self::MAX_ACCOUNT_LENGTH.'}$/', $accountNumber) !== 1) {
            throw new InvalidArgumentException('Số tài khoản phải gồm 1–'.self::MAX_ACCOUNT_LENGTH.' chữ và số không dấu.');
        }

        if ($amount !== null && ($amount < 1 || $amount > Money::MAX)) {
            throw new InvalidArgumentException('Số tiền trong mã QR phải lớn hơn 0 và không vượt trần.');
        }

        if ($note !== null && (mb_strlen($note) > self::MAX_NOTE_LENGTH || preg_match(self::NOTE_ALPHABET, $note) !== 1)) {
            throw new InvalidArgumentException('Nội dung chuyển khoản trong mã QR sai chuẩn.');
        }

        $beneficiary = self::field('00', $bankBin).self::field('01', $accountNumber);

        $payload = self::field('00', '01')
            .self::field('01', $amount === null ? '11' : '12')
            .self::field('38', self::field('00', self::GUID).self::field('01', $beneficiary).self::field('02', self::SERVICE_TO_ACCOUNT))
            .self::field('53', '704')
            .($amount === null ? '' : self::field('54', (string) $amount))
            .self::field('58', 'VN')
            .($note === null ? '' : self::field('62', self::field('08', $note)))
            .'6304';

        return $payload.self::crc16($payload);
    }

    /**
     * Nội dung chuyển khoản gợi ý cho một đợt: mã hồ sơ + "DOT" + số thứ tự của đợt, chữ hoa không dấu
     * (`VK-2026-DS-0012 DOT 2`). Không dùng TÊN đợt: tên đợt thường dài ("Thanh toán đợt 2 khi nộp hồ
     * sơ") và bị cắt cụt ở 25 ký tự, còn số thứ tự thì kế toán đối chiếu được với lịch thu của vụ. Ký tự
     * ngoài {@see self::NOTE_ALPHABET} bị bỏ; mã hồ sơ quá dài thì phần MÃ bị cắt bớt, phần "DOT n" giữ
     * nguyên.
     */
    public static function transferNote(string $matterCode, int $sequence): string
    {
        $suffix = ' DOT '.$sequence;

        $code = Str::upper(Str::ascii($matterCode));
        $code = (string) preg_replace('/[^A-Z0-9\-]+/', '', $code);
        $code = substr($code, 0, max(0, self::MAX_NOTE_LENGTH - strlen($suffix)));

        return trim($code.$suffix);
    }

    /** Ảnh QR SVG của chuỗi, dạng `data:` URI (CSP `img-src` của panel cho phép `data:`). */
    public static function svgDataUri(string $payload): string
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG,
            'outputBase64' => true,
            'eccLevel' => EccLevel::M,
            'addQuietzone' => true,
        ]);

        return (string) (new QRCode($options))->render($payload);
    }

    /** CRC-16/CCITT-FALSE, bốn chữ số hex viết hoa. */
    public static function crc16(string $data): string
    {
        $crc = 0xFFFF;

        for ($i = 0, $length = strlen($data); $i < $length; $i++) {
            $crc ^= ord($data[$i]) << 8;

            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) !== 0 ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }

        return sprintf('%04X', $crc);
    }

    private static function field(string $id, string $value): string
    {
        return $id.str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT).$value;
    }
}
