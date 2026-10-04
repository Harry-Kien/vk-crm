<?php

namespace App\Support\Mcp;

use InvalidArgumentException;

/**
 * Làm sạch văn bản do KHÁCH viết trước khi nó rời hệ thống qua MCP, trong trường
 * `untrusted_client_content` (kế hoạch M11, R11) — `client_requests.subject`/`content`, trả lời của
 * khách, tiêu đề tài liệu nhóm A.
 *
 * **Best-effort, không phải một ranh giới phân quyền** [DC:174]. Ranh giới thật là R5: không tool nào
 * gửi, công bố hay gọi URL; tool ghi không nhận địa chỉ người nhận; nháp chỉ tới khách khi một người
 * bấm trong `/admin`. Hàm này chỉ bớt các đường quen thuộc của prompt injection [DC:170-173]:
 *
 *  1. ký tự UTF-8 hỏng được thay (không để `preg_*` trả `null` rồi mất cả đoạn);
 *  2. thực thể HTML được giải mã TRƯỚC (ba lớp), để `&lt;script&gt;`, `&amp;lt;script&amp;gt;` và
 *     `&#8203;` không đi vòng qua các bước sau;
 *  3. xuống dòng về `\n`; ký tự vô hình bị bỏ: nhóm Cf (gồm U+200B, điều khiển hướng chữ U+202A–202E,
 *     U+2066–2069, BOM, soft hyphen), nhóm Co, ký tự điều khiển (trừ xuống dòng; tab thành dấu
 *     cách), CẢ khối tag U+E0000–E007F (kể cả điểm chưa gán), bộ chọn biến thể, và vài chữ "trống" có
 *     tiếng (U+034F, U+115F, U+1160, U+3164, U+FFA0, U+17B4–17B5, U+180B–180D, U+2800); khoảng trắng
 *     lạ (NBSP, U+2000–200A…) thành dấu cách. Dấu thanh tiếng Việt dạng tách rời (U+0300–036F) là
 *     nhóm Mn và KHÔNG bị đụng tới;
 *  4. khối `<script>`/`<style>` bị bỏ cùng nội dung, rồi chú thích HTML; sau bước 5, mọi thẻ còn lại
 *     (kể cả autolink `<https://…>`) bị bỏ; dấu `<` `>` đứng trơ ("số tiền < 5 triệu") được giữ;
 *  5. ảnh Markdown bị thay bằng `[ảnh đã bỏ]`; link Markdown giữ chữ, bỏ URL; định nghĩa link kiểu
 *     tham chiếu bị bỏ cả dòng;
 *  6. mọi URL còn lại bị thay bằng `[liên kết đã bỏ]`: mọi `scheme://`, `//host`, `www.`, và các URI
 *     `data:`, `javascript:`, `vbscript:`, `mailto:`, `blob:`;
 *  7. khoảng trắng dồn lại, dòng trống quá hai dòng gộp lại;
 *  8. cắt theo CHỮ HIỂN THỊ (cụm grapheme `\X`), không theo byte hay code point: một chữ tiếng Việt
 *     dạng NFD không bao giờ bị tách khỏi dấu của nó, và NFC/NFD bị cắt ở cùng một chỗ.
 *
 * Không chuẩn hoá Unicode (NFC/NFKC): văn bản tiếng Việt ra đúng từng byte như khách đã viết, dạng
 * NFC hay NFD (bài học M6.5 Review Focus 5). Cái giá: một URL viết bằng chữ toàn chiều rộng
 * (`ｈｔｔｐｓ://`) không bị nhận ra — thêm một lý do cho câu "best-effort" ở trên.
 */
final class UntrustedText
{
    /** Ký tự vô hình bị bỏ — xem bước 3 ở docblock lớp. */
    private const INVISIBLE = '/[\p{Cf}\p{Co}\x{E0000}-\x{E007F}\x{FE00}-\x{FE0F}\x{E0100}-\x{E01EF}'
        .'\x{034F}\x{115F}\x{1160}\x{3164}\x{FFA0}\x{17B4}\x{17B5}\x{180B}-\x{180D}\x{2800}]/u';

    /**
     * @return array{text: string, truncated: bool}
     */
    public static function from(?string $raw, int $limit = 2000): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('UntrustedText: giới hạn độ dài phải từ 1 trở lên.');
        }

        $text = self::clean((string) $raw);

        return self::truncate($text, $limit);
    }

    private static function clean(string $text): string
    {
        // 1. UTF-8 hỏng → ký tự thay thế, để mọi biểu thức /u phía dưới chạy được.
        $text = mb_scrub($text, 'UTF-8');

        // 2. Giải mã thực thể ba lớp (`&amp;amp;lt;` → `&amp;lt;` → `&lt;` → `<`); giải mã một chuỗi
        // đã sạch thực thể không đổi gì, nên không cần dừng sớm.
        for ($i = 0; $i < 3; $i++) {
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // 3. Xuống dòng về một dạng, rồi bỏ ký tự vô hình và ký tự điều khiển.
        $text = self::replace('/\r\n?|[\x{2028}\x{2029}\x{0085}]/u', "\n", $text);
        $text = self::replace(self::INVISIBLE, '', $text);
        $text = self::replace('/\t/u', ' ', $text);
        $text = self::replace('/(?!\n)\p{Cc}/u', '', $text);
        $text = self::replace('/(?! )\p{Zs}/u', ' ', $text);

        // 4. HTML: khối script/style cùng nội dung, và chú thích. Thẻ còn lại bỏ SAU bước Markdown.
        $text = self::replace('/<(script|style)\b[^>]*>.*?<\/\1\s*>/isu', '', $text);
        $text = self::replace('/<!--.*?-->/su', '', $text);

        // 5. Markdown: ảnh (cả kiểu tham chiếu), định nghĩa link tham chiếu, link (giữ chữ). Mỗi
        // mẫu dừng ở cuối dòng, nên một dấu `](` không đóng không nuốt phần còn lại của đoạn văn.
        $text = self::replace('/!\[[^\]\n]*\]\([^)\n]*\)|!\[[^\]\n]*\]\[[^\]\n]*\]/u', __('mcp.untrusted.image_removed'), $text);
        $text = self::replace('/^[ ]{0,3}\[[^\]\n]+\]:[ ]*\S+.*$/mu', '', $text);
        $text = self::replace('/\[([^\]\n]*)\]\([^)\n]*\)/u', '$1', $text);
        $text = self::replace('/\[([^\]\n]+)\]\[[^\]\n]*\]/u', '$1', $text);

        // Mọi thẻ HTML còn lại, kể cả autolink `<https://…>`. Dấu `<` `>` đứng trơ được giữ.
        $text = self::replace('/<[!?\/]?[a-z][^<>]*>/iu', '', $text);

        // 6. Mọi URL còn lại.
        $text = self::replace(
            '/(?:\b[a-z][a-z0-9+.\-]*:\/\/|(?<![\w:\/])\/\/(?=[^\s\/])|\bwww\.)\S*|\b(?:data|javascript|vbscript|mailto|blob):\S+/iu',
            __('mcp.untrusted.link_removed'),
            $text,
        );

        // 7. Khoảng trắng.
        $text = self::replace('/ {2,}/u', ' ', $text);
        $text = self::replace('/ *\n */u', "\n", $text);
        $text = self::replace('/\n{3,}/u', "\n\n", $text);

        return trim($text);
    }

    /**
     * @return array{text: string, truncated: bool}
     */
    private static function truncate(string $text, int $limit): array
    {
        // 8. Đếm theo cụm grapheme: một chữ hiển thị là một đơn vị, dù NFC hay NFD.
        preg_match_all('/\X/u', $text, $matches);
        $graphemes = $matches[0];

        if (count($graphemes) <= $limit) {
            return ['text' => $text, 'truncated' => false];
        }

        return ['text' => rtrim(implode('', array_slice($graphemes, 0, $limit))), 'truncated' => true];
    }

    private static function replace(string $pattern, string $replacement, string $subject): string
    {
        // Đầu vào đã qua mb_scrub nên /u không thể thất bại vì UTF-8 hỏng; nếu vẫn thất bại (giới
        // hạn backtrack của PCRE), trả chuỗi RỖNG chứ không trả nguyên văn chưa lọc.
        return preg_replace($pattern, $replacement, $subject) ?? '';
    }
}
