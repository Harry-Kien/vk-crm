<?php

use Symfony\Component\Finder\Finder;

/**
 * Rà soát SQL thô (M15 Task 1): MỌI lời gọi SQL thô mà đối số ĐẦU không phải chuỗi hằng — tức là
 * bất cứ thứ gì ngoài các chuỗi nguyên văn (có thể nối `.` với hằng lớp `self::X`/`Foo::X`) — phải
 * nằm trong danh sách đã duyệt `tests/Fixtures/Security/raw-sql-baseline.php`, kèm lý do an toàn.
 * Nghĩa là biến trần (`whereRaw($sql)`), nội suy, nối với biến hay với lời gọi hàm
 * (`'x = '.request('q')`), `sprintf`/`implode`... đều bị cờ: câu hỏi duy nhất là "có byte nào đến từ
 * request / dữ liệu người dùng gõ / cấu hình người dùng sửa không". Có thì sửa sang binding, KHÔNG
 * đưa vào danh sách. `DB::unprepared` luôn đỏ.
 *
 * Điểm cuối (sink): `whereRaw|orWhereRaw|selectRaw|orderByRaw|havingRaw|orHavingRaw|groupByRaw|
 * fromRaw|joinRaw`, `->raw()`, `new Expression()`, `DB::raw|select|selectOne|scalar|cursor|statement|
 * unprepared|insert|update|delete|affectingStatement`, và cùng các hàm đó (cộng `exec|query|prepare`)
 * trên một kết nối (`DB::connection()->…`, `getPdo()`, `$connection`, `$db`, `$pdo`).
 *
 * Quét bằng tokenizer của PHP (không regex trên dòng) nên câu lệnh nhiều dòng vẫn bắt được; dò tệp
 * bằng Finder vì DirectoryIterator rơi tệp trên ổ 9p của Docker.
 */

/**
 * Đối số đầu có là chuỗi hằng không: `'…'`/`"…"` không nội suy, nối `.` với hằng lớp (`self::X`).
 *
 * @param  list<mixed>  $arg
 */
function rawSqlArgIsConstant(array $arg): bool
{
    if ($arg === []) {
        return true;
    }

    $expectOperand = true;
    for ($k = 0; $k < count($arg); $k++) {
        $t = $arg[$k];
        if ($expectOperand) {
            if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $expectOperand = false;

                continue;
            }
            // Hằng lớp: Tên :: TÊN, không phải lời gọi hàm.
            if (is_array($t) && in_array($t[0], [T_STRING, T_STATIC, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && is_array($arg[$k + 1] ?? null) && $arg[$k + 1][0] === T_DOUBLE_COLON
                && is_array($arg[$k + 2] ?? null) && $arg[$k + 2][0] === T_STRING
                && ($arg[$k + 3] ?? null) !== '(') {
                $k += 2;
                $expectOperand = false;

                continue;
            }

            return false;
        }

        if ($t !== '.') {
            return false;
        }
        $expectOperand = true;
    }

    return ! $expectOperand;
}

/** Thứ đứng trước `->` có phải một kết nối CSDL không. */
function rawSqlOnConnection(array $tokens, int $arrowIndex): bool
{
    $p = $arrowIndex - 1;
    $t = $tokens[$p] ?? null;

    if (is_array($t) && $t[0] === T_VARIABLE) {
        return in_array($t[1], ['$connection', '$conn', '$db', '$pdo'], true);
    }

    if ($t === ')') {
        $depth = 0;
        for ($q = $p; $q >= 0; $q--) {
            if ($tokens[$q] === ')') {
                $depth++;
            } elseif ($tokens[$q] === '(') {
                $depth--;
                if ($depth === 0) {
                    $n = $tokens[$q - 1] ?? null;

                    return is_array($n) && in_array(strtolower($n[1]), ['connection', 'getconnection', 'getpdo', 'getreadpdo', 'getwritepdo'], true);
                }
            }
        }
    }

    return false;
}

/**
 * @return array{found: array<string, string>, unprepared: list<string>}
 */
function rawSqlInterpolationSites(): array
{
    $methods = ['whereraw', 'orwhereraw', 'selectraw', 'orderbyraw', 'havingraw', 'orhavingraw', 'groupbyraw', 'fromraw', 'joinraw', 'raw', 'unprepared'];
    $dbStatics = ['raw', 'select', 'selectone', 'scalar', 'cursor', 'statement', 'unprepared', 'insert', 'update', 'delete', 'affectingstatement'];
    $connMethods = [...$dbStatics, 'exec', 'query', 'prepare'];
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
            if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) || ($tokens[$i + 1] ?? null) !== '(') {
                continue;
            }

            $name = strtolower($token[1]);
            $prev = $tokens[$i - 1] ?? null;
            $isArrow = is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);
            $isStatic = is_array($prev) && $prev[0] === T_DOUBLE_COLON;
            $onDb = $isStatic && is_array($tokens[$i - 2] ?? null) && str_ends_with($tokens[$i - 2][1], 'DB');
            $isNewExpression = is_array($prev) && $prev[0] === T_NEW && str_ends_with($name, 'expression');

            $hit = $isNewExpression
                || (($isArrow || $isStatic) && in_array($name, $methods, true) && $token[0] === T_STRING)
                || ($onDb && in_array($name, $dbStatics, true))
                || ($isArrow && in_array($name, $connMethods, true) && rawSqlOnConnection($tokens, $i - 1));
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

            if (rawSqlArgIsConstant($arg)) {
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
