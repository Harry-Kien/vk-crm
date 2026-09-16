<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Chuẩn hoá dữ liệu để so khớp xung đột lợi ích (SPEC §6.10 bước 1).
 * Không bao giờ lưu số căn cước gốc ở đây; chỉ lưu hash.
 */
final class Normalizer
{
    public static function name(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $ascii = Str::ascii(mb_strtolower(trim($value), 'UTF-8'));

        return trim(preg_replace('/\s+/u', ' ', $ascii)) ?: null;
    }

    /**
     * Đưa mọi cách viết của cùng một số điện thoại Việt Nam về `84xxxxxxxxx` (SPEC §6.10 bước 1).
     *
     * Đây là một trong ba tầng khớp của kiểm tra xung đột lợi ích, và là tầng MẠNH DUY NHẤT còn
     * lại khi một bên không có số căn cước — tình trạng bình thường của bên đối lập. Hai cách
     * viết khác nhau của cùng một số ở đây = một xung đột lợi ích bị bỏ sót, im lặng.
     *
     * **Vì sao phải đoán cả số đã mất số 0 đầu.** Bản xuất Excel/CSV coi `0912345678` là số và
     * cắt mất số 0 đứng đầu; dữ liệu nhập vào văn phòng thật sự tồn tại song song cả hai cách
     * viết. Trước đây `912345678` rơi hết mọi nhánh và giữ nguyên, không bao giờ khớp với
     * `84912345678`.
     *
     * **Quy tắc độ dài, và vì sao chọn 8–9.** Phần thuê bao (sau số 0 đứng đầu) trong kế hoạch
     * đánh số hiện hành là 9 chữ số cho cả di động lẫn cố định có mã vùng; một số số cố định
     * cũ/ngắn còn 8 chữ số. Nên: một dãy TRẦN (không `0`, không mã quốc gia) dài đúng 8 hoặc 9
     * chữ số được coi là số thuê bao bị mất số 0 và được thêm `84`. Dãy dài khác (số nước ngoài,
     * số rác, số nội bộ) giữ nguyên.
     *
     * **Cố ý chọn phía "chuẩn hoá thừa".** Nếu đoán sai, hậu quả xấu nhất là một cảnh báo
     * vàng/đỏ thừa mà luật sư bấm bỏ qua; nếu đoán thiếu, hậu quả là văn phòng nhận việc chống
     * lại chính khách hàng của mình. Hai sai lầm này không cùng hạng, nên nghi ngờ thì chuẩn hoá.
     *
     * **`84` đứng đầu một dãy 9 chữ số là đầu số thuê bao, KHÔNG phải mã quốc gia.** `084` là
     * đầu số VinaPhone có thật: `0843123456` mất số 0 thành `843123456`. Đọc `84` ở đây như mã
     * quốc gia sẽ để lại phần thuê bao 7 chữ số — độ dài không tồn tại trong kế hoạch đánh số —
     * và `843123456` sẽ không bao giờ khớp `0843123456`. Vì vậy nhánh mã quốc gia chỉ nhận khi
     * phần còn lại đủ dài để là một số thuê bao thật (từ 10 chữ số trở lên, tức `84` + ít nhất 8).
     * Nhờ đó phép chuẩn hoá cũng luỹ đẳng: chuẩn hoá lại một giá trị đã chuẩn hoá không đổi.
     */
    public static function phone(?string $value): ?string
    {
        $digits = self::digits($value);

        if ($digits === null) {
            return null;
        }

        // Tiền tố gọi quốc tế: 0084... -> 84...
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        // Mã quốc gia — chỉ khi phần sau `84` còn đủ dài để là một số thuê bao (xem docblock).
        if (str_starts_with($digits, '84') && strlen($digits) >= 10) {
            return '84'.self::withoutTrunkPrefix(substr($digits, 2));
        }

        // Cách viết trong nước: 0912345678 -> 84912345678.
        if (str_starts_with($digits, '0')) {
            return '84'.substr($digits, 1);
        }

        // Số thuê bao trần đã mất số 0 đứng đầu (xem quy tắc độ dài ở docblock).
        if (in_array(strlen($digits), [8, 9], true)) {
            return '84'.$digits;
        }

        return $digits;
    }

    public static function idNumberHash(?string $value): ?string
    {
        $digits = self::digits($value);

        return $digits === null ? null : hash('sha256', $digits);
    }

    /**
     * Bỏ số 0 gọi nội hạt còn sót sau mã quốc gia (`+84 (0)912...`). Số thuê bao Việt Nam không
     * bao giờ bắt đầu bằng 0, nên `84` + `0` luôn là cách viết thừa, không phải dữ liệu thật.
     */
    private static function withoutTrunkPrefix(string $national): string
    {
        return str_starts_with($national, '0') ? substr($national, 1) : $national;
    }

    private static function digits(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return $digits === '' ? null : $digits;
    }
}
