<?php

namespace Tests\Support;

/**
 * Trích chữ từ một tệp PDF do dompdf sinh — bộ trích NHỎ phía test (M7 Task 4, R3), viết tay vì
 * kế hoạch chỉ cho phép MỘT gói mới (`barryvdh/laravel-dompdf`), kể cả `require-dev`.
 *
 * Nó đọc đúng thứ nằm trong TỆP, không đọc thứ mã vừa đưa vào dompdf:
 *  1. giải nén mọi luồng `FlateDecode`;
 *  2. dựng bản đồ "tên font trong trang → CMap ToUnicode" từ từ điển `/Font` của trang và từ đối
 *     tượng font (`/ToUnicode N 0 R`);
 *  3. đi qua luồng nội dung của trang, đọc `Tf` (chọn font) và các chuỗi trong `Tj`/`TJ`, giải mã
 *     từng mã glyph 2 byte qua CMap của font đang chọn.
 *
 * dompdf nhúng font TrueType kiểu Identity-H với CMap `bfrange <0000> <FFFF> <0000>` (mã glyph =
 * mã Unicode), nhưng bộ trích đọc CMap tổng quát (`bfchar` và `bfrange`), nên nếu dompdf đổi cách
 * nhúng thì test vẫn đo đúng. Font KHÔNG nhúng (Helvetica mặc định) không có `ToUnicode` — chữ có
 * dấu khi đó mất khỏi tệp và bộ trích trả về chuỗi thiếu dấu, đúng thứ test cần bắt.
 */
final class PdfText
{
    /**
     * @return string văn bản, mỗi lần `Tj`/`TJ` một dòng.
     */
    public static function extract(string $pdf): string
    {
        $objects = self::objects($pdf);

        $cmaps = [];
        $fontCmap = [];

        foreach ($objects as $number => $object) {
            if (preg_match('#/Type\s*/Font\b#', $object['dict']) === 1
                && preg_match('#/ToUnicode\s+(\d+)\s+0\s+R#', $object['dict'], $match) === 1
            ) {
                $stream = $objects[(int) $match[1]]['stream'] ?? null;

                if ($stream !== null) {
                    $fontCmap[$number] = $cmaps[(int) $match[1]] ??= self::parseCMap($stream);
                }
            }
        }

        // Tên font dùng trong luồng nội dung (`/F1`) → CMap. dompdf khai từ điển `/Font << /F1 8 0 R … >>`.
        $named = [];

        foreach ($objects as $object) {
            if (preg_match('#/Font\s*<<(.*?)>>#s', $object['dict'], $fonts) === 1) {
                preg_match_all('#/(\w+)\s+(\d+)\s+0\s+R#', $fonts[1], $pairs, PREG_SET_ORDER);

                foreach ($pairs as $pair) {
                    if (isset($fontCmap[(int) $pair[2]])) {
                        $named[$pair[1]] = $fontCmap[(int) $pair[2]];
                    }
                }
            }
        }

        $lines = [];

        foreach ($objects as $object) {
            $stream = $object['stream'];

            if ($stream === null || ! str_contains($stream, 'BT')) {
                continue;
            }

            $current = [];

            preg_match_all(
                '#/(\w+)\s+[\d.]+\s+Tf|\[((?:[^\]\\\\]|\\\\.)*)\]\s*TJ|\(((?:[^)\\\\]|\\\\.)*)\)\s*Tj#s',
                $stream,
                $tokens,
                PREG_SET_ORDER,
            );

            foreach ($tokens as $token) {
                if (($token[1] ?? '') !== '') {
                    $current = $named[$token[1]] ?? [];

                    continue;
                }

                $text = '';

                if (isset($token[2]) && $token[2] !== '') {
                    preg_match_all('#\(((?:[^)\\\\]|\\\\.)*)\)#s', $token[2], $strings);

                    foreach ($strings[1] as $string) {
                        $text .= self::decode(self::unescape($string), $current);
                    }
                } elseif (isset($token[3])) {
                    $text = self::decode(self::unescape($token[3]), $current);
                }

                if ($text !== '') {
                    $lines[] = $text;
                }
            }
        }

        return implode("\n", $lines);
    }

    /** Bỏ mọi khoảng trắng — để so một cụm có dấu bất kể dompdf ngắt dòng/chia từ ở đâu. */
    public static function squash(string $text): string
    {
        return (string) preg_replace('/\s+/u', '', $text);
    }

    /**
     * @return array<int, array{dict: string, stream: ?string}>
     */
    private static function objects(string $pdf): array
    {
        preg_match_all('#(\d+)\s+0\s+obj(.*?)endobj#s', $pdf, $matches, PREG_SET_ORDER);

        $objects = [];

        foreach ($matches as $match) {
            $body = $match[2];
            $position = strpos($body, 'stream');

            if ($position === false) {
                $objects[(int) $match[1]] = ['dict' => $body, 'stream' => null];

                continue;
            }

            $dict = substr($body, 0, $position);
            $data = substr($body, $position + 6);
            $data = preg_replace('/^\r?\n/', '', $data);
            $data = (string) preg_replace('/\r?\nendstream\s*$/', '', (string) $data);

            if (str_contains($dict, 'FlateDecode')) {
                $decoded = @gzuncompress($data);
                $data = $decoded === false ? '' : $decoded;
            }

            $objects[(int) $match[1]] = ['dict' => $dict, 'stream' => $data];
        }

        return $objects;
    }

    /**
     * @return array{map: array<int, string>, ranges: list<array{int, int, int}>}
     */
    private static function parseCMap(string $cmap): array
    {
        $map = [];
        $ranges = [];

        if (preg_match_all('#beginbfchar(.*?)endbfchar#s', $cmap, $blocks) > 0) {
            foreach ($blocks[1] as $block) {
                preg_match_all('#<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>#', $block, $pairs, PREG_SET_ORDER);

                foreach ($pairs as $pair) {
                    $map[hexdec($pair[1])] = self::utf16Hex($pair[2]);
                }
            }
        }

        if (preg_match_all('#beginbfrange(.*?)endbfrange#s', $cmap, $blocks) > 0) {
            foreach ($blocks[1] as $block) {
                preg_match_all('#<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>#', $block, $triples, PREG_SET_ORDER);

                foreach ($triples as $triple) {
                    $ranges[] = [hexdec($triple[1]), hexdec($triple[2]), hexdec($triple[3])];
                }
            }
        }

        return ['map' => $map, 'ranges' => $ranges];
    }

    private static function utf16Hex(string $hex): string
    {
        return (string) mb_convert_encoding((string) hex2bin($hex), 'UTF-8', 'UTF-16BE');
    }

    /**
     * @param  array{map: array<int, string>, ranges: list<array{int, int, int}>}|array{}  $cmap
     */
    private static function decode(string $bytes, array $cmap): string
    {
        if ($cmap === []) {
            // Không có CMap (font không nhúng): trả byte thô — chữ có dấu sẽ KHÔNG khớp.
            return $bytes;
        }

        $out = '';

        for ($i = 0; $i + 1 < strlen($bytes); $i += 2) {
            $code = (ord($bytes[$i]) << 8) | ord($bytes[$i + 1]);

            if (isset($cmap['map'][$code])) {
                $out .= $cmap['map'][$code];

                continue;
            }

            foreach ($cmap['ranges'] as [$low, $high, $target]) {
                if ($code >= $low && $code <= $high) {
                    $out .= mb_convert_encoding(pack('n', $target + ($code - $low)), 'UTF-8', 'UTF-16BE');

                    continue 2;
                }
            }
        }

        return $out;
    }

    private static function unescape(string $string): string
    {
        return (string) preg_replace_callback(
            '#\\\\(n|r|t|b|f|\(|\)|\\\\|[0-7]{1,3})#',
            static fn (array $m): string => match ($m[1]) {
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                'b' => "\x08",
                'f' => "\f",
                '(' => '(',
                ')' => ')',
                '\\' => '\\',
                default => chr(octdec($m[1]) & 0xFF),
            },
            $string,
        );
    }
}
