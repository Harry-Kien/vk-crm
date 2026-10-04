<?php

/*
|--------------------------------------------------------------------------
| M14 Task 1 — không I/O mạng tới kho bên trong `DB::transaction` (kế hoạch M14, R2)
|--------------------------------------------------------------------------
|
| Một lượt tải lên Drive giữ khoá hàng `matters`/`matter_checklist_items` trong khi chờ mạng (gói
| bàn giao tới 2 GB), và một lỗi Google rollback cả tệp khách vừa nộp. Luật: trong `app/Actions/` và
| `app/Support/Storage/`, không lời gọi nào sau đây nằm trong dấu ngoặc của một
| `DB::transaction(...)`:
|
|  - `DocumentStore::remote(` — lấy đĩa kho;
|  - `->writeStream(`, `->readStream(`, `->checksum(` (kể cả `?->`) — luồng và md5 trên bất kỳ đĩa
|    nào, vì ở chỗ gọi không phân biệt được đĩa cục bộ với đĩa kho;
|  - `Http::` — mọi lời gọi HTTP.
|
| Cùng cách quét với luật "không Mail:: trong transaction" của `tests/Feature/ArchitectureTest.php`:
| `token_get_all`, bỏ chú thích trước khi so (docblock giải thích luật không được tự tố chính nó),
| đếm độ sâu ngoặc từ dấu `(` ngay sau `transaction`. Mở rộng hơn luật kia ở hai chỗ: nhận cả
| `->transaction(` (ví dụ `DB::connection()->transaction(`) và tên lớp viết đầy đủ
| (`\Illuminate\Support\Facades\Http::`).
|
| Giới hạn, ghi rõ: quét tĩnh một tầng. Một Action gọi trong transaction một Action KHÁC mà Action
| đó đi mạng thì luật này không thấy; người rà soát vẫn phải đọc.
*/

/**
 * Các lời gọi I/O kho nằm trong dấu ngoặc của một `DB::transaction(` / `->transaction(` ở mã
 * nguồn PHP đã cho, theo thứ tự xuất hiện (`'Http::'`, `'DocumentStore::remote('`, `'->readStream('`,
 * …). Rỗng nghĩa là sạch.
 *
 * @return list<string>
 */
function remoteStorageIoInsideTransactions(string $source): array
{
    $tokens = array_values(array_filter(
        token_get_all($source),
        fn ($token) => ! (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)),
    ));

    $text = fn ($token): string => is_array($token) ? $token[1] : (string) $token;
    $isWhitespace = fn ($token): bool => is_array($token) && $token[0] === T_WHITESPACE;

    /** Chỉ số token kế tiếp (hoặc trước đó), bỏ qua khoảng trắng; null khi hết. */
    $step = function (int $i, int $direction) use ($tokens, $isWhitespace): ?int {
        $j = $i + $direction;

        while (isset($tokens[$j]) && $isWhitespace($tokens[$j])) {
            $j += $direction;
        }

        return isset($tokens[$j]) ? $j : null;
    };

    $textAt = fn (?int $i): string => $i === null ? '' : $text($tokens[$i]);

    /** Tên ngắn của một định danh (`\Illuminate\Support\Facades\Http` → `Http`), null nếu không phải tên. */
    $shortName = function ($token): ?string {
        if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return null;
        }

        $parts = explode('\\', $token[1]);

        return end($parts);
    };

    $offenses = [];
    $armed = false; // đã thấy "transaction", đang đợi đúng dấu "(" mở lời gọi
    $depth = 0;     // > 0: đang ở trong dấu ngoặc của lời gọi transaction

    foreach ($tokens as $i => $token) {
        if ($depth === 0) {
            if ($armed) {
                if ($token === '(') {
                    $depth = 1;
                    $armed = false;
                } elseif (! $isWhitespace($token)) {
                    $armed = false;
                }

                continue;
            }

            if (is_array($token) && $token[0] === T_STRING && $token[1] === 'transaction') {
                $operator = $step($i, -1);

                if (in_array($textAt($operator), ['->', '?->'], true)) {
                    $armed = true;
                } elseif ($textAt($operator) === '::') {
                    $class = $step($operator, -1);
                    $armed = $class !== null && $shortName($tokens[$class]) === 'DB';
                }
            }

            continue;
        }

        if ($token === '(') {
            $depth++;

            continue;
        }

        if ($token === ')') {
            $depth--;

            continue;
        }

        $name = $shortName($token);

        if ($name === null) {
            continue;
        }

        $after = $step($i, 1);

        if ($name === 'Http' && $textAt($after) === '::') {
            $offenses[] = 'Http::';
        } elseif ($name === 'DocumentStore' && $textAt($after) === '::') {
            $method = $step($after, 1);

            if ($textAt($method) === 'remote' && $textAt($step($method, 1)) === '(') {
                $offenses[] = 'DocumentStore::remote(';
            }
        } elseif (in_array($name, ['writeStream', 'readStream', 'checksum'], true)
            && $textAt($after) === '('
            && in_array($textAt($step($i, -1)), ['->', '?->'], true)
        ) {
            $offenses[] = '->'.$name.'(';
        }
    }

    return $offenses;
}

it('không có DocumentStore::remote(, ->writeStream(, ->readStream(, ->checksum( hay Http:: nào chạy bên trong DB::transaction ở app/Actions và app/Support/Storage', function () {
    $offenders = [];

    foreach ([app_path('Actions'), app_path('Support/Storage')] as $root) {
        expect(is_dir($root))->toBeTrue("thư mục quét không tồn tại: {$root}");

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $found = remoteStorageIoInsideTransactions((string) file_get_contents($file->getPathname()));

            if ($found !== []) {
                $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()).' — '.implode(', ', $found);
            }
        }
    }

    expect($offenders)->toBe([], 'I/O kho chạy bên trong DB::transaction ở: '.implode('; ', $offenders));
});

/**
 * Bộ quét phải ĐỎ được: mỗi dạng vi phạm, đặt trong một transaction, bị bắt. Không có cặp này thì
 * test ở trên xanh cả khi bộ quét hỏng (đúng lỗi đếm ngoặc hai lần mà luật `Mail::` từng mắc).
 */
it('bộ quét bắt từng dạng vi phạm đặt trong transaction', function (string $body, array $expected) {
    expect(remoteStorageIoInsideTransactions("<?php\n".$body))->toBe($expected);
})->with([
    'DocumentStore::remote(' => ['DB::transaction(function () { DocumentStore::remote()->exists("18/a.pdf"); });', ['DocumentStore::remote(']],
    '->writeStream(' => ['DB::transaction(fn () => $disk->writeStream("18/a.pdf", $stream));', ['->writeStream(']],
    '->readStream(' => ['DB::transaction(function () use ($disk) { return $disk->readStream("18/a.pdf"); });', ['->readStream(']],
    '?->checksum(' => ['DB::transaction(fn () => $disk?->checksum("18/a.pdf"));', ['->checksum(']],
    'Http::' => ['DB::transaction(function () { Http::timeout(5)->get("https://example.test"); });', ['Http::']],
    'tên lớp đầy đủ' => ['\Illuminate\Support\Facades\DB::transaction(function () { \Illuminate\Support\Facades\Http::get("x"); \App\Support\Storage\DocumentStore::remote(); });', ['Http::', 'DocumentStore::remote(']],
    '->transaction(' => ['DB::connection()->transaction(function () { Http::get("x"); });', ['Http::']],
    'sau ngoặc lồng nhau' => ['DB::transaction(function () { if (count(array_filter([1]))) { foo(bar()); } $disk->readStream("x"); });', ['->readStream(']],
    'khoảng trắng trước ngoặc' => ['DB::transaction (function () { Http::get("x"); });', ['Http::']],
]);

it('bộ quét không tố lời gọi nằm ngoài transaction, trong chú thích, hay chỉ là hằng/chuỗi', function (string $body) {
    expect(remoteStorageIoInsideTransactions("<?php\n".$body))->toBe([]);
})->with([
    'trước và sau transaction' => ['$s = $disk->readStream("x"); DB::transaction(fn () => Media::query()->whereKey(1)->update(["disk" => "private"])); Http::get("y"); DocumentStore::remote()->exists("z");'],
    'trong chú thích' => ['DB::transaction(function () { /* Http::get("x") */ // $disk->readStream("x")
        Media::query()->delete(); });'],
    'hằng REMOTE_DISK' => ['DB::transaction(fn () => Media::query()->where("disk", DocumentStore::REMOTE_DISK)->count());'],
    'chuỗi chứa Http::' => ['DB::transaction(fn () => Log::info("Http:: và ->readStream( chỉ là chữ"));'],
    'tên hàm trùng nhưng không phải phương thức' => ['DB::transaction(fn () => checksum("x"));'],
    'transaction không phải của DB' => ['Queue::transaction(function () { Http::get("x"); });'],
]);
