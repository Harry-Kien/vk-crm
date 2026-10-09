<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * `tools/coverage/summary.php` là cổng đo phủ của SPEC §11 / §14 mục 1 (`bin/coverage` gọi nó, và
 * PROGRESS "Nghiệm thu bản 1.0" dán số nó in). Một cổng như thế không được XANH khi nó không đo được
 * gì: báo cáo clover mang đường dẫn tuyệt đối của container (`/var/www/html/app/…`), nên chạy tay
 * tệp này từ một thư mục khác thì không tệp nào khớp tiền tố, và trước đây nó in "0 / 0 = 100.00%"
 * rồi thoát 0. Test chạy đúng tệp đó bằng PHP dòng lệnh, trên một báo cáo clover tự dựng.
 *
 * Hàm toàn cục mang tiền tố `cvs…`.
 */

/**
 * Một báo cáo clover nhỏ: mỗi phần tử của `$files` là [đường dẫn, số dòng lệnh, số dòng đã phủ].
 *
 * @param  list<array{0: string, 1: int, 2: int}>  $files
 */
function cvsClover(array $files): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?><coverage><project>';

    foreach ($files as [$name, $statements, $covered]) {
        $xml .= '<file name="'.$name.'">';

        for ($line = 1; $line <= $statements; $line++) {
            $xml .= '<line num="'.$line.'" type="stmt" count="'.($line <= $covered ? 1 : 0).'"/>';
        }

        $xml .= '</file>';
    }

    $path = sys_get_temp_dir().'/cvs-'.uniqid().'.xml';
    File::put($path, $xml.'</project></coverage>');

    return $path;
}

/** Chạy tệp tóm tắt; `$cwd` khác gốc kho mô phỏng một lần chạy tay từ thư mục khác. */
function cvsRun(string $clover, ?string $cwd = null): ProcessResult
{
    return Process::path($cwd ?? base_path())->run([PHP_BINARY, base_path('tools/coverage/summary.php'), $clover]);
}

it('passes and prints the two percentages when both directories are measured and above 80%', function () {
    $clover = cvsClover([
        [base_path('app/Actions/A.php'), 10, 9],
        [base_path('app/Policies/P.php'), 10, 8],
    ]);

    $result = cvsRun($clover);

    expect($result->exitCode())->toBe(0)
        ->and($result->output())->toContain('90.00%')
        ->and($result->output())->toContain('80.00%');
});

it('fails when a directory is below 80%', function () {
    $result = cvsRun(cvsClover([
        [base_path('app/Actions/A.php'), 10, 7],
        [base_path('app/Policies/P.php'), 10, 10],
    ]));

    expect($result->exitCode())->toBe(1);
});

it('reads a report written inside the container even when run from another directory', function () {
    // Báo cáo do bin/coverage viết mang đường dẫn của container; lần chạy tay này đứng ở thư mục tạm.
    $result = cvsRun(cvsClover([
        ['/var/www/html/app/Actions/A.php', 10, 9],
        ['/var/www/html/app/Policies/P.php', 4, 4],
    ]), sys_get_temp_dir());

    expect($result->exitCode())->toBe(0)
        ->and($result->output())->toContain('9 /     10')
        ->and($result->output())->toContain('4 /      4');
});

it('fails instead of reporting 100% when a directory matched no statement at all', function () {
    $result = cvsRun(cvsClover([
        ['/somewhere/else/app/Actions/A.php', 10, 10],
        ['/somewhere/else/app/Policies/P.php', 10, 10],
    ]));

    expect($result->exitCode())->toBe(1)
        ->and($result->output().$result->errorOutput())->toContain('không khớp dòng lệnh nào')
        ->and($result->output())->not->toContain('100.00%');
});
