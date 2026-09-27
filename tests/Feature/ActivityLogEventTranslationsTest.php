<?php

/**
 * Task 20 (phát hiện "lượt rà soát cuối"): trang Nhật ký hệ thống hiện khoá dịch thô
 * (`activity.events.document_uploaded`) cho khoảng 11 loại sự kiện của M4–M6. Test này quét MỌI
 * literal `Audit::record('…')` trong `app/` và đòi có mặt trong `lang/vi/activity.php` — không
 * dựa vào việc ai đó nhớ cập nhật danh sách bằng tay mỗi khi thêm một sự kiện mới.
 *
 * Task 9 và Task 11 chạy song song trên nhánh này có thể thêm khoá sự kiện MỚI sau khi các làn
 * gộp lại. Nếu bộ test này đỏ SAU KHI gộp, thông điệp lỗi nêu đích danh từng khoá còn thiếu —
 * thêm đúng khoá đó vào `lang/vi/activity.php` (mục `events`), không cần đọc lại toàn bộ tệp.
 *
 * # Vì sao quét bằng token, không bằng một regex một dòng
 *
 * Phần lớn lời gọi có dạng `Audit::record('ten_su_kien', ...)` — literal ngay tham số đầu. Nhưng
 * `App\Actions\Portal\ReplyToClientRequest` gọi theo dạng:
 *
 *     Audit::record(
 *         $actor instanceof ClientUser
 *             ? 'client_request_replied_by_client'
 *             : 'client_request_answered_by_staff',
 *         $reply,
 *         [...],
 *     );
 *
 * — tên sự kiện là MỘT TRONG HAI literal của một biểu thức ba ngôi, không phải tham số đầu tiên
 * đọc thẳng. Một regex neo vào `Audit::record\('...'` sẽ bỏ sót cặp này. `token_get_all()` quét
 * đúng ngữ pháp PHP: tìm chuỗi token `Audit`, `::`, `record`, `(`, rồi thu mọi
 * `T_CONSTANT_ENCAPSED_STRING` gặp được ở ĐỘ SÂU NGOẶC ĐÚNG BẰNG 1 (tức còn trong lời gọi này,
 * chưa lồng vào một lời gọi hàm khác) cho tới dấu phẩy ĐẦU TIÊN ở độ sâu đó — dấu phẩy đó kết
 * thúc tham số thứ nhất, nên các chuỗi của mảng `$properties` (tham số thứ ba, ví dụ `'guard'`,
 * `'ip'`) không lọt vào danh sách tên sự kiện.
 *
 * Token hoá cũng tự bỏ qua chú thích (`T_COMMENT`/`T_DOC_COMMENT` là MỘT token duy nhất, không
 * tách chuỗi bên trong ra thành `T_CONSTANT_ENCAPSED_STRING`), nên các dòng docblock NHẮC TỚI
 * `Audit::record('login_success', ...)` như ví dụ (có thật trong `App\Support\Audit`) không bị
 * hiểu nhầm thành một lời gọi.
 *
 * @return list<string>
 */
function findAuditRecordEventLiterals(): array
{
    $events = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), RecursiveDirectoryIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $tokens = token_get_all((string) file_get_contents($file->getPathname()));
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! (is_array($tokens[$i]) && $tokens[$i][0] === T_STRING && $tokens[$i][1] === 'Audit')) {
                continue;
            }

            $j = $i + 1;
            $skipTrivia = function () use ($tokens, $count, &$j): array|string|null {
                while ($j < $count && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $j++;
                }

                return $j < $count ? $tokens[$j] : null;
            };

            $doubleColon = $skipTrivia();
            $j++;

            if (! (is_array($doubleColon) && $doubleColon[0] === T_DOUBLE_COLON)) {
                continue;
            }

            $recordWord = $skipTrivia();
            $j++;

            if (! (is_array($recordWord) && $recordWord[0] === T_STRING && $recordWord[1] === 'record')) {
                continue;
            }

            $openParen = $skipTrivia();
            $j++;

            if ($openParen !== '(') {
                continue;
            }

            $depth = 1;

            for ($k = $j; $k < $count && $depth > 0; $k++) {
                $token = $tokens[$k];

                if ($token === '(') {
                    $depth++;

                    continue;
                }

                if ($token === ')') {
                    $depth--;

                    continue;
                }

                if ($depth === 1 && $token === ',') {
                    break;
                }

                if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $events[] = trim($token[1], "'\"");
                }
            }
        }
    }

    return array_values(array_unique($events));
}

it('finds Audit::record() calls in app/, as a sanity check that the scan itself works', function () {
    // Tiền đề của test dưới: nếu quét ra một danh sách RỖNG, test dịch sẽ xanh mãi mãi mà không
    // đo được gì — cùng nguyên tắc đã ghi ở ArchitectureTest.php cho vụ quét "hàm gỡ lỗi".
    expect(findAuditRecordEventLiterals())->not->toBeEmpty();
});

/**
 * Chứng minh lý do chọn token hoá thay vì regex một dòng (xem docblock hàm quét): hai literal
 * của biểu thức ba ngôi trong `App\Actions\Portal\ReplyToClientRequest` phải có mặt trong kết quả
 * quét, không chỉ literal đơn giản ở tham số đầu của các nơi gọi khác.
 */
it('finds both literals of a ternary-chosen event name, not just the simple first-argument form', function () {
    $events = findAuditRecordEventLiterals();

    expect($events)->toContain('client_request_replied_by_client')
        ->toContain('client_request_answered_by_staff');
});

it('has a lang/vi/activity.php translation for every Audit::record() event literal in app/', function () {
    $events = findAuditRecordEventLiterals();

    $translated = array_keys(__('activity.events'));

    $missing = array_values(array_diff($events, $translated));

    expect($missing)->toBe([], 'Thiếu bản dịch lang/vi/activity.php cho các khoá sự kiện: '.implode(', ', $missing));
});
