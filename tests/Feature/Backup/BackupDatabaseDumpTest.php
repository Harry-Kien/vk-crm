<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\Config\Config;
use Spatie\Backup\Notifications\EventHandler;
use Symfony\Component\Process\ExecutableFinder;

/*
|--------------------------------------------------------------------------
| §10.8 — backup:run THẬT có dump CSDL (fix I5)
|--------------------------------------------------------------------------
|
| Các test khác của sao lưu chạy `--only-files`, vì image dev (`webdevops/php:8.3-alpine`) không
| có `mariadb-dump`/`mysqldump`/`sqlite3` (xem `docs/research/2026-09-26-sao-luu.md`). Test này
| chạy lượt sao lưu ĐẦY ĐỦ như lịch 02:00: dump CSDL mặc định bằng binary thật, nén cùng tệp hồ
| sơ, mã hoá, ghi ra disk — rồi mở archive kiểm bản dump nằm trong đó và chỉ đọc được bằng mật
| khẩu.
|
| Chạy bằng `/d/vkwt/m8-dev test:dump <đường dẫn>`: MariaDB dev cộng một container tạm cài
| `mariadb-client` lúc chạy. Ở `m8-dev test` thường (SQLite, không binary) test tự bỏ qua kèm lý
| do, để bộ test chính vẫn xanh mà không giả vờ đã kiểm.
*/

beforeEach(function () {
    // Cờ static của gói; lý do ở `BackupRunIntegrationTest.php`.
    EventHandler::enable();
});

afterEach(function () {
    EventHandler::enable();
});

it('§10.8 backup:run thật dump CSDL MariaDB/MySQL vào archive mã hoá', function () {
    $connection = config('database.default');
    $driver = config("database.connections.{$connection}.driver");

    // Tên binary đúng như `Spatie\DbDumper\Databases\MariaDb`/`MySql` gọi.
    $binary = match ($driver) {
        'mariadb' => 'mariadb-dump',
        'mysql' => 'mysqldump',
        default => null,
    };

    if ($binary === null) {
        $this->markTestSkipped("Cần kết nối MariaDB/MySQL (đang là `{$driver}`) — chạy bằng `m8-dev test:dump`.");
    }

    if ((new ExecutableFinder)->find($binary) === null) {
        $this->markTestSkipped("Không có `{$binary}` trong PATH — chạy bằng `m8-dev test:dump`.");
    }

    Storage::fake('backup_dump_disk_1');

    $password = 'mat-khau-thu-nghiem-khong-dung-that';

    config([
        'backup.backup.destination.disks' => ['backup_dump_disk_1'],
        'backup.backup.password' => $password,
    ]);
    Config::rebind();

    $exit = Artisan::call('backup:run', ['--disable-notifications' => true]);

    expect($exit)->toBe(0, Artisan::output());

    $files = Storage::disk('backup_dump_disk_1')->allFiles(config('backup.backup.name'));
    expect($files)->toHaveCount(1);

    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('backup_dump_disk_1')->path($files[0])))->toBe(true);

    $dumpEntries = collect(range(0, $zip->numFiles - 1))
        ->map(fn (int $index) => $zip->getNameIndex($index))
        ->filter(fn (string $name) => str_starts_with($name, 'db-dumps/') && str_ends_with($name, '.sql'))
        ->values();

    expect($dumpEntries)->toHaveCount(1);

    $entry = $dumpEntries->first();

    // Không mật khẩu: không đọc được bản dump.
    expect(@$zip->getFromName($entry))->toBeFalse();

    // Có mật khẩu: đọc được, và đó là bản dump thật của CSDL này — bảng do migration tạo ra,
    // cùng các dòng của bảng `migrations` (đã commit, nên tiến trình dump bên ngoài thấy được,
    // khác các dòng test tạo trong giao dịch của `RefreshDatabase`).
    $zip->setPassword($password);
    $dump = $zip->getFromName($entry);
    $zip->close();

    expect($dump)->toBeString()
        ->and($dump)->toContain('CREATE TABLE `users`')
        ->and($dump)->toContain('INSERT INTO `migrations`');
});
