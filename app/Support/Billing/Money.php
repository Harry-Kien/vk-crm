<?php

namespace App\Support\Billing;

use Illuminate\Validation\ValidationException;

/**
 * Định dạng và đọc số tiền — MỘT chỗ cho cả hệ thống (kế hoạch M9, "Định dạng tiền một chỗ").
 * Tiền lưu bằng số nguyên ĐỒNG (M9 quyết định 5); đồng không có phần lẻ, nên ở đây không có số
 * thực nào.
 *
 * **Dấu chấm là phân cách nghìn, không bao giờ là dấu thập phân.** `parse("1.25")` KHÔNG phải
 * 1,25 đồng cũng không phải 125 đồng: một chuỗi mà dấu chấm không chia đúng nhóm ba chữ số là một
 * chuỗi gõ sai, và bị trả lại cho người gõ bằng `ValidationException` thay vì được đoán nghĩa.
 */
final class Money
{
    /**
     * Trần của MỌI ô tiền — form và Action dùng chung hằng này (ràng buộc toàn cục M9, mang từ
     * M6.5): 999.999.999.999 đồng, dưới một nghìn tỷ. Không hợp đồng dịch vụ pháp lý nào của một
     * văn phòng chạm tới đó, và với trần này tổng của hàng chục nghìn đợt vẫn cách xa
     * `PHP_INT_MAX` (≈ 9,2 × 10^18), nên cộng dồn trong PHP và `SUM()` trong SQL không tràn.
     */
    public const MAX = 999_999_999_999;

    /** `1250000` → `"1.250.000 ₫"`. */
    public static function format(int $dong): string
    {
        return self::formatForInput($dong).' ₫';
    }

    /**
     * `1250000` → `"1.250.000"` — đúng dạng {@see self::parse()} đọc lại được, KHÔNG kèm "₫": giá trị
     * điền sẵn vào một ô nhập tiền (form sửa bản nháp). Điền `format()` vào ô rồi bấm lưu ngay sẽ
     * ra lỗi định dạng, vì `parse()` cố tình không đoán nghĩa ký tự "₫".
     */
    public static function formatForInput(int $dong): string
    {
        return number_format($dong, 0, ',', '.');
    }

    /**
     * `"1.250.000"` → `1250000`; `"1250000"` → `1250000`. Chấp nhận đúng hai hình dạng: chỉ chữ số,
     * hoặc chữ số chia nhóm ba bằng dấu chấm. Khoảng trắng hai đầu được bỏ; mọi thứ khác (dấu phẩy,
     * dấu trừ, khoảng trắng giữa số, nhóm không đủ ba chữ số) là lỗi xác thực trên `$field`.
     *
     * Không nhận số âm và không nhận số vượt {@see self::MAX}. KHÔNG từ chối số 0: "số tiền phải
     * lớn hơn 0" là luật của nơi dùng (một khoản thu, một đợt), không phải của cách viết một con số.
     *
     * @throws ValidationException
     */
    public static function parse(string $input, string $field = 'amount'): int
    {
        $input = trim($input);

        if (preg_match('/^(?:\d+|\d{1,3}(?:\.\d{3})+)$/', $input) !== 1) {
            throw ValidationException::withMessages([$field => [__('billing.validation.money_format')]]);
        }

        $digits = ltrim(str_replace('.', '', $input), '0');

        // Một chuỗi số dài hơn `PHP_INT_MAX` bão hoà thành `PHP_INT_MAX` khi ép `(int)` (PHP 8,
        // chuỗi số nguyên) — vẫn lớn hơn MAX, nên vẫn bị từ chối ở đây; test "refuses a number
        // too long to fit in an integer" ghim hành vi đó thay vì một phép so độ dài thứ hai.
        if ((int) $digits > self::MAX) {
            throw ValidationException::withMessages([
                $field => [__('billing.validation.money_too_large', ['max' => self::format(self::MAX)])],
            ]);
        }

        return (int) $digits;
    }
}
