<?php

namespace Tests\Support;

use Symfony\Component\Finder\Finder;

/**
 * Bộ dò SQL thô cho `RawSqlInterpolationTest` (M15 Task 1). Quét bằng tokenizer của PHP (không regex
 * trên dòng) nên câu lệnh nhiều dòng vẫn bắt được.
 *
 * Luật: một điểm cuối (sink) bị cờ trừ khi đối số ĐẦU của nó chỉ gồm chuỗi nguyên văn, có thể nối `.`
 * với hằng lớp (`self::X`, `Foo::X`; lời gọi hàm/phương thức, `self::$x`, nội suy, heredoc/nowdoc, đối
 * số có tên, `...$args` đều KHÔNG phải hằng). Câu hỏi duy nhất cho một chỗ bị cờ: có byte nào đến từ
 * request / dữ liệu người dùng gõ / cấu hình người dùng sửa không.
 *
 * Điểm cuối:
 *  - tên riêng, mọi đối tượng: `whereRaw|orWhereRaw|selectRaw|orderByRaw|havingRaw|orHavingRaw|groupByRaw|
 *    fromRaw|joinRaw|raw|unprepared|statement|affectingStatement|selectOne|scalar|
 *    selectFromWriteConnection|selectResultSets`;
 *  - `new Expression(...)`;
 *  - `DB::` các hàm `select|insert|update|delete|cursor` (cộng các tên trên);
 *  - `select|insert|update|delete|cursor|exec|query|prepare` trên một kết nối (`DB::connection()->`,
 *    `getConnection()`, `getPdo()`, `$connection|$conn|$db|$pdo`, `->connection|db|pdo->`) — những
 *    tên này cũng là hàm của query builder nên không thể cờ trên mọi đối tượng.
 */
final class RawSqlScanner
{
    private const NAMED_SINKS = [
        'whereraw', 'orwhereraw', 'selectraw', 'orderbyraw', 'havingraw', 'orhavingraw', 'groupbyraw',
        'fromraw', 'joinraw', 'raw', 'unprepared', 'statement', 'affectingstatement', 'selectone',
        'scalar', 'selectfromwriteconnection', 'selectresultsets',
    ];

    private const DB_STATIC_SINKS = ['select', 'insert', 'update', 'delete', 'cursor'];

    private const CONNECTION_SINKS = ['select', 'insert', 'update', 'delete', 'cursor', 'exec', 'query', 'prepare'];

    private const CONNECTION_VARIABLES = ['$connection', '$conn', '$db', '$pdo'];

    private const CONNECTION_PROPERTIES = ['connection', 'db', 'pdo'];

    private const CONNECTION_GETTERS = ['connection', 'getconnection', 'getpdo', 'getreadpdo', 'getwritepdo'];

    /**
     * @return array{sites: list<array{sink: string, arg: string, key: string, line: int}>, unprepared: list<string>}
     */
    public static function scan(string $source, string $relative): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $text = fn ($t) => is_array($t) ? $t[1] : $t;
        $sites = [];
        $unprepared = [];

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
                || (($isArrow || $isStatic) && $token[0] === T_STRING && in_array($name, self::NAMED_SINKS, true))
                || ($onDb && in_array($name, self::DB_STATIC_SINKS, true))
                || ($isArrow && in_array($name, self::CONNECTION_SINKS, true) && self::onConnection($tokens, $i - 1));
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

            if (self::isConstant($arg)) {
                continue;
            }

            $normalized = trim((string) preg_replace('/\s+/', ' ', implode('', array_map($text, $arg))));
            $sink = $isNewExpression ? 'new '.$token[1] : $token[1];
            $sites[] = ['sink' => $sink, 'arg' => $normalized, 'key' => $relative.':'.$sink.':'.$normalized, 'line' => $token[2]];
        }

        return ['sites' => $sites, 'unprepared' => $unprepared];
    }

    /**
     * Quét mọi tệp `.php` dưới `app/` (Finder, không DirectoryIterator — rơi tệp trên ổ 9p).
     *
     * @return array{counts: array<string, int>, where: array<string, string>, unprepared: list<string>}
     */
    public static function scanApp(): array
    {
        $root = base_path();
        $counts = [];
        $where = [];
        $unprepared = [];

        foreach ((new Finder)->files()->in(base_path('app'))->name('*.php')->sortByName() as $file) {
            $relative = str_replace(chr(92), '/', substr($file->getRealPath(), strlen($root) + 1));
            $result = self::scan($file->getContents(), $relative);
            $unprepared = [...$unprepared, ...$result['unprepared']];
            foreach ($result['sites'] as $site) {
                $counts[$site['key']] = ($counts[$site['key']] ?? 0) + 1;
                $where[$site['key']] = ($where[$site['key']] ?? '').' '.$relative.':'.$site['line'];
            }
        }

        return ['counts' => $counts, 'where' => array_map('trim', $where), 'unprepared' => $unprepared];
    }

    /**
     * Đối số đầu có là chuỗi hằng không: `'…'`/`"…"` không nội suy, nối `.` với hằng lớp (`self::X`).
     *
     * @param  list<mixed>  $arg
     */
    private static function isConstant(array $arg): bool
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

    /** Thứ đứng trước `->` (ở vị trí $arrow) có phải một kết nối CSDL không. */
    private static function onConnection(array $tokens, int $arrow): bool
    {
        $p = $arrow - 1;
        $t = $tokens[$p] ?? null;

        if (is_array($t) && $t[0] === T_VARIABLE) {
            return in_array($t[1], self::CONNECTION_VARIABLES, true);
        }

        // `$this->connection->…`
        if (is_array($t) && $t[0] === T_STRING && in_array(strtolower($t[1]), self::CONNECTION_PROPERTIES, true)) {
            $a = $tokens[$p - 1] ?? null;

            return is_array($a) && in_array($a[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);
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

                        return is_array($n) && in_array(strtolower($n[1]), self::CONNECTION_GETTERS, true);
                    }
                }
            }
        }

        return false;
    }
}
