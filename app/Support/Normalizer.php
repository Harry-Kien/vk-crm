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

        return preg_replace('/\s+/', ' ', $ascii) ?: null;
    }

    public static function phone(?string $value): ?string
    {
        $digits = self::digits($value);

        if ($digits === null) {
            return null;
        }

        if (str_starts_with($digits, '84')) {
            // If digits are '84' followed by '0', strip the leading '0' after 84
            if (strlen($digits) > 2 && $digits[2] === '0') {
                return '84'.substr($digits, 3);
            }

            return $digits;
        }

        if (str_starts_with($digits, '0')) {
            return '84'.substr($digits, 1);
        }

        return $digits;
    }

    public static function idNumberHash(?string $value): ?string
    {
        $digits = self::digits($value);

        return $digits === null ? null : hash('sha256', $digits);
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
