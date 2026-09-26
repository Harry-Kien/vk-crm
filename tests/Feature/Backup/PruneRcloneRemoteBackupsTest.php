<?php

use App\Actions\Backup\PruneRcloneRemoteBackups;
use App\Exceptions\RcloneCommandFailed;
use Illuminate\Support\Facades\Process;

/*
|--------------------------------------------------------------------------
| §10.8 — dọn remote rclone xuống còn 30 bản mới nhất (SPEC §10 mục 8; M8a Task 2, Ruling 1)
|--------------------------------------------------------------------------
|
| Dọn THEO SỐ LƯỢNG (không theo tuổi), và MỚI NHẤT được tính bằng `ModTime` của `rclone lsjson`,
| KHÔNG phải theo tên tệp — mảng lsjson giả lập ở đây cố tình liệt kê KHÔNG theo thứ tự thời gian,
| để một cài đặt lỡ tin vào thứ tự trả về của rclone (thay vì tự sắp theo ModTime) bị bắt lỗi ngay.
|
| `RcloneProcess` LUÔN gọi `Process::run()` với một MẢNG lệnh (không phải chuỗi), nên
| `$process->command` trong callback `Process::fake()` là một `array<string>` — dùng `in_array()`,
| KHÔNG `str_contains()` (Laravel docs minh hoạ bằng lệnh CHUỖI, không áp dụng ở đây).
|
| Đặt tường minh `filename_prefix` = "vk-crm-" (không dựa vào APP_NAME của môi trường test) để bộ
| lọc "chỉ tính đúng tệp mang hình dạng archive" (fix I1, vòng rà soát 1) có một giá trị cố định,
| không lệ thuộc `.env` của máy chạy test.
*/

beforeEach(fn () => config(['backup.backup.destination.filename_prefix' => 'vk-crm-']));

/** @param  list<int>  $ages tuổi tính bằng phút, phần tử đầu KHÔNG nhất thiết mới nhất */
function fakeRemoteEntries(array $ages): array
{
    return array_map(
        fn (int $age) => [
            'Name' => "vk-crm-fake-age-{$age}.zip",
            'Size' => 1000,
            'ModTime' => now()->copy()->subMinutes($age)->toIso8601String(),
            'IsDir' => false,
        ],
        $ages,
    );
}

/** Một tệp KHÔNG khớp hình dạng archive — văn phòng có thể tự tay bỏ vào cùng thư mục remote. */
function fakeForeignEntry(string $name, int $ageInDays = 1): array
{
    return [
        'Name' => $name,
        'Size' => 500,
        'ModTime' => now()->copy()->subDays($ageInDays)->toIso8601String(),
        'IsDir' => false,
    ];
}

it('§10.8 không xoá gì khi remote có đúng 30 bản trở xuống', function () {
    $entries = fakeRemoteEntries(range(30, 1)); // cố ý KHÔNG theo thứ tự tăng dần tuổi

    Process::fake(function ($process) use ($entries) {
        return in_array('lsjson', $process->command, true)
            ? Process::result(output: json_encode($entries))
            : Process::result(exitCode: 1, errorOutput: 'không nên gọi deletefile ở bài này');
    });

    app(PruneRcloneRemoteBackups::class)->handle('gdrive:VK-CRM-backups');

    Process::assertNotRan(fn ($process) => in_array('deletefile', $process->command, true));
});

it('§10.8 xoá đúng các bản CŨ HƠN theo ModTime, giữ lại 30 bản mới nhất', function () {
    // 33 bản, tuổi 1..33 phút, xáo trộn thứ tự liệt kê — 3 bản CŨ NHẤT (31, 32, 33) phải bị xoá.
    $ages = [20, 5, 33, 1, 15, 31, 3, 32, 10, 25, 2, 30, 4, 6, 7, 8, 9, 11, 12, 13, 14, 16, 17, 18, 19, 21, 22, 23, 24, 26, 27, 28, 29];
    expect($ages)->toHaveCount(33);

    $entries = fakeRemoteEntries($ages);
    $deleted = [];

    Process::fake(function ($process) use ($entries, &$deleted) {
        if (in_array('lsjson', $process->command, true)) {
            return Process::result(output: json_encode($entries));
        }

        if (in_array('deletefile', $process->command, true)) {
            $deleted[] = $process->command;

            return Process::result(exitCode: 0);
        }

        return Process::result(exitCode: 1, errorOutput: 'lệnh không mong đợi: '.implode(' ', $process->command));
    });

    app(PruneRcloneRemoteBackups::class)->handle('gdrive:VK-CRM-backups');

    expect($deleted)->toHaveCount(3);

    $deletedTargets = array_map(fn (array $cmd) => end($cmd), $deleted);

    foreach ([31, 32, 33] as $age) {
        expect(collect($deletedTargets)->contains(fn (string $target) => str_contains($target, "vk-crm-fake-age-{$age}.zip")))->toBeTrue();
    }
    foreach ([29, 30, 1] as $age) {
        expect(collect($deletedTargets)->contains(fn (string $target) => str_contains($target, "vk-crm-fake-age-{$age}.zip")))->toBeFalse();
    }
});

it('§10.8 một deletefile hỏng ném RcloneCommandFailed, không nuốt lỗi', function () {
    $entries = fakeRemoteEntries(range(1, 31));

    Process::fake(function ($process) use ($entries) {
        if (in_array('lsjson', $process->command, true)) {
            return Process::result(output: json_encode($entries));
        }

        if (in_array('deletefile', $process->command, true)) {
            return Process::result(exitCode: 1, errorOutput: 'remote treo');
        }

        return Process::result(exitCode: 1);
    });

    expect(fn () => app(PruneRcloneRemoteBackups::class)->handle('gdrive:VK-CRM-backups'))
        ->toThrow(RcloneCommandFailed::class);
});

it('§10.8 fix I1 — tệp lạ (ghi chú văn phòng, tệp thử sót lại) KHÔNG BAO GIỜ bị đếm hay bị xoá', function () {
    // 33 archive thật (3 bản cũ nhất phải bị xoá) + hai tệp KHÔNG khớp hình dạng archive, một
    // trong hai còn mang đúng tên kiểu tệp thử của CheckBackupDestinations (fix I1 khác đã chuyển
    // tệp thử sang thư mục .backup-check riêng — dòng dưới đây là lớp phòng thủ THỨ HAI, độc lập,
    // phòng trường hợp một tệp thử CŨ từ trước khi có fix đó vẫn còn sót ở gốc).
    $ages = range(1, 33);
    $entries = array_merge(
        fakeRemoteEntries($ages),
        [
            fakeForeignEntry('ghi-chu-cua-van-phong.pdf', ageInDays: 1),
            fakeForeignEntry('vkcrm-backup-check-legacy.txt', ageInDays: 10),
            // Đúng tiền tố archive, nhưng KHÔNG kết thúc bằng ".zip" — vẫn phải sống sót. Không có
            // tệp này, một cài đặt chỉ kiểm tiền tố (bỏ sót điều kiện đuôi ".zip") vẫn xanh giả.
            fakeForeignEntry('vk-crm-ghi-chu-khong-phai-zip.txt', ageInDays: 60),
        ],
    );
    $deleted = [];

    Process::fake(function ($process) use ($entries, &$deleted) {
        if (in_array('lsjson', $process->command, true)) {
            return Process::result(output: json_encode($entries));
        }

        if (in_array('deletefile', $process->command, true)) {
            $deleted[] = end($process->command);

            return Process::result(exitCode: 0);
        }

        return Process::result(exitCode: 1, errorOutput: 'lệnh không mong đợi');
    });

    app(PruneRcloneRemoteBackups::class)->handle('gdrive:VK-CRM-backups');

    // Đúng 3 archive cũ nhất (31, 32, 33) bị xoá — không hơn, không kém.
    expect($deleted)->toHaveCount(3);
    foreach ([31, 32, 33] as $age) {
        expect(collect($deleted)->contains(fn (string $t) => str_contains($t, "vk-crm-fake-age-{$age}.zip")))->toBeTrue();
    }

    // Ba tệp lạ sống sót, dù cái cũ nhất (60 ngày) cũ hơn CẢ BA archive vừa bị xoá.
    expect(collect($deleted)->contains(fn (string $t) => str_contains($t, 'ghi-chu-cua-van-phong.pdf')))->toBeFalse()
        ->and(collect($deleted)->contains(fn (string $t) => str_contains($t, 'vkcrm-backup-check-legacy.txt')))->toBeFalse()
        ->and(collect($deleted)->contains(fn (string $t) => str_contains($t, 'vk-crm-ghi-chu-khong-phai-zip.txt')))->toBeFalse();
});
