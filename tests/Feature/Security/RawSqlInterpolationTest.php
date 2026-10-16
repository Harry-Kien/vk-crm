<?php

use Tests\Support\RawSqlScanner;

/**
 * Rà soát SQL thô (M15 Task 1): MỌI lời gọi SQL thô mà đối số ĐẦU không phải chuỗi hằng phải nằm trong
 * danh sách đã duyệt `tests/Fixtures/Security/raw-sql-baseline.php` — theo (tệp, tên hàm, đối số) và
 * ĐÚNG số lần đã duyệt — kèm lý do an toàn. Chỗ nào có byte đến từ người dùng thì sửa sang binding,
 * KHÔNG đưa vào danh sách. `DB::unprepared` luôn đỏ. Luật và danh sách điểm cuối ở
 * {@see RawSqlScanner}; các dataset cuối tệp ghim bộ dò (mẫu nào PHẢI bị cờ, mẫu nào KHÔNG).
 */
it('mọi câu SQL thô có đối số đầu không phải chuỗi hằng đều nằm trong danh sách đã duyệt', function () {
    $baseline = require base_path('tests/Fixtures/Security/raw-sql-baseline.php');
    $scan = RawSqlScanner::scanApp();

    expect($scan['unprepared'])->toBe([], 'DB::unprepared không bao giờ được dùng: '.implode(', ', $scan['unprepared']));

    $report = [];
    foreach ($scan['counts'] as $key => $count) {
        $approved = $baseline[$key]['count'] ?? 0;
        if ($count > $approved) {
            $report[] = "{$scan['where'][$key]}  =>  {$key}  (có {$count}, đã duyệt {$approved})";
        }
    }

    expect($report)->toBe([], "SQL thô chưa duyệt:\n".implode("\n", $report));
});

it('danh sách nền không giữ chỗ đã biến mất hay đã bớt đi', function () {
    $baseline = require base_path('tests/Fixtures/Security/raw-sql-baseline.php');
    $scan = RawSqlScanner::scanApp();

    $stale = [];
    foreach ($baseline as $key => $entry) {
        $found = $scan['counts'][$key] ?? 0;
        if ($found < $entry['count']) {
            $stale[] = "{$key}  (còn {$found}, nền ghi {$entry['count']})";
        }
    }

    expect($stale)->toBe([], "Khoá nền không còn đủ trong mã:\n".implode("\n", $stale));
});

it('mọi khoá nền đều kèm số lần và lý do', function () {
    $baseline = require base_path('tests/Fixtures/Security/raw-sql-baseline.php');

    foreach ($baseline as $key => $entry) {
        expect($entry['count'])->toBeInt()->toBeGreaterThan(0)
            ->and(trim($entry['reason']))->not->toBe('', "Thiếu lý do cho {$key}");
    }
});

// ---------------------------------------------------------------------------------------------
// Ghim bộ dò: mẫu nào PHẢI bị cờ.
// ---------------------------------------------------------------------------------------------

dataset('flagged raw sql', [
    'nối với request()' => ['$q->whereRaw(\'name = \'.request(\'q\'));'],
    'orderByRaw nối request()' => ['$q->orderByRaw(request(\'sort\').\' desc\');'],
    'biến trần $sql' => ['$q->whereRaw($sql);'],
    'DB::raw biến trần' => ['DB::raw($person);'],
    'sprintf' => ['$q->whereRaw(sprintf("a = \'%s\'", $x));'],
    'implode' => ['$q->whereRaw(implode(\' AND \', $conds));'],
    'lời gọi hàm tĩnh Helper::x($v)' => ['$q->whereRaw(Helper::expr($v));'],
    'biến tĩnh self::$x' => ['$q->whereRaw(self::$x);'],
    'lời gọi static::m()' => ['$q->whereRaw(static::m());'],
    'nội suy "{$x}"' => ['$q->selectRaw("a = {$x}");'],
    'nội suy $x trần trong nháy kép' => ['$q->selectRaw("a = $x");'],
    'heredoc' => ["\$q->whereRaw(<<<SQL\n a = {\$x}\nSQL);"],
    'nowdoc (không coi là hằng)' => ["\$q->whereRaw(<<<'SQL'\n a = 1\nSQL);"],
    'đối số có tên' => ['$q->whereRaw(sql: $x);'],
    'trải mảng ...$args' => ['$q->whereRaw(...$args);'],
    'new Expression($x)' => ['$e = new Expression($x);'],
    'new Expression nội suy' => ['$e = new Expression("a {$x}");'],
    'DB::connection()->select' => ['DB::connection()->select("a {$x}");'],
    '$this->connection->statement' => ['$this->connection->statement($x);'],
    '$database->statement' => ['$database->statement($x);'],
    'app(db)->statement' => ['app(\'db\')->statement($x);'],
    'DB::getFacadeRoot()->selectOne' => ['DB::getFacadeRoot()->selectOne($x);'],
    '?->whereRaw' => ['$q?->whereRaw($x);'],
    '->raw' => ['$conn->raw($x);'],
    'DB::selectOne' => ['DB::selectOne(request(\'q\'));'],
    'DB::affectingStatement' => ['DB::affectingStatement($x);'],
    'DB::scalar' => ['DB::scalar("select {$x}");'],
    'DB::cursor' => ['DB::cursor($x);'],
    'DB::statement nối biến' => ['DB::statement(\'update t set a = \'.$x);'],
    'hằng nối với lời gọi' => ['$q->whereRaw(self::A.foo());'],
    'hằng nối với biến' => ['$q->whereRaw(self::A.$x);'],
    'tên hằng là lời gọi' => ['$q->whereRaw(Foo::bar());'],
]);

it('bộ dò cờ mẫu SQL thô nguy hiểm', function (string $statement) {
    $scan = RawSqlScanner::scan("<?php\nclass A { function f() {\n{$statement}\n} }", 'app/Probe.php');

    expect($scan['sites'])->not->toBe([], "Không bị cờ: {$statement}");
})->with('flagged raw sql');

// ---------------------------------------------------------------------------------------------
// Ghim bộ dò: mẫu nào KHÔNG được cờ (hằng).
// ---------------------------------------------------------------------------------------------

dataset('constant raw sql', [
    'chuỗi + binding' => ['$q->whereRaw(\'a = ?\', [$x]);'],
    'nháy kép không nội suy' => ['$q->whereRaw("a = ?", [$x]);'],
    'chuỗi nối self::B' => ['$q->whereRaw(\'a\'.self::B);'],
    'Foo::BAR nối chuỗi' => ['$q->whereRaw(Foo::BAR.\' x\');'],
    'chuỗi nối hằng nối chuỗi' => ['$q->orderByRaw(\'a \'.self::DIR.\' b\');'],
    'selectRaw chuỗi hằng' => ['$q->selectRaw(\'COUNT(*) as total\');'],
    'DB::raw chuỗi hằng' => ['DB::raw(\'1\');'],
    'DB::select chuỗi + binding' => ['DB::select(\'select 1 where a = ?\', [$x]);'],
    'connection()->select chuỗi' => ['DB::connection()->select(\'select 1\');'],
    'new Expression chuỗi' => ['$e = new Expression(\'1\');'],
    'cursor() không đối số' => ['DB::cursor();'],
    'không phải sink (select cột trên builder)' => ['$q->select($columns);'],
    'update trên model' => ['$model->update($attributes);'],
]);

it('bộ dò không cờ mẫu SQL thô hằng', function (string $statement) {
    $scan = RawSqlScanner::scan("<?php\nclass A { function f() {\n{$statement}\n} }", 'app/Probe.php');

    expect($scan['sites'])->toBe([], "Bị cờ nhầm: {$statement}");
})->with('constant raw sql');

it('bộ dò ghi tên hàm vào khoá và cờ DB::unprepared riêng', function () {
    $scan = RawSqlScanner::scan("<?php\nclass A { function f() {\nDB::unprepared(\$x);\n\$q->orderByRaw(\$x);\n} }", 'app/Probe.php');

    expect(array_column($scan['sites'], 'key'))->toBe(['app/Probe.php:unprepared:$x', 'app/Probe.php:orderByRaw:$x'])
        ->and($scan['unprepared'])->toHaveCount(1);
});
