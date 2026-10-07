<?php

use App\Exceptions\RcloneCommandFailed;
use App\Support\Backup\RcloneProcess;
use Illuminate\Support\Facades\Process;

/*
|--------------------------------------------------------------------------
| M14 Task 7 — `RcloneProcess::cat()` và thời gian chờ riêng cho `listJson()`
|--------------------------------------------------------------------------
|
| Lượt nhập biên nhận văn phòng chỉ đọc một thư mục nhỏ và vài tệp JSON: 120 giây là đủ, và một
| `rclone` treo không được giữ lượt 07:00 tới 30 phút (mặc định 1800 của sao lưu). Các nơi gọi cũ của
| M8a không truyền tham số và vẫn giữ mặc định.
*/

beforeEach(function () {
    config(['vkcrm.backup.rclone.binary' => 'rclone', 'vkcrm.backup.rclone.config_path' => null, 'vkcrm.backup.rclone.timeout' => 1800]);
});

it('cat() chạy "rclone cat <đường>" và trả nội dung nguyên văn', function () {
    // `Process::result()` giả luôn kết thúc đầu ra bằng một "\n" (FakeProcessResult::normalizeOutput),
    // nên nội dung giả đã có sẵn dấu xuống dòng đó: cat() phải trả đúng từng byte, không cắt gì.
    Process::fake(fn () => Process::result(output: "{\"format\":1}\n"));

    expect(RcloneProcess::cat('gdrive:a/b.json', 120))->toBe("{\"format\":1}\n");

    Process::assertRan(fn ($process) => $process->command === ['rclone', 'cat', 'gdrive:a/b.json'] && $process->timeout === 120);
});

it('cat() không truyền thời gian chờ thì dùng mặc định của sao lưu', function () {
    Process::fake(fn () => Process::result(output: ''));

    RcloneProcess::cat('gdrive:a/b.json');

    Process::assertRan(fn ($process) => $process->timeout === 1800);
});

it('cat() lỗi → RcloneCommandFailed', function () {
    Process::fake(fn () => Process::result(errorOutput: 'not found', exitCode: 3));

    expect(fn () => RcloneProcess::cat('gdrive:a/b.json', 120))->toThrow(RcloneCommandFailed::class);
});

it('listJson() nhận thời gian chờ riêng; không truyền thì giữ mặc định cho các nơi gọi của M8a', function () {
    Process::fake(fn () => Process::result(output: '[]'));

    RcloneProcess::listJson('gdrive:a', 120);
    RcloneProcess::listJson('gdrive:b');

    Process::assertRan(fn ($process) => $process->command === ['rclone', 'lsjson', 'gdrive:a'] && $process->timeout === 120);
    Process::assertRan(fn ($process) => $process->command === ['rclone', 'lsjson', 'gdrive:b'] && $process->timeout === 1800);
});

it('cat() mang --config khi đã cấu hình', function () {
    config(['vkcrm.backup.rclone.config_path' => '/etc/rclone.conf']);
    Process::fake(fn () => Process::result(output: ''));

    RcloneProcess::cat('gdrive:a/b.json', 120);

    Process::assertRan(fn ($process) => $process->command === ['rclone', '--config', '/etc/rclone.conf', 'cat', 'gdrive:a/b.json']);
});
