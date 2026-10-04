<?php

use App\Support\Storage\DocumentStore;
use App\Support\Storage\GoogleDrive\DriveCircuitBreaker;
use App\Support\Storage\GoogleDrive\DriveClient;
use App\Support\Storage\GoogleDrive\DriveTokenProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| M14 Task 2 — test SỐNG trên một Shared Drive THỬ (kế hoạch M14, R7)
|--------------------------------------------------------------------------
|
| Chỉ chạy khi có ĐỦ bốn biến môi trường của tiến trình, trỏ vào một Shared Drive THỬ (không bao giờ
| Kho thật, không dữ liệu khách):
|
|   DRIVE_LIVE_TEST=1
|   DRIVE_LIVE_CREDENTIALS=<đường dẫn tệp khoá JSON của tài khoản dịch vụ>
|   DRIVE_LIVE_SHARED_DRIVE_ID=<mã Shared Drive thử>
|   DRIVE_LIVE_ROOT_FOLDER_ID=<mã thư mục gốc trong Shared Drive thử>
|
| Thiếu biến nào thì test tự bỏ qua kèm lý do. CI và các làn không có bí mật này (phán quyết C2):
| việc chạy nó là PENDING OWNER, ở Task 8 Phần 2. Chạy, ví dụ:
|
|   docker run … -e DRIVE_LIVE_TEST=1 -e DRIVE_LIVE_CREDENTIALS=… -e DRIVE_LIVE_SHARED_DRIVE_ID=… \
|     -e DRIVE_LIVE_ROOT_FOLDER_ID=… … vk-container-test tests/Feature/Storage/GoogleDriveLiveTest.php
|
| Nội dung: ghi 10 MB ngẫu nhiên với khối 4 MiB, kiểm checksum do Google tính, đọc lại so từng byte,
| liệt kê, cho vào thùng rác, xác nhận `trashed`, đọc `drives.get` và `permissions.list`. Tệp thử
| nằm dưới khoá `preflight/<ngẫu nhiên>.bin` và luôn được cho vào thùng rác ở cuối.
*/

function driveLiveEnvironment(): ?array
{
    $vars = [
        'flag' => getenv('DRIVE_LIVE_TEST'),
        'credentials' => getenv('DRIVE_LIVE_CREDENTIALS'),
        'drive' => getenv('DRIVE_LIVE_SHARED_DRIVE_ID'),
        'root' => getenv('DRIVE_LIVE_ROOT_FOLDER_ID'),
    ];

    if ($vars['flag'] !== '1' || in_array(false, $vars, true) || in_array('', $vars, true)) {
        return null;
    }

    return $vars;
}

it('ghi 10 MB, kiểm md5 của Google, đọc lại, liệt kê, cho vào thùng rác; đọc drives.get và permissions.list', function () {
    $live = driveLiveEnvironment();

    config([
        'vkcrm.storage.google_drive.credentials_path' => $live['credentials'],
        'vkcrm.storage.google_drive.shared_drive_id' => $live['drive'],
        'vkcrm.storage.google_drive.root_folder_id' => $live['root'],
        'vkcrm.storage.google_drive.chunk_mb' => 4,
        'vkcrm.storage.google_drive.token_cache_store' => 'array',
        'vkcrm.storage.google_drive.breaker_store' => 'array',
    ]);

    Storage::forgetDisk(DocumentStore::REMOTE_DISK);
    $disk = Storage::disk(DocumentStore::REMOTE_DISK);
    $key = 'preflight/'.Str::lower((string) Str::ulid()).'.bin';
    $content = random_bytes(10 * 1024 * 1024);

    try {
        $disk->put($key, $content);

        expect($disk->checksum($key))->toBe(md5($content))
            ->and($disk->size($key))->toBe(strlen($content))
            ->and($disk->get($key) === $content)->toBeTrue()
            ->and($disk->allFiles('preflight'))->toContain($key);
    } finally {
        $fileId = DB::table('drive_objects')->where('object_key', $key)->value('file_id');
        $disk->delete($key);
    }

    $client = DriveClient::fromConfig(app(DriveTokenProvider::class), new DriveCircuitBreaker(Cache::store('array')));

    expect($client->metadata($fileId)['trashed'])->toBeTrue()
        ->and($client->drive($live['drive']))->toHaveKey('restrictions')
        ->and($client->drivePermissions($live['drive']))->not->toBeEmpty();
})->skip(
    fn () => driveLiveEnvironment() === null,
    'Test sống Google Drive bỏ qua: cần DRIVE_LIVE_TEST=1, DRIVE_LIVE_CREDENTIALS, DRIVE_LIVE_SHARED_DRIVE_ID, DRIVE_LIVE_ROOT_FOLDER_ID trỏ vào một Shared Drive THỬ (PENDING OWNER, Task 8 Phần 2).',
);
