<?php

use App\Actions\Backup\PruneRcloneRemoteBackups;
use App\Exceptions\RcloneCommandFailed;
use Illuminate\Support\Facades\Process;

/*
|--------------------------------------------------------------------------
| §10.8 — dọn remote rclone xuống còn 30 bản mới nhất (SPEC §10 mục 8; M8a Task 2, Ruling 1)
|--------------------------------------------------------------------------
|
| Dọn THEO SỐ LƯỢNG (không theo tuổi), trong ĐÚNG thư mục của môi trường (`{remote}/{slug}`), và
| MỚI NHẤT được tính bằng MỐC THỜI GIAN TRONG TÊN TỆP (`{prefix}Y-m-d-H-i-s.zip`, đúng định dạng
| `Spatie\Backup\Tasks\Backup\BackupJob::FILENAME_FORMAT`) — KHÔNG phải `ModTime` của Google Drive
| (fix I3, lượt rà soát cuối M8a): một archive cũ được tải lên lại (khôi phục từ thùng rác, chép
| tay) mang `ModTime` MỚI và trước fix này chiếm chỗ của một bản thật sự mới. Mảng lsjson giả lập ở
| đây cố tình liệt kê KHÔNG theo thứ tự và mang `ModTime` ngược với tên, để một cài đặt lỡ tin vào
| thứ tự trả về hay vào `ModTime` bị bắt lỗi ngay.
|
| `RcloneProcess` LUÔN gọi `Process::run()` với một MẢNG lệnh (không phải chuỗi), nên
| `$process->command` trong callback `Process::fake()` là một `array<string>` — dùng `in_array()`,
| KHÔNG `str_contains()` (Laravel docs minh hoạ bằng lệnh CHUỖI, không áp dụng ở đây).
|
| Đặt tường minh `filename_prefix` = "vk-crm-" (không dựa vào APP_NAME của môi trường test) để bộ
| lọc tên archive có một giá trị cố định, không lệ thuộc `.env` của máy chạy test. Đồng hồ bị
| đóng băng để tên dựng lúc chuẩn bị và tên dựng lúc kiểm luôn trùng nhau đến từng giây.
*/

const PRUNE_TEST_FOLDER = 'gdrive:VK-CRM-backups/vk-crm';

beforeEach(function () {
    $this->freezeTime();
    config(['backup.backup.destination.filename_prefix' => 'vk-crm-']);
});

/** Tên archive thật của một bản sao lưu tạo cách đây `$ageInMinutes` phút. */
function archiveNameForAge(int $ageInMinutes, string $prefix = 'vk-crm-'): string
{
    return $prefix.now()->copy()->subMinutes($ageInMinutes)->format('Y-m-d-H-i-s').'.zip';
}

/**
 * @param  list<int>  $ages  tuổi tính bằng phút, phần tử đầu KHÔNG nhất thiết mới nhất
 *
 * `ModTime` cố ý NGƯỢC với tên: bản càng cũ theo tên càng mang `ModTime` mới — mọi cài đặt còn sắp
 * theo `ModTime` sẽ xoá nhầm những bản mới nhất.
 */
function fakeRemoteEntries(array $ages): array
{
    return array_map(
        fn (int $age) => [
            'Name' => archiveNameForAge($age),
            'Size' => 1000,
            'ModTime' => now()->copy()->subMinutes(1000 - $age)->toIso8601String(),
            'IsDir' => false,
        ],
        $ages,
    );
}

/** Một tệp KHÔNG khớp đúng hình dạng archive của môi trường này. */
function fakeForeignEntry(string $name, int $ageInDays = 1): array
{
    return [
        'Name' => $name,
        'Size' => 500,
        'ModTime' => now()->copy()->subDays($ageInDays)->toIso8601String(),
        'IsDir' => false,
    ];
}

/** Giả `lsjson` trả `$entries`, ghi đích của mọi `deletefile` vào `$deleted`. */
function fakeRcloneListingAndRecordDeletes(array $entries, array &$deleted): void
{
    Process::fake(function ($process) use ($entries, &$deleted) {
        if (in_array('lsjson', $process->command, true)) {
            return Process::result(output: json_encode($entries));
        }

        if (in_array('deletefile', $process->command, true)) {
            $deleted[] = end($process->command);

            return Process::result(exitCode: 0);
        }

        return Process::result(exitCode: 1, errorOutput: 'lệnh không mong đợi: '.implode(' ', $process->command));
    });
}

it('§10.8 không xoá gì khi remote có đúng 30 bản trở xuống; chỉ liệt kê đúng thư mục môi trường', function () {
    $deleted = [];
    fakeRcloneListingAndRecordDeletes(fakeRemoteEntries(range(30, 1)), $deleted);

    app(PruneRcloneRemoteBackups::class)->handle(PRUNE_TEST_FOLDER);

    expect($deleted)->toBe([]);
    Process::assertRan(fn ($process) => $process->command === ['rclone', 'lsjson', PRUNE_TEST_FOLDER]);
});

it('§10.8 xoá đúng các bản CŨ HƠN theo mốc trong TÊN tệp, giữ lại 30 bản mới nhất', function () {
    // 33 bản, tuổi 1..33 phút, xáo trộn thứ tự liệt kê — 3 bản CŨ NHẤT (31, 32, 33) phải bị xoá.
    $ages = [20, 5, 33, 1, 15, 31, 3, 32, 10, 25, 2, 30, 4, 6, 7, 8, 9, 11, 12, 13, 14, 16, 17, 18, 19, 21, 22, 23, 24, 26, 27, 28, 29];
    expect($ages)->toHaveCount(33);

    $deleted = [];
    fakeRcloneListingAndRecordDeletes(fakeRemoteEntries($ages), $deleted);

    app(PruneRcloneRemoteBackups::class)->handle(PRUNE_TEST_FOLDER);

    expect($deleted)->toEqualCanonicalizing(array_map(
        fn (int $age) => PRUNE_TEST_FOLDER.'/'.archiveNameForAge($age),
        [31, 32, 33],
    ));
});

it('§10.8 fix I3 — một archive CŨ được tải lên lại (ModTime mới tinh) vẫn bị dọn theo tên', function () {
    // 30 bản gần đây (tuổi 1..30 phút, ModTime khớp tên) + một bản cũ 40 ngày mà ai đó vừa khôi
    // phục từ thùng rác Google Drive: ModTime của nó là BÂY GIỜ, mới hơn mọi bản khác. Sắp theo
    // ModTime thì nó được giữ và bản 30 phút tuổi bị xoá; sắp theo tên thì nó là bản cũ nhất.
    $restoredOld = [
        'Name' => 'vk-crm-'.now()->copy()->subDays(40)->format('Y-m-d-H-i-s').'.zip',
        'Size' => 1000,
        'ModTime' => now()->toIso8601String(),
        'IsDir' => false,
    ];

    $entries = array_map(fn (int $age) => [
        'Name' => archiveNameForAge($age),
        'Size' => 1000,
        'ModTime' => now()->copy()->subMinutes($age)->toIso8601String(),
        'IsDir' => false,
    ], range(1, 30));
    $entries[] = $restoredOld;

    $deleted = [];
    fakeRcloneListingAndRecordDeletes($entries, $deleted);

    app(PruneRcloneRemoteBackups::class)->handle(PRUNE_TEST_FOLDER);

    expect($deleted)->toBe([PRUNE_TEST_FOLDER.'/'.$restoredOld['Name']]);
});

it('§10.8 fix I3 — archive của môi trường KHÁC (staging) trong cùng thư mục không bao giờ bị đếm hay bị xoá', function () {
    // 30 bản production + 5 bản staging CŨ HƠN, cùng nằm trong một thư mục (ví dụ bố trí phẳng cũ,
    // trước khi mỗi môi trường có thư mục riêng). Tiền tố `vk-crm-staging-` BẮT ĐẦU bằng `vk-crm-`
    // — trước fix này, bộ lọc "bắt đầu bằng tiền tố, kết thúc bằng .zip" đếm chúng là archive
    // production, vượt 30, rồi xoá.
    $entries = array_merge(
        fakeRemoteEntries(range(1, 30)),
        array_map(fn (int $days) => fakeForeignEntry(archiveNameForAge($days * 1440, 'vk-crm-staging-'), $days), range(40, 44)),
    );

    $deleted = [];
    fakeRcloneListingAndRecordDeletes($entries, $deleted);

    app(PruneRcloneRemoteBackups::class)->handle(PRUNE_TEST_FOLDER);

    expect($deleted)->toBe([]);
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

    expect(fn () => app(PruneRcloneRemoteBackups::class)->handle(PRUNE_TEST_FOLDER))
        ->toThrow(RcloneCommandFailed::class);
});

it('§10.8 tệp lạ (ghi chú văn phòng, tệp thử sót lại, tên gần giống archive) KHÔNG BAO GIỜ bị đếm hay bị xoá', function () {
    // 33 archive thật (3 bản cũ nhất phải bị xoá) + các tệp KHÔNG khớp ĐÚNG hình dạng archive.
    $entries = array_merge(
        fakeRemoteEntries(range(1, 33)),
        [
            fakeForeignEntry('ghi-chu-cua-van-phong.pdf', ageInDays: 1),
            fakeForeignEntry('vkcrm-backup-check-legacy.txt', ageInDays: 10),
            // Đúng tiền tố, KHÔNG kết thúc bằng ".zip".
            fakeForeignEntry('vk-crm-ghi-chu-khong-phai-zip.txt', ageInDays: 60),
            // Đúng tiền tố và ".zip", nhưng phần giữa không phải mốc thời gian của gói.
            fakeForeignEntry('vk-crm-ban-chep-tay.zip', ageInDays: 90),
        ],
    );

    $deleted = [];
    fakeRcloneListingAndRecordDeletes($entries, $deleted);

    app(PruneRcloneRemoteBackups::class)->handle(PRUNE_TEST_FOLDER);

    expect($deleted)->toEqualCanonicalizing(array_map(
        fn (int $age) => PRUNE_TEST_FOLDER.'/'.archiveNameForAge($age),
        [31, 32, 33],
    ));
});
