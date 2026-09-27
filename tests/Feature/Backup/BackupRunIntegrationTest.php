<?php

use App\Enums\OutboundStatus;
use App\Models\OutboundMessage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Config as FlysystemConfig;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToWriteFile;
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
| `docs/research/2026-09-26-sao-luu.md` mục 3. Lượt sao lưu THẬT có dump CSDL nằm ở
| `BackupDatabaseDumpTest` (chạy bằng `m8-dev test:dump`); phần kiểm ở ĐÂY là nhiều đích, xử lý
| một disk hỏng, và mã hoá — cả ba xảy ra SAU bước dump, trên archive chứa tệp.
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
    // Thư mục tạm RIÊNG cho tiến trình test này — xem docblock `backupTemporaryTestDirectory()`
    // ở `tests/Pest.php` ("backup-temp parallel race"). Không có dòng này, tệp này đua chung một
    // `storage_path('app/backup-temp')` với ba tệp Backup khác khi chạy `--parallel`.
    config(['backup.backup.temporary_directory' => backupTemporaryTestDirectory()]);

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
    if (isset($GLOBALS['__vkcrm_write_fails_disk_root'])) {
        File::deleteDirectory($GLOBALS['__vkcrm_write_fails_disk_root']);
        unset($GLOBALS['__vkcrm_write_fails_disk_root']);
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

it('§10.8 một disk hỏng khi GHI (đứng đầu danh sách) không chặn disk sau nó; thư báo lỗi nêu đúng disk hỏng', function () {
    config(['queue.default' => 'sync']);

    Storage::fake('backup_test_disk_3');

    // Disk hỏng đúng kiểu brief yêu cầu: KẾT NỐI ĐƯỢC (`BackupDestination::isReachable()` liệt kê
    // thư mục thành công) nhưng NÉM LỖI KHI GHI archive — như Google Drive hết hạn mức, hay một
    // ổ mạng chỉ còn quyền đọc. Và nó đứng ĐẦU danh sách: nếu lượt sao lưu dừng ở disk hỏng đầu
    // tiên (`continue_on_failure = false`), disk lành phía sau không bao giờ được ghi, và câu
    // kiểm "disk lành vẫn nhận archive" bên dưới đỏ (fix I4 — bản trước để disk lành đứng đầu,
    // nên xanh cả khi cờ này tắt). Test KHÔNG tự đặt `continue_on_failure`: nó chạy với giá trị
    // thật trong `config/backup.php`, nên đổi giá trị đó sang `false` cũng làm test này đỏ.
    $root = sys_get_temp_dir().'/vkcrm-write-fails-disk-'.uniqid();
    File::ensureDirectoryExists($root);
    $GLOBALS['__vkcrm_write_fails_disk_root'] = $root;

    Storage::extend('vkcrm_write_fails', function ($app, array $config) {
        $adapter = new class($config['root']) extends LocalFilesystemAdapter
        {
            public function write(string $path, string $contents, FlysystemConfig $config): void
            {
                throw UnableToWriteFile::atLocation($path, 'disk giả lập hỏng khi ghi');
            }

            public function writeStream(string $path, $contents, FlysystemConfig $config): void
            {
                throw UnableToWriteFile::atLocation($path, 'disk giả lập hỏng khi ghi');
            }
        };

        return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
    });

    config(['filesystems.disks.backup_test_disk_write_fails' => [
        'driver' => 'vkcrm_write_fails',
        'root' => $root,
    ]]);

    config([
        'backup.backup.destination.disks' => ['backup_test_disk_write_fails', 'backup_test_disk_3'],
        'vkcrm.backup.notify_email' => 'ops@luatvukhang.com',
    ]);
    rebindBackup();

    Artisan::call('backup:run', ['--only-files' => true]);

    $name = config('backup.backup.name');

    // Disk hỏng thật sự kết nối được — lỗi là lỗi GHI, không phải lỗi kết nối.
    expect(Storage::disk('backup_test_disk_write_fails')->allFiles($name))->toBe([]);

    // Disk lành, đứng SAU disk hỏng, vẫn nhận đúng một archive.
    expect(Storage::disk('backup_test_disk_3')->allFiles($name))->toHaveCount(1);

    $row = OutboundMessage::query()->sole();

    expect($row->status)->toBe(OutboundStatus::Sent)
        ->and($row->template)->toBe('staff.backup_alert.backup_failed')
        ->and($row->payload['subject'] ?? null)->toContain('backup_test_disk_write_fails');
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
