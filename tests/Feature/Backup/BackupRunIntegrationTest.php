<?php

use App\Enums\OutboundStatus;
use App\Models\OutboundMessage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\Config\Config;
use Spatie\Backup\Notifications\EventHandler;

/*
|--------------------------------------------------------------------------
| §10.8 — backup:run THẬT, ra nhiều disk, một disk hỏng, archive mã hoá
|--------------------------------------------------------------------------
|
| Chạy `--only-files`, bỏ qua bước dump CSDL: image dev (`webdevops/php:8.3-alpine`) không có
| `mariadb-dump`, `mysqldump`, LẪN `sqlite3` (Spatie\DbDumper\Databases\Sqlite::dumpToFile() vẫn
| gọi ra tiến trình `sqlite3` ngoài, không tự dump bằng PHP) — xem
| `docs/research/2026-09-26-sao-luu.md` mục 3. Cấu hình nguồn CSDL (`env('DB_CONNECTION')`) đã
| kiểm ở `BackupConfigTest`; phần Task 1 chịu trách nhiệm ở ĐÂY là nhiều đích, xử lý một disk
| hỏng, và mã hoá — cả ba xảy ra SAU bước dump, trên archive chứa tệp.
|
| MỖI TEST DÙNG TÊN DISK RIÊNG (không tái dùng `disk_a`/`disk_b` giữa các `it()`):
| `Illuminate\Filesystem\FilesystemManager` là một singleton SỐNG QUA CẢ TIẾN TRÌNH test, không
| bị `RefreshDatabase` reset. `Storage::fake('disk_b')` ở một test trước CACHE một driver còn
| hoạt động dưới tên `disk_b`; nếu test sau ghi đè `config('filesystems.disks.disk_b')` bằng một
| root hỏng, driver ĐÃ CACHE (còn tốt) vẫn được dùng — disk "hỏng" thực ra không hỏng, và bài test
| tưởng đang kiểm tra một disk lỗi lại đang kiểm tra một disk lành. Đo được: gộp chung tên disk
| giữa hai `it()` làm bài "một disk hỏng" xanh giả (không có `BackupHasFailed` nào được bắn, vì
| Flysystem coi driver cache là còn dùng được).
*/

function rebindBackup(): void
{
    Config::rebind();
}

beforeEach(function () {
    // `Spatie\Backup\Notifications\EventHandler::$enabled` là một cờ STATIC, sống qua CẢ TIẾN
    // TRÌNH test — `--disable-notifications` (dùng ở hai test khác trong tệp này) gọi
    // `EventHandler::disable()` và KHÔNG BAO GIỜ tự bật lại. Không có dòng này, bài "một disk
    // hỏng" chạy SAU một bài `--disable-notifications` sẽ không thấy `BackupHasFailedNotification`
    // nào được gửi — không phải vì code sai, mà vì cờ toàn cục của gói còn tắt từ test trước. Đo
    // được: bỏ dòng này, bài "một disk hỏng" đỏ với "No query results for model
    // [OutboundMessage]" khi chạy CHUNG file (nhưng xanh khi chạy riêng bằng --filter).
    EventHandler::enable();
});

afterEach(function () {
    if (isset($GLOBALS['__vkcrm_broken_disk_root_file']) && file_exists($GLOBALS['__vkcrm_broken_disk_root_file'])) {
        @unlink($GLOBALS['__vkcrm_broken_disk_root_file']);
        unset($GLOBALS['__vkcrm_broken_disk_root_file']);
    }

    // Bật lại sau CẢ tệp, không chỉ trước — một tệp khác chạy sau trong cùng worker (ví dụ
    // `BackupCleanupTest.php`) cũng gọi `--disable-notifications` và cũng cần cờ này ở trạng thái
    // bật khi NÓ bắt đầu; hai lớp phòng thủ (bật trước ở đây, bật trước ở tệp kia) không thừa,
    // vì thứ tự tệp trong một worker `--parallel` không do ta chọn.
    EventHandler::enable();
});

it('§10.8 một lượt sao lưu ra hai disk giả lập, cả hai đều có đúng một archive', function () {
    Storage::fake('backup_test_disk_1');
    Storage::fake('backup_test_disk_2');

    config(['backup.backup.destination.disks' => ['backup_test_disk_1', 'backup_test_disk_2']]);
    rebindBackup();

    $exitCode = Artisan::call('backup:run', ['--only-files' => true, '--disable-notifications' => true]);

    $name = config('backup.backup.name');

    expect($exitCode)->toBe(0)
        ->and(Storage::disk('backup_test_disk_1')->allFiles($name))->toHaveCount(1)
        ->and(Storage::disk('backup_test_disk_2')->allFiles($name))->toHaveCount(1)
        ->and(Storage::disk('backup_test_disk_1')->allFiles($name)[0])->toEndWith('.zip');
});

it('§10.8 một disk hỏng không chặn disk còn lại; thư báo lỗi nêu đúng disk hỏng', function () {
    config(['queue.default' => 'sync']);

    Storage::fake('backup_test_disk_3');

    // Root của disk hỏng nằm DƯỚI MỘT TỆP (không phải thư mục) — Flysystem không thể tạo thư mục
    // ở đó dù chạy với quyền root trong container, khác kiểu lỗi "chmod 000" (root bỏ qua quyền).
    $brokenRootFile = sys_get_temp_dir().'/vkcrm-broken-disk-'.uniqid();
    file_put_contents($brokenRootFile, 'không phải thư mục');
    $GLOBALS['__vkcrm_broken_disk_root_file'] = $brokenRootFile;

    config(['filesystems.disks.backup_test_disk_broken' => [
        'driver' => 'local',
        'root' => $brokenRootFile.'/khong-the-tao-thu-muc',
        'throw' => false,
    ]]);

    config([
        'backup.backup.destination.disks' => ['backup_test_disk_3', 'backup_test_disk_broken'],
        'backup.backup.destination.continue_on_failure' => true,
        'vkcrm.backup.notify_email' => 'ops@luatvukhang.com',
    ]);
    rebindBackup();

    Artisan::call('backup:run', ['--only-files' => true]);

    $name = config('backup.backup.name');

    // Disk lành vẫn nhận đúng một archive dù disk kia hỏng.
    expect(Storage::disk('backup_test_disk_3')->allFiles($name))->toHaveCount(1);

    $row = OutboundMessage::query()->sole();

    expect($row->status)->toBe(OutboundStatus::Sent)
        ->and($row->template)->toBe('staff.backup_alert.backup_failed')
        ->and($row->payload['subject'] ?? null)->toContain('backup_test_disk_broken');
});

it('§10.8 archive mã hoá: không mật khẩu không đọc được nội dung, có mật khẩu đọc được', function () {
    Storage::fake('backup_test_disk_4');

    config([
        'backup.backup.destination.disks' => ['backup_test_disk_4'],
        'backup.backup.password' => 'mat-khau-thu-nghiem-khong-dung-that',
    ]);
    rebindBackup();

    Artisan::call('backup:run', ['--only-files' => true, '--disable-notifications' => true]);

    $name = config('backup.backup.name');
    $files = Storage::disk('backup_test_disk_4')->allFiles($name);
    expect($files)->toHaveCount(1);

    $absolutePath = Storage::disk('backup_test_disk_4')->path($files[0]);

    $zip = new ZipArchive;
    expect($zip->open($absolutePath))->toBe(true);

    $entryName = $zip->getNameIndex(0);
    expect($entryName)->not->toBeFalse();

    // Không đặt mật khẩu: đọc nội dung tệp bên trong THẤT BẠI.
    expect(@$zip->getFromName($entryName))->toBeFalse();

    // Đặt đúng mật khẩu: đọc được.
    $zip->setPassword('mat-khau-thu-nghiem-khong-dung-that');
    expect($zip->getFromName($entryName))->not->toBeFalse();

    $zip->close();
});
