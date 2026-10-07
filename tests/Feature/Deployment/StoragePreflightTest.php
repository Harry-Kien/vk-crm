<?php

use App\Support\Files\FreeSpace;
use App\Support\Storage\CredentialFileInspector;
use App\Support\Storage\GoogleDrive\DriveTokenProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\Support\DocumentStoreFixtures as Store;
use Tests\Support\FakeCredentialFile;
use Tests\Support\FakeGoogleDrive;

/*
|--------------------------------------------------------------------------
| M14 Task 5 — `vkcrm:preflight` trên production nối các dòng của kho (kế hoạch R7)
|--------------------------------------------------------------------------
|
| Preflight production chỉ GÓI `StorageReadiness` lại; từng dòng được đo ở
| `tests/Feature/Storage/StorageReadiness*Test.php` và `StorageStateRowsTest.php`. Ở đây: nó nối ĐÚNG
| các dòng đó, và ở chế độ `local` không media nào trên kho thì chỉ hai dòng không cần Drive.
|
| Máy chủ Drive giả trả lời cả hai URL thăm dò `storage/app/private` của preflight bằng 404 (một
| máy chủ web cấu hình đúng), qua `respondNext` — mọi request khác tới đích lạ vẫn làm test đỏ.
*/

const T5_STORAGE_READINESS_KEYS = [
    'document_storage_driver', 'drive_credentials', 'drive_http_client', 'drive_reachable',
    'drive_sharing', 'drive_root_folder', 'drive_roundtrip',
];

const T5_STORAGE_STATE_KEYS = [
    'document_storage_enabled', 'drive_item_count', 'document_push_backlog', 'document_office_copy',
    'data_transfer_dossier', 'media_on_remote_while_local', 'disk_free_space_available',
];

beforeEach(function () {
    $this->drive = FakeGoogleDrive::install();
    app()->instance(DriveTokenProvider::class, FakeGoogleDrive::tokenProvider());
    app()->instance(CredentialFileInspector::class, new FakeCredentialFile);
    app()->instance(FreeSpace::class, new FreeSpace(fn (string $path) => 40 * 1024 ** 3));

    $this->drive->respondNext('GET', 'preflight.example.test', Http::response('not found', 404), times: 2);
    Process::fake(['command -v *' => Process::result(exitCode: 0)]);

    config([
        'app.env' => 'production',
        'app.debug' => false,
        'app.url' => 'http://preflight.example.test',
        'trustedproxy.proxies' => '10.0.0.1',
        'vkcrm.heartbeat_url' => 'https://heartbeat.example.test/ping',
        'session.secure' => true,
    ]);
});

/** @return list<string> khoá dòng kho mà đầu ra preflight mang (`[MỨC] khoá: câu`) */
function t5PreflightStorageKeys(string $output): array
{
    $keys = [];

    foreach ([...T5_STORAGE_READINESS_KEYS, ...T5_STORAGE_STATE_KEYS] as $key) {
        if (preg_match('/\] '.preg_quote($key, '/').':/', $output) === 1) {
            $keys[] = $key;
        }
    }

    return $keys;
}

it('công tắc google_drive: preflight production nối đủ bảy dòng sẵn sàng và bảy dòng trạng thái', function () {
    Store::enableRemote(now()->subDay());

    Artisan::call('vkcrm:preflight');
    $output = Artisan::output();

    expect(t5PreflightStorageKeys($output))->toBe([...T5_STORAGE_READINESS_KEYS, ...T5_STORAGE_STATE_KEYS]);

    // Đúng thứ tự: các dòng kho nằm SAU mọi dòng ra mắt có sẵn (chỉ nối thêm ở cuối).
    expect(strpos($output, 'document_storage_driver:'))->toBeGreaterThan(strpos($output, (string) __('preflight.mariadb_dump_ok', ['binary' => 'mariadb-dump'])));
});

it('công tắc local nhưng còn media trên kho: vẫn nối đủ các dòng của kho', function () {
    config(['vkcrm.storage.driver' => 'local']);
    Store::remoteMedia();

    Artisan::call('vkcrm:preflight');

    expect(t5PreflightStorageKeys(Artisan::output()))->toBe([...T5_STORAGE_READINESS_KEYS, ...T5_STORAGE_STATE_KEYS]);
});

it('công tắc local, không media trên kho: chỉ document_storage_driver và disk_free_space_available, không gọi Drive', function () {
    config(['vkcrm.storage.driver' => 'local']);

    Artisan::call('vkcrm:preflight');

    expect(t5PreflightStorageKeys(Artisan::output()))->toBe(['document_storage_driver', 'disk_free_space_available']);

    expect(Http::recorded(fn ($request) => str_contains($request->url(), 'googleapis.com')))->toBeEmpty();
});

it('giá trị công tắc gõ sai, không media trên kho: dòng công tắc ĐỎ làm preflight thoát khác 0', function () {
    config(['vkcrm.storage.driver' => 'gooogle_drive']);

    $exit = Artisan::call('vkcrm:preflight');

    expect($exit)->not->toBe(0)
        ->and(Artisan::output())->toContain('[ĐỎ] document_storage_driver:');
});

it('ngoài production preflight không nối dòng kho nào (dùng vkcrm:storage:check ở đó)', function () {
    config(['app.env' => 'staging']);
    Store::enableRemote(now()->subDay());

    Artisan::call('vkcrm:preflight');

    expect(t5PreflightStorageKeys(Artisan::output()))->toBe([]);
});
