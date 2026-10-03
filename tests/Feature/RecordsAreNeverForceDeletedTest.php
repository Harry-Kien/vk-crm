<?php

/**
 * M7 Task 6 (R5): "Bất kỳ mã nào trong milestone này gọi `forceDelete()` trên dữ liệu hồ sơ là
 * sai." Tiêu huỷ hồ sơ là quyết định của người, có biên bản, làm ngoài hệ thống; hệ thống chỉ GHI
 * quyết định đó (`RecordMatterDestruction`) và chỉ CẢNH BÁO khi tới hạn (`FlagRetentionExpiry`).
 *
 * **Quét LỜI GỌI, không quét chuỗi.** Chữ `forceDelete` hợp lệ ở nhiều chỗ: phương thức policy
 * `forceDelete()` luôn trả `false` (`MatterPolicy`, `ClientPolicy`, …), `ForceDeleteBulkAction`
 * của Filament bị chính policy đó chặn, và docblock nhắc tên nó trong văn xuôi. Nên test tách
 * token bằng `token_get_all()` (chú thích và chuỗi là token riêng, không bao giờ khớp) và chỉ bắt
 * dạng gọi: `->`/`?->`/`::` + `forceDelete`/`forceDeleteQuietly`/`forceDestroy` + `(`. Tên phương
 * thức PHP không phân biệt hoa thường, nên so cũng không phân biệt.
 *
 * **Phạm vi: `app/`, `routes/`, `database/seeders/`** — mọi nơi mã sản phẩm chạy. Kiểu của vế bên
 * trái `->` không đọc được bằng phân tích tĩnh, nên MỌI lời gọi trong ba thư mục này đều bị coi là
 * xoá vĩnh viễn dữ liệu hồ sơ. Một ngày có lời gọi hợp lệ trên một model KHÔNG phải hồ sơ (ví dụ một
 * bảng mã tạm), nó phải được thêm TƯỜNG MINH vào `$allowed` bên dưới — đường dẫn + lý do — để người
 * rà soát thấy nó, không lặng lẽ lọt qua.
 *
 * Chiều ngược lại cũng được đo: bộ quét được chạy trên mấy đoạn mã mẫu, một đoạn có lời gọi phải
 * bị bắt — không thì một bộ quét hỏng (luôn trả rỗng) để test này xanh mãi mà không đo gì.
 */

/**
 * @return list<int> số dòng của mỗi lời gọi xoá vĩnh viễn trong `$source`
 */
function forceDeleteCallLines(string $source): array
{
    $tokens = token_get_all($source);
    $count = count($tokens);
    $lines = [];

    $next = function (int $i) use ($tokens, $count): ?int {
        for ($j = $i + 1; $j < $count; $j++) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $j;
        }

        return null;
    };

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (! is_array($token) || ! in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
            continue;
        }

        $nameAt = $next($i);

        if ($nameAt === null || ! is_array($tokens[$nameAt]) || $tokens[$nameAt][0] !== T_STRING
            || ! in_array(strtolower($tokens[$nameAt][1]), ['forcedelete', 'forcedeletequietly', 'forcedestroy'], true)) {
            continue;
        }

        $parenAt = $next($nameAt);

        if ($parenAt !== null && $tokens[$parenAt] === '(') {
            $lines[] = $tokens[$nameAt][2];
        }
    }

    return $lines;
}

it('bộ quét bắt được lời gọi thật và bỏ qua những chỗ chỉ nhắc tên', function () {
    $calls = <<<'PHP'
    <?php
    $matter->forceDelete();
    $document?->forceDeleteQuietly();
    Matter::query()->whereKey(1)->ForceDelete ();
    Document::forceDestroy([1, 2]);
    $archive
        ->forceDelete();
    PHP;

    expect(forceDeleteCallLines($calls))->toBe([2, 3, 4, 5, 7]);

    $mentions = <<<'PHP'
    <?php
    class MatterPolicy {
        /** Không ai gọi được ->forceDelete( trên vụ việc. */
        public function forceDelete($user, $matter): bool { return false; }
    }
    // $matter->forceDelete();
    $label = '->forceDelete(';
    $actions = [ForceDeleteBulkAction::make(), ForceDeleteAction::make()];
    $callable = [$model, 'forceDelete'];
    $flag = $matter->forceDeleting;
    PHP;

    expect(forceDeleteCallLines($mentions))->toBe([]);
});

it('không có lời gọi xoá vĩnh viễn nào trong app/, routes/, database/seeders/', function () {
    /** Đường dẫn tương đối (dấu `/`) => lý do. Rỗng: hôm nay không có ngoại lệ nào. */
    $allowed = [];

    $files = [];

    foreach ([app_path(), base_path('routes'), database_path('seeders')] as $root) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    // Tiền đề: bộ duyệt thư mục thật sự đi tới từng gốc, kể cả thư mục con sâu (nghiệp vụ
    // xoá/lưu trữ vụ việc, model hồ sơ, seeder dữ liệu mẫu). Chọn tệp có từ trước M7 Task 6, để
    // test này xanh ngay trên mã nền — nó đo "không có lời gọi", không đo tệp mới của task.
    $relative = fn (string $path): string => str_replace('\\', '/', substr($path, strlen(base_path()) + 1));
    $relativeFiles = array_map($relative, $files);
    expect($relativeFiles)->toContain('app/Actions/Matter/CancelMatter.php')
        ->toContain('app/Models/Matter.php')
        ->toContain('routes/console.php')
        ->toContain('database/seeders/MatterSeeder.php');

    $offenders = [];

    foreach ($files as $file) {
        $path = $relative($file);

        if (array_key_exists($path, $allowed)) {
            continue;
        }

        foreach (forceDeleteCallLines((string) file_get_contents($file)) as $line) {
            $offenders[] = "{$path}:{$line}";
        }
    }

    expect($offenders)->toBe([], 'lời gọi xoá vĩnh viễn dữ liệu (R5 cấm): '.implode(', ', $offenders));
});
