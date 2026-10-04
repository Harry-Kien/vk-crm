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
 *  4. HTML và Markdown, LẶP cho tới khi một lượt không đổi gì (bỏ một thứ có thể ghép lại thứ khác:
 *     `<im<b>g …>` thành `<img …>`, `![x]<b>(…)` thành `![x](…)`). Mỗi lượt, theo thứ tự: khối
 *     `<script>`/`<style>` cùng nội dung; chú thích HTML; ảnh Markdown (kiểu inline và kiểu tham chiếu)
 *     thành `[ảnh đã bỏ]`; định nghĩa link tham chiếu bỏ cả dòng; link Markdown giữ chữ, bỏ URL; mọi
 *     thẻ HTML còn lại (kể cả autolink `<https://…>`). Chữ của ảnh và link được qua nhiều dòng, chứa
 *     ngoặc vuông lồng nhau cân bằng và `\]`; phần `(…)` dừng ở cuối dòng, nên một `](` không đóng
 *     không nuốt phần còn lại của đoạn văn. Dấu `<` `>` đứng trơ ("số tiền < 5 triệu") được giữ.
 *     Lượt thứ mười mà vẫn còn đổi thì kết quả là chuỗi RỖNG, không phải bản lọc dở;
 *  5. mọi URL còn lại bị thay bằng `[liên kết đã bỏ]`: `scheme:` theo sau là `/` hoặc `\` (trình
 *     duyệt đọc `https:\host` và `https:/host` như `https://host`; scheme một chữ cái chỉ khi có hai
 *     gạch, để đường dẫn Windows `C:\…` còn nguyên); hai gạch trở lên ở đầu, xuôi hay ngược (`//host`,
 *     `\\host`, `/\host`, `///host` — URL tương đối theo giao thức); `www.`; các URI `data:`,
 *     `javascript:`, `vbscript:`, `mailto:`, `blob:`; và `http:`, `https:`, `ws:`, `wss:`, `ftp:` không
 *     có gạch nào (trên trang https, `http:evil.example` là `http://evil.example`);
 *  6. chặn cuối: mọi `](`, `][`, `]:` còn sót được chèn một dấu cách (`[Ghi chú]: …` của khách cũng
 *     thành `[Ghi chú] : …`). CommonMark đòi `(`, `[` hay `:` đứng NGAY sau `]` thì mới có ảnh hay link
 *     inline, ảnh hay link tham chiếu đầy đủ (kể cả `[x][]`), định nghĩa link; nên sau bước này không
 *     còn cú pháp nào trong số đó, kể cả các dạng mẫu ở bước 4 bỏ sót (chữ alt có code span chứa `]`,
 *     định nghĩa nằm trong trích dẫn `>` hay mục danh sách), và dạng rút gọn `![x]`, `[x]` không còn
 *     định nghĩa nào để trỏ tới. Bước 7 chỉ dồn nhiều dấu cách thành một và bỏ dấu cách sát xuống dòng,
 *     bước 8 chỉ cắt đuôi, nên không ghép lại được các cặp này;
 *  7. khoảng trắng dồn lại, dòng trống quá hai dòng gộp lại;
 *  8. cắt theo CHỮ HIỂN THỊ (cụm grapheme `\X`), không theo byte hay code point: một chữ tiếng Việt
 *     dạng NFD không bao giờ bị tách khỏi dấu của nó, và NFC/NFD bị cắt ở cùng một chỗ.
 *
 * Không chuẩn hoá Unicode (NFC/NFKC): văn bản tiếng Việt ra đúng từng byte như khách đã viết, dạng
 * NFC hay NFD (bài học M6.5 Review Focus 5). Cái giá: một URL viết bằng chữ toàn chiều rộng
 * (`ｈｔｔｐｓ://`) không bị nhận ra — thêm một lý do cho câu "best-effort" ở trên. Cũng không bị bỏ: tên
 * miền trần không scheme (`evil.example/x`) và địa chỉ email trần — GFM biến email trần thành link
 * `mailto:`, phải bấm mới đi, không tự tải như ảnh.
 */
final class UntrustedText
{
    /** Ký tự vô hình bị bỏ — xem bước 3 ở docblock lớp. */
    private const INVISIBLE = '/[\p{Cf}\p{Co}\x{E0000}-\x{E007F}\x{FE00}-\x{FE0F}\x{E0100}-\x{E01EF}'
        .'\x{034F}\x{115F}\x{1160}\x{3164}\x{FFA0}\x{17B4}\x{17B5}\x{180B}-\x{180D}\x{2800}]/u';

    /**
     * Bước 4 chạy tối đa chừng này lượt. Văn bản thật cần một lượt có đổi và một lượt thấy không còn gì
     * để bỏ; mỗi tầng thẻ lồng kiểu `<<b>b>` cần thêm một lượt.
     */
    private const MAX_MARKUP_PASSES = 10;

    /**
     * Phần trong của một nhãn Markdown `[…]` (chữ của ảnh hay link) ở bước 4: qua được nhiều dòng, chứa
     * được ngoặc vuông lồng nhau cân bằng (gọi lại nhóm `label`) và ký tự thoát (`\]`).
     */
    private const LABEL_BODY = '(?:[^\[\]\\\\]++|\\\\[\s\S]|(?&label))';

    /**
     * Khối DEFINE của nhóm `label`, ghép ở CUỐI mỗi mẫu dùng nó: nhóm có tên cũng là nhóm bắt có số,
     * đặt cuối thì `$1` của mẫu link vẫn là chữ của link.
     */
    private const LABEL_DEFINE = '(?(DEFINE)(?<label>\['.self::LABEL_BODY.'*+\]))';

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

        // 4. HTML và Markdown, lặp tới khi một lượt không đổi gì.
        $text = self::stripMarkup($text);

        // 5. Mọi URL còn lại, kể cả dạng gạch ngược mà trình duyệt đọc như `//`.
        $text = self::replace(
            '/(?:\b(?:[a-z]:[\\\\\/]{2}|[a-z][a-z0-9+.\-]+:[\\\\\/])|(?<![\w:\/\\\\])[\\\\\/]{2,}(?=[^\s\/\\\\])|\bwww\.)\S*'
                .'|\b(?:data|javascript|vbscript|mailto|blob|https?|wss?|ftp):\S+/iu',
            __('mcp.untrusted.link_removed'),
            $text,
        );

        // 6. Chặn cuối: không còn `](`, `][`, `]:` nào để CommonMark dựng ảnh, link hay định nghĩa link.
        $text = self::replace('/\](?=[(\[:])/u', '] ', $text);

        // 7. Khoảng trắng.
        $text = self::replace('/ {2,}/u', ' ', $text);
        $text = self::replace('/ *\n */u', "\n", $text);
        $text = self::replace('/\n{3,}/u', "\n\n", $text);

        return trim($text);
    }

    /**
     * Bước 4: chạy {@see stripMarkupOnce()} cho tới khi một lượt không đổi gì. Lượt thứ
     * {@see MAX_MARKUP_PASSES} mà vẫn còn đổi thì trả chuỗi RỖNG — cùng hướng với biểu thức chính quy
     * thất bại ở {@see replace()}: không bao giờ trả một bản lọc dở.
     */
    private static function stripMarkup(string $text): string
    {
        for ($pass = 1; $pass <= self::MAX_MARKUP_PASSES; $pass++) {
            $stripped = self::stripMarkupOnce($text);

            if ($stripped === $text) {
                return $text;
            }

            $text = $stripped;
        }

        return '';
    }

    /**
     * Một lượt của bước 4, theo thứ tự ở docblock lớp. Phần `(…)` của ảnh và link dừng ở cuối dòng.
     */
    private static function stripMarkupOnce(string $text): string
    {
        $text = self::replace('/<(script|style)\b[^>]*>.*?<\/\1\s*>/isu', '', $text);
        $text = self::replace('/<!--.*?-->/su', '', $text);

        $text = self::replace(
            '/!(?&label)(?:\([^)\n]*\)|\[[^\]\n]*\])'.self::LABEL_DEFINE.'/u',
            __('mcp.untrusted.image_removed'),
            $text,
        );
        $text = self::replace('/^[ ]{0,3}\[[^\]\n]+\]:[ ]*\S+.*$/mu', '', $text);
        $text = self::replace('/\[('.self::LABEL_BODY.'*+)\]\([^)\n]*\)'.self::LABEL_DEFINE.'/u', '$1', $text);
        $text = self::replace('/\[('.self::LABEL_BODY.'++)\]\[[^\]\n]*\]'.self::LABEL_DEFINE.'/u', '$1', $text);

        // Mọi thẻ HTML còn lại, kể cả autolink `<https://…>`. Dấu `<` `>` đứng trơ được giữ.
        return self::replace('/<[!?\/]?[a-z][^<>]*>/iu', '', $text);
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
