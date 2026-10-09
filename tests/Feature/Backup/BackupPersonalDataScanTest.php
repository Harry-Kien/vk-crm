<?php

use App\Support\Normalizer;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Backup\Config\Config;
use Spatie\Backup\Notifications\EventHandler;
use Symfony\Component\Process\ExecutableFinder;
use Tests\Support\CommitsToTheDatabase;
use Tests\Support\SensitiveDataFlows;
use Tests\Support\SensitiveTraceScanner;

/*
|--------------------------------------------------------------------------
| §10.5 — một bản sao lưu THẬT, giải nén bằng mật khẩu, không chứa dạng nào của số CCCD
|--------------------------------------------------------------------------
|
| Kế hoạch M8 Task 4: quét "một bản sao lưu đã giải nén". Bản sao lưu là nơi dữ liệu RỜI máy chủ
| (Google Drive, máy chủ văn phòng — R3), nên một số CCCD lọt ra ngoài cột đã mã hoá ở đây đi xa
| nhất. Test chạy đúng các luồng thật của phép quét CSDL (`Tests\Support\SensitiveDataFlows`), rồi
| `backup:run` THẬT (dump MariaDB bằng `mariadb-dump` + tệp hồ sơ, nén, mã hoá AES-256), mở
| archive bằng mật khẩu và quét TỪNG mục bằng `Tests\Support\SensitiveTraceScanner`.
|
| Dữ liệu phải được COMMIT thì `mariadb-dump` (một tiến trình khác) mới thấy — nên tệp này dùng
| `CommitsToTheDatabase` (không transaction bọc test) và dựng lại CSDL sạch sau mỗi test.
|
| Chạy bằng `/d/vkwt/m8b-dev test:dump tests/Feature/Backup/BackupPersonalDataScanTest.php`. Ở
| runner SQLite thường test tự bỏ qua kèm lý do, cùng thành ngữ với `BackupDatabaseDumpTest`.
*/

uses(CommitsToTheDatabase::class);

beforeEach(function () {
    // Cờ static của gói; lý do ở `BackupRunIntegrationTest.php`.
    EventHandler::enable();
});

afterEach(function () {
    EventHandler::enable();
    $this->forgetMigratedDatabaseAfterCommittedTest();
});

it('§10.5 bản sao lưu thật (dump + tệp, mở bằng mật khẩu) không chứa dạng nào của số CCCD, mật khẩu hay bí mật 2FA sau các luồng thật', function () {
    $connection = config('database.default');
    $driver = config("database.connections.{$connection}.driver");

    $binary = match ($driver) {
        'mariadb' => 'mariadb-dump',
        'mysql' => 'mysqldump',
        default => null,
    };

    if ($binary === null) {
        $this->markTestSkipped("Cần kết nối MariaDB/MySQL (đang là `{$driver}`) — chạy bằng `m8b-dev test:dump`.");
    }

    if ((new ExecutableFinder)->find($binary) === null) {
        $this->markTestSkipped("Không có `{$binary}` trong PATH — chạy bằng `m8b-dev test:dump`.");
    }

    // Đích mặc định cũng là đĩa giả: nếu cấu hình đích dưới đây vì lý do nào đó không tới được lệnh,
    // archive rơi vào đây chứ không vào `storage/app/backups` thật của máy đang chạy test.
    Storage::fake('local_backups');

    $flows = SensitiveDataFlows::for($this)->useProductionStorage()->run();

    Storage::fake('backup_personal_data_scan');

    $password = 'mat-khau-luu-tru-thu-nghiem-'.Str::random(12);

    config([
        'backup.backup.destination.disks' => ['backup_personal_data_scan'],
        'backup.backup.password' => $password,
        // Kho tệp hồ sơ của lượt chạy (đĩa `private` giả) — nơi tệp tải lên ở trên đang nằm.
        'backup.backup.source.files.include' => [Storage::disk('private')->path('')],
        // Đĩa giả nằm dưới `storage/framework/testing`, mà `config/backup.php` loại trừ cả
        // `storage/framework` — giữ các loại trừ còn lại, bỏ đúng cái đó (đo được: thiếu dòng
        // này, archive chỉ còn bản dump, không tệp nào).
        'backup.backup.source.files.exclude' => array_values(array_filter(
            (array) config('backup.backup.source.files.exclude'),
            fn (string $path): bool => $path !== storage_path('framework'),
        )),
    ]);
    Config::rebind();

    // `Spatie\Backup\Commands\BackupCommand` nhận `Config` qua HÀM DỰNG, và ứng dụng Artisan dựng
    // mọi lệnh một lần ở lần `Artisan::call()` đầu tiên — ở đây là `queue:work` của các luồng trên,
    // trước cấu hình vừa đặt. Dựng lại ứng dụng Artisan để lệnh nhận đúng `Config` hiện tại (đo được:
    // thiếu dòng này, archive đi vào `local_backups` với nguồn là `storage/app/private` thật).
    app(ConsoleKernel::class)->setArtisan(null);

    expect(Artisan::call('backup:run', ['--disable-notifications' => true]))->toBe(0, Artisan::output())
        ->and(Storage::disk('local_backups')->allFiles())->toBe([]);

    $archives = Storage::disk('backup_personal_data_scan')->allFiles(config('backup.backup.name'));
    expect($archives)->toHaveCount(1);

    $archive = Storage::disk('backup_personal_data_scan')->path($archives[0]);

    // --- Đối chứng: archive chứa ĐÚNG dữ liệu mà phép quét đang tìm, ở dạng được phép. ---
    $zip = new ZipArchive;
    expect($zip->open($archive))->toBeTrue();
    $zip->setPassword($password);

    $entries = collect(range(0, $zip->numFiles - 1))->map(fn (int $index) => (string) $zip->getNameIndex($index));
    $dumpEntry = $entries->first(fn (string $name) => str_starts_with($name, 'db-dumps/') && str_ends_with($name, '.sql'));
    $dump = (string) $zip->getFromName((string) $dumpEntry);
    $zip->close();

    $clientCiphertext = (string) DB::table('clients')->where('id', $flows->client->id)->value('id_number');

    expect($dumpEntry)->not->toBeNull()
        ->and($dump)->toContain('INSERT INTO `matter_parties`')
        ->and($dump)->toContain($clientCiphertext)
        ->and($dump)->toContain((string) Normalizer::idNumberHash(SensitiveDataFlows::OPPOSING_ID_NUMBER_TYPED))
        ->and($dump)->toContain((string) Normalizer::idNumberHash(SensitiveDataFlows::INTAKE_CONTACT_ID_NUMBER_TYPED))
        ->and($entries->filter(fn (string $name) => str_ends_with($name, '.pdf'))->count())->toBe(1);

    $scanner = SensitiveTraceScanner::make()
        ->digits('CCCD khách hàng lúc tạo', SensitiveDataFlows::CLIENT_ID_NUMBER_TYPED, SensitiveDataFlows::CLIENT_ID_NUMBER_LOOKUP)
        ->digits('CCCD khách hàng sau khi sửa', SensitiveDataFlows::CLIENT_ID_NUMBER_EDITED)
        ->digits('CCCD bên đối lập', SensitiveDataFlows::OPPOSING_ID_NUMBER_TYPED)
        // Lượt quét trước bản 1.0 (fr-m2 rà soát cuối M10): hai số gõ ở màn hình Tiếp nhận.
        ->digits('CCCD người liên hệ (tiếp nhận)', SensitiveDataFlows::INTAKE_CONTACT_ID_NUMBER_TYPED)
        ->digits('CCCD bên đối lập (tiếp nhận)', SensitiveDataFlows::INTAKE_OPPOSING_ID_NUMBER_TYPED)
        ->typed('mật khẩu nhân sự', SensitiveDataFlows::STAFF_PASSWORD)
        ->typed('mật khẩu sai gõ ở ô mật khẩu', SensitiveDataFlows::WRONG_STAFF_PASSWORD)
        ->typed('chuỗi gõ nhầm vào ô email (admin)', SensitiveDataFlows::TYPED_INTO_STAFF_EMAIL)
        ->typed('mật khẩu tạm của tài khoản cổng', SensitiveDataFlows::PORTAL_INITIAL_PASSWORD)
        ->typed('chuỗi gõ nhầm vào ô email (cổng)', SensitiveDataFlows::TYPED_INTO_PORTAL_EMAIL)
        ->typed('mật khẩu của archive', $password)
        ->secret('secret 2FA', $flows->twoFactorSecret)
        ->secret('APP_KEY', (string) config('app.key'))
        ->secret('APP_KEY (phần base64)', Str::after((string) config('app.key'), 'base64:'));

    foreach ($flows->recoveryCodes as $index => $code) {
        $scanner->secret('mã khôi phục 2FA #'.($index + 1), $code);
    }

    expect($scanner->scanZip($archive, $password))->toBe([]);
});
