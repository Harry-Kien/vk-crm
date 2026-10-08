<?php

/*
 * Đọc một báo cáo clover (PHPUnit `--coverage-clover`) và in độ phủ theo dòng lệnh của `app/Actions/`,
 * `app/Policies/` và cả `app/` — đúng hai thư mục SPEC §11 và §14 mục 1 đòi ≥ 80% — rồi các tệp còn
 * nhiều dòng chưa phủ nhất của hai thư mục đó. `bin/coverage` gọi tệp này; chạy tay được:
 *
 *     php tools/coverage/summary.php storage/logs/coverage-clover.xml [số tệp mỗi thư mục, mặc định 15]
 *
 * "Dòng lệnh" là phần tử `<line type="stmt">` của clover (pcov và Xdebug cùng đếm thế); một dòng là
 * đã phủ khi `count > 0`. Mã thoát 1 khi `app/Actions/` hoặc `app/Policies/` dưới 80%.
 */
$path = $argv[1] ?? 'storage/logs/coverage-clover.xml';
$top = (int) ($argv[2] ?? 15);

if (! is_file($path)) {
    fwrite(STDERR, "Không thấy {$path} — chạy bin/coverage trước.\n");
    exit(2);
}

$xml = simplexml_load_file($path);
$root = rtrim((string) getcwd(), '/').'/';
$targets = ['app/Actions/' => [0, 0], 'app/Policies/' => [0, 0], 'app/' => [0, 0]];
$files = [];

foreach ($xml->xpath('//file') as $file) {
    $name = str_starts_with((string) $file['name'], $root) ? substr((string) $file['name'], strlen($root)) : (string) $file['name'];
    $statements = 0;
    $covered = 0;

    foreach ($file->line as $line) {
        if ((string) $line['type'] === 'stmt') {
            $statements++;
            $covered += (int) $line['count'] > 0 ? 1 : 0;
        }
    }

    $files[] = [$name, $statements, $covered];

    foreach (array_keys($targets) as $prefix) {
        if (str_starts_with($name, $prefix)) {
            $targets[$prefix][0] += $statements;
            $targets[$prefix][1] += $covered;
        }
    }
}

$percent = fn (int $statements, int $covered): float => $statements === 0 ? 100.0 : 100 * $covered / $statements;
$failed = false;

foreach ($targets as $prefix => [$statements, $covered]) {
    $value = $percent($statements, $covered);
    printf("%-14s %6d / %6d dòng lệnh = %6.2f%%\n", $prefix, $covered, $statements, $value);
    $failed = $failed || ($prefix !== 'app/' && $value < 80);
}

foreach (['app/Actions/', 'app/Policies/'] as $prefix) {
    echo "\nNhiều dòng chưa phủ nhất trong {$prefix}:\n";
    $inside = array_values(array_filter($files, fn (array $f): bool => str_starts_with($f[0], $prefix)));
    usort($inside, fn (array $a, array $b): int => ($b[1] - $b[2]) <=> ($a[1] - $a[2]));

    foreach (array_slice($inside, 0, $top) as [$name, $statements, $covered]) {
        printf("%5d chưa phủ  %4d/%-4d (%3.0f%%)  %s\n", $statements - $covered, $covered, $statements, $percent($statements, $covered), $name);
    }
}

exit($failed ? 1 : 0);
