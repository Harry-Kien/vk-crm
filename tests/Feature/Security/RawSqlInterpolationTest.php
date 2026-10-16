<?php

use Symfony\Component\Finder\Finder;

/**
 * Rà soát SQL thô (M15 Task 1): mọi lời gọi SQL thô mà đối số ĐẦU có chèn biến — chuỗi nháy kép
 * có `$`/`{$`, hoặc phép nối `.` với biến — phải nằm trong danh sách đã duyệt
 * `tests/Fixtures/Security/raw-sql-baseline.php`, kèm lý do an toàn. Chỗ nào chèn giá trị đến từ
 * người dùng thì phải sửa sang binding, KHÔNG được đưa vào danh sách. `DB::unprepared` luôn đỏ.
 *
 * Quét bằng tokenizer của PHP (không regex trên dòng) nên câu lệnh nhiều dòng vẫn bắt được; dò tệp
 * bằng Finder vì DirectoryIterator rơi tệp trên ổ 9p của Docker.
 */

/**
 * @return array{found: array<string, string>, unprepared: list<string>}
 */
function rawSqlInterpolationSites(): array
{
    $methods = ['whereraw', 'orwhereraw', 'selectraw', 'orderbyraw', 'havingraw', 'orhavingraw', 'groupbyraw', 'fromraw', 'joinraw'];
    $dbStatics = ['raw', 'select', 'statement', 'unprepared', 'insert', 'update', 'delete'];
    $root = base_path();
    $found = [];
    $unprepared = [];

    $finder = (new Finder)->files()->in(base_path('app'))->name('*.php')->sortByName();

    foreach ($finder as $file) {
        $relative = str_replace(chr(92), '/', substr($file->getRealPath(), strlen($root) + 1));
        $tokens = array_values(array_filter(
            token_get_all($file->getContents()),
            fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $text = fn ($t) => is_array($t) ? $t[1] : $t;

        foreach ($tokens as $i => $token) {
            if (! is_array($token) || $token[0] !== T_STRING || ($tokens[$i + 1] ?? null) !== '(') {
                continue;
            }

            $name = strtolower($token[1]);
            $prev = $tokens[$i - 1] ?? null;
            $isArrow = is_array($prev) ? $prev[0] === T_OBJECT_OPERATOR || $prev[0] === T_NULLSAFE_OBJECT_OPERATOR : false;
            $isStatic = is_array($prev) && $prev[0] === T_DOUBLE_COLON;
            $onDb = $isStatic && is_array($tokens[$i - 2] ?? null) && ($tokens[$i - 2][1] === 'DB' || str_ends_with($tokens[$i - 2][1], 'DB'));

            $hit = ($isArrow && (in_array($name, $methods, true) || in_array($name, ['unprepared', 'statement'], true)))
                || ($onDb && in_array($name, $dbStatics, true))
                || ($isStatic && in_array($name, $methods, true));
            if (! $hit) {
                continue;
            }

            if ($name === 'unprepared') {
                $unprepared[] = $relative.':'.$token[2];
            }

            // Đối số đầu: từ sau "(" tới dấu "," hoặc ")" ở độ sâu 0.
            $depth = 0;
            $arg = [];
            for ($j = $i + 2; $j < count($tokens); $j++) {
                $t = $tokens[$j];
                $s = $text($t);
                if (in_array($s, ['(', '[', '{'], true) || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;
                } elseif (in_array($s, [')', ']', '}'], true)) {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                } elseif ($s === ',' && $depth === 0) {
                    break;
                }
                $arg[] = $t;
            }

            $interpolated = false;
            $inQuote = false;
            $hasConcat = false;
            $hasVariable = false;
            foreach ($arg as $t) {
                if ($t === '"') {
                    $inQuote = ! $inQuote;
                }
                if (is_array($t) && in_array($t[0], [T_VARIABLE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $hasVariable = true;
                    if ($inQuote) {
                        $interpolated = true;
                    }
                }
                if ($t === '.' && ! $inQuote) {
                    $hasConcat = true;
                }
            }
            // Heredoc có biến: token T_START_HEREDOC + T_VARIABLE, không có dấu nháy kép rời.
            if ($hasVariable && ! $interpolated && array_filter($arg, fn ($t) => is_array($t) && $t[0] === T_START_HEREDOC)) {
                $interpolated = true;
            }
            if ($hasConcat && $hasVariable) {
                $interpolated = true;
            }
            if (! $interpolated) {
                continue;
            }

            $normalized = trim((string) preg_replace('/\s+/', ' ', implode('', array_map($text, $arg))));
            $found[$relative.':'.$normalized] = $relative.':'.$token[2];
        }
    }

    return ['found' => $found, 'unprepared' => $unprepared];
}

it('mọi câu SQL thô có chèn biến đều nằm trong danh sách đã duyệt', function () {
    $baseline = require base_path('tests/Fixtures/Security/raw-sql-baseline.php');
    $sites = rawSqlInterpolationSites();

    expect($sites['unprepared'])->toBe([], 'DB::unprepared không bao giờ được dùng: '.implode(', ', $sites['unprepared']));

    $new = array_diff_key($sites['found'], $baseline);
    $report = [];
    foreach ($new as $key => $where) {
        $report[] = "{$where}  =>  {$key}";
    }

    expect($report)->toBe([], "SQL thô chèn biến chưa duyệt:\n".implode("\n", $report));
});

it('danh sách nền không giữ chỗ đã biến mất', function () {
    $baseline = require base_path('tests/Fixtures/Security/raw-sql-baseline.php');
    $sites = rawSqlInterpolationSites();

    $gone = array_keys(array_diff_key($baseline, $sites['found']));

    expect($gone)->toBe([], "Khoá nền không còn trong mã:\n".implode("\n", $gone));
});

it('mọi khoá nền đều kèm lý do', function () {
    $baseline = require base_path('tests/Fixtures/Security/raw-sql-baseline.php');

    foreach ($baseline as $key => $reason) {
        expect(trim($reason))->not->toBe('', "Thiếu lý do cho {$key}");
    }
});
