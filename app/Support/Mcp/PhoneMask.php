<?php

namespace App\Support\Mcp;

/**
 * Số điện thoại chỉ ra khỏi hệ thống qua MCP ở dạng che: `***` + ba chữ số cuối (kế hoạch M11, R4,
 * [DC:108]) — `'(+84) 912 345 678'` → `***678`. Ba chữ số cuối giống nhau ở mọi cách viết của cùng
 * một số (chuẩn hoá của `App\Support\Normalizer::phone()` chỉ đổi phần ĐẦU), nên chỉ cần lấy chữ số.
 *
 * Giá trị có dưới sáu chữ số không phải một số điện thoại; ba chữ số cuối của nó là phần lớn (hoặc
 * toàn bộ) giá trị, nên ra `***` không kèm chữ số nào.
 */
final class PhoneMask
{
    /** Dưới ngưỡng này, ba chữ số cuối là phần lớn giá trị: không lộ chữ số nào. */
    private const MIN_DIGITS_TO_REVEAL = 6;

    public static function mask(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        if (strlen($digits) < self::MIN_DIGITS_TO_REVEAL) {
            return '***';
        }

        return '***'.substr($digits, -3);
    }
}
