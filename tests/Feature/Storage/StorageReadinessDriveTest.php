<?php

use App\Actions\Storage\StorageReadiness;
use App\Enums\DriveObjectRetirement;
use App\Enums\PreflightLevel;
use App\Models\DriveObject;
use App\Support\Files\FreeSpace;
use App\Support\Storage\CredentialFileInspector;
use App\Support\Storage\GoogleDrive\DriveTokenProvider;
use App\Support\Storage\HttpTransportAvailability;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\DocumentStoreFixtures as Store;
use Tests\Support\FakeCredentialFile;
use Tests\Support\FakeGoogleDrive;

/*
|--------------------------------------------------------------------------
| M14 Task 5 — bốn dòng sẵn sàng có mạng: drive_reachable, drive_sharing, drive_root_folder,
| drive_roundtrip (R5, R7; phán quyết C2)
|--------------------------------------------------------------------------
|
| Client và adapter THẬT nói chuyện với máy chủ Drive giả (`FakeGoogleDrive`: `Http::fake()` +
| `Http::preventStrayRequests()`), nên test đo đúng các request đi ra. Không request nào tới Google
| thật. Mỗi vế đi qua `StorageReadiness::rows()` và qua `vkcrm:storage:check`.
*/

beforeEach(function () {
    $this->drive = FakeGoogleDrive::install();
    app()->instance(DriveTokenProvider::class, FakeGoogleDrive::tokenProvider());

    // Chia sẻ đúng luật, để mỗi test chỉ đổi đúng một điều.
    $this->drive->drive['restrictions']['domainUsersOnly'] = true;
});

/** @return list<Request> */
function t5DriveRequests(string $contains): array
{
    return Http::recorded(fn (Request $request) => str_contains($request->url(), $contains))
        ->map(fn (array $pair) => $pair[0])
        ->values()
        ->all();
}

// ---------------------------------------------------------------------------------------------
// drive_reachable
// ---------------------------------------------------------------------------------------------

it('drive_reachable: Shared Drive trả lời thì XANH', function () {
    $row = Store::expectRow('drive_reachable', PreflightLevel::Green);

    expect($row['message'])->toContain('VK-CRM Kho');
});

it('drive_reachable: sai mã Shared Drive (404) là ĐỎ, kèm gợi ý NTP, tường lửa và mã Shared Drive', function () {
    config(['vkcrm.storage.google_drive.shared_drive_id' => '0ASaiMaDrive']);

    $row = Store::expectRow('drive_reachable', PreflightLevel::Red);

    expect($row['message'])
        ->toContain('NTP')
        ->toContain('oauth2.googleapis.com')
        ->toContain('www.googleapis.com')
        ->toContain('GOOGLE_DRIVE_SHARED_DRIVE_ID');
});

it('drive_reachable: Google không trả lời (lỗi kết nối mọi lượt thử) là ĐỎ', function () {
    $this->drive->failNext('GET', 'drives/', 0, times: 20);

    Store::expectRow('drive_reachable', PreflightLevel::Red);
});

it('drive_reachable: Google từ chối token là ĐỎ', function () {
    $this->drive->revokeTokens();

    Store::expectRow('drive_reachable', PreflightLevel::Red);
});

it('drive_reachable: thiếu mã Shared Drive là ĐỎ và không gửi request nào', function () {
    config(['vkcrm.storage.google_drive.shared_drive_id' => null]);

    $row = Store::expectRow('drive_reachable', PreflightLevel::Red);

    expect($row['message'])->toContain('GOOGLE_DRIVE_SHARED_DRIVE_ID');
    Http::assertNothingSent();
});

it('drive_reachable: thiếu đường dẫn khoá là ĐỎ và không gửi request nào', function () {
    config(['vkcrm.storage.google_drive.credentials_path' => null]);

    $row = Store::expectRow('drive_reachable', PreflightLevel::Red);

    expect($row['message'])->toContain('GOOGLE_DRIVE_CREDENTIALS_PATH');
    Http::assertNothingSent();
});

it('không HTTP client thì bốn dòng mạng ĐỎ mà không thử gửi gì', function () {
    app()->instance(HttpTransportAvailability::class, new class extends HttpTransportAvailability
    {
        public function curl(): bool
        {
            return false;
        }

        public function urlFopen(): bool
        {
            return false;
        }
    });

    foreach (['drive_reachable', 'drive_sharing', 'drive_root_folder', 'drive_roundtrip'] as $key) {
        expect(Store::readiness($key)['level'])->toBe(PreflightLevel::Red, $key);
    }

    Http::assertNothingSent();
});

it('Drive không tới được thì ba dòng sau ĐỎ "chưa kiểm được", không gửi thêm request nào', function () {
    config(['vkcrm.storage.google_drive.shared_drive_id' => '0ASaiMaDrive']);

    $rows = app(StorageReadiness::class)->rows();

    foreach (['drive_sharing', 'drive_root_folder', 'drive_roundtrip'] as $key) {
        expect(Store::find($rows, $key)['level'])->toBe(PreflightLevel::Red, $key);
    }

    // Chỉ đúng một drives.get (cộng lượt thử lại nếu có); không permissions.list, không tải lên.
    expect(t5DriveRequests('/permissions'))->toBe([])
        ->and(t5DriveRequests('upload/drive'))->toBe([]);
});

// ---------------------------------------------------------------------------------------------
// drive_sharing (chi tiết từng điều kiện ở InspectDriveSharingTest)
// ---------------------------------------------------------------------------------------------

it('drive_sharing: chia sẻ đúng luật là XANH', function () {
    Store::expectRow('drive_sharing', PreflightLevel::Green);
});

it('drive_sharing: domainUsersOnly tắt là VÀNG', function () {
    $this->drive->drive['restrictions']['domainUsersOnly'] = false;

    Store::expectRow('drive_sharing', PreflightLevel::Yellow);
});

it('drive_sharing: có thành viên lạ là ĐỎ, câu nêu email đó', function () {
    $this->drive->permissionPages[0]['permissions'][] = ['id' => 'p9', 'type' => 'user', 'role' => 'reader', 'emailAddress' => 'nguoi-la@ngoai.vn'];

    $row = Store::expectRow('drive_sharing', PreflightLevel::Red);

    expect($row['message'])->toContain('nguoi-la@ngoai.vn');
});

// ---------------------------------------------------------------------------------------------
// drive_root_folder
// ---------------------------------------------------------------------------------------------

it('drive_root_folder: thư mục gốc thuộc Shared Drive này, chưa vào thùng rác là XANH', function () {
    Store::expectRow('drive_root_folder', PreflightLevel::Green);
});

it('drive_root_folder: thư mục gốc đang ở thùng rác là ĐỎ', function () {
    $this->drive->files[FakeGoogleDrive::ROOT_FOLDER_ID]['trashed'] = true;

    Store::expectRow('drive_root_folder', PreflightLevel::Red);
});

it('drive_root_folder: thư mục gốc thuộc Shared Drive khác là ĐỎ', function () {
    $this->drive->files[FakeGoogleDrive::ROOT_FOLDER_ID]['driveId'] = '0AShareDriveKhac';

    Store::expectRow('drive_root_folder', PreflightLevel::Red);
});

it('drive_root_folder: mã thư mục gốc là một TỆP, không phải thư mục, là ĐỎ', function () {
    $this->drive->files[FakeGoogleDrive::ROOT_FOLDER_ID]['mimeType'] = 'application/pdf';

    Store::expectRow('drive_root_folder', PreflightLevel::Red);
});

it('drive_root_folder: mã thư mục gốc không có trên Drive (404) là ĐỎ, câu nói đúng là không tìm thấy', function () {
    config(['vkcrm.storage.google_drive.root_folder_id' => '1KhongCoThuMucNay']);

    $row = Store::expectRow('drive_root_folder', PreflightLevel::Red);

    expect($row['message'])->toContain(__('document_store.root.not_found'));
});

it('drive_root_folder: chưa điền mã thư mục gốc là ĐỎ, nhắc chạy vkcrm:storage:init; không thử ghi–đọc', function () {
    config(['vkcrm.storage.google_drive.root_folder_id' => null]);

    $row = Store::expectRow('drive_root_folder', PreflightLevel::Red);
    $roundtrip = Store::readiness('drive_roundtrip');

    expect($row['message'])->toContain('vkcrm:storage:init')
        ->and($roundtrip['level'])->toBe(PreflightLevel::Red)
        ->and($roundtrip['message'])->toContain(__('document_store.readiness.skipped_root'))
        ->and(t5DriveRequests('upload/drive'))->toBe([]);
});

// ---------------------------------------------------------------------------------------------
// drive_roundtrip — ghi, kiểm md5, đọc lại, cho vào thùng rác; luôn dọn
// ---------------------------------------------------------------------------------------------

it('drive_roundtrip: ghi 1 KiB dưới preflight/, kiểm md5, đọc lại rồi cho vào thùng rác: XANH', function () {
    $row = Store::readiness('drive_roundtrip');

    expect($row['level'])->toBe(PreflightLevel::Green, $row['message']);

    $probes = array_values(array_filter($this->drive->files, fn (array $file) => str_starts_with($file['name'], 'preflight~')));

    expect($probes)->toHaveCount(1)
        ->and(strlen($probes[0]['content']))->toBe(1024)
        ->and($probes[0]['trashed'])->toBeTrue()
        ->and(preg_match('/^preflight~[0-9a-z]+\.txt$/', $probes[0]['name']))->toBe(1);

    // Dòng chỉ mục của tệp thăm dò đã rời chỉ mục sống, lý do thùng rác.
    $index = DriveObject::query()->where('former_key', 'like', 'preflight/%')->sole();

    expect($index->object_key)->toBeNull()
        ->and($index->retired_reason)->toBe(DriveObjectRetirement::Trashed);

    // md5 hỏi Google (files.get có md5Checksum), nội dung đọc qua alt=media.
    expect(t5DriveRequests('alt=media'))->toHaveCount(1);

    [, $output] = Store::check();
    expect($output)->toContain('[XANH] drive_roundtrip:');
});

it('drive_roundtrip: Google báo md5 khác bản gửi đi là ĐỎ, và tệp vẫn được dọn', function () {
    // `files.get` metadata đầu tiên là của thư mục gốc (drive_root_folder); lượt thứ hai là checksum()
    // của tệp thăm dò — chỉ lượt đó trả md5 sai.
    $this->drive->respondNext('GET', 'sha256Checksum', fn (Request $request) => Http::response([
        'id' => 'x', 'name' => 'x', 'size' => '1024', 'md5Checksum' => str_repeat('0', 32), 'trashed' => false,
        'mimeType' => 'text/plain', 'parents' => [], 'driveId' => FakeGoogleDrive::DRIVE_ID,
    ]), after: 1);

    $row = Store::readiness('drive_roundtrip');

    expect($row['level'])->toBe(PreflightLevel::Red);

    $probes = array_values(array_filter($this->drive->files, fn (array $file) => str_starts_with($file['name'], 'preflight~')));
    expect($probes)->toHaveCount(1)
        ->and($probes[0]['trashed'])->toBeTrue();
});

it('drive_roundtrip: nội dung đọc lại khác bản đã ghi là ĐỎ, và tệp vẫn được dọn', function () {
    $this->drive->respondNext('GET', 'alt=media', Http::response('noi dung khac', 200));

    $row = Store::readiness('drive_roundtrip');

    expect($row['level'])->toBe(PreflightLevel::Red);

    $probes = array_values(array_filter($this->drive->files, fn (array $file) => str_starts_with($file['name'], 'preflight~')));
    expect($probes[0]['trashed'])->toBeTrue();
});

it('drive_roundtrip: tải lên hỏng là ĐỎ', function () {
    // Hai lần: một cho rows(), một cho lệnh vkcrm:storage:check (403 này không được thử lại).
    $this->drive->failNext('POST', 'uploadType=resumable', 403, 'insufficientFilePermissions', times: 2);

    Store::expectRow('drive_roundtrip', PreflightLevel::Red);
});

it('drive_roundtrip: không cho được tệp thăm dò vào thùng rác (vai Contributor) là ĐỎ', function () {
    $this->drive->failNext('PATCH', 'files/', 403, 'insufficientFilePermissions');

    $row = Store::readiness('drive_roundtrip');

    expect($row['level'])->toBe(PreflightLevel::Red)
        ->and($row['message'])->toContain('thùng rác');
});

it('mọi dòng sẵn sàng XANH thì isReady() đúng và lệnh thoát 0 (dòng trạng thái chỉ VÀNG/XANH)', function () {
    app()->instance(CredentialFileInspector::class, new FakeCredentialFile);
    app()->instance(FreeSpace::class, new FreeSpace(fn (string $path) => 50 * 1024 ** 3));

    $readiness = app(StorageReadiness::class);

    expect(collect($readiness->rows())->filter(fn ($row) => $row['level'] !== PreflightLevel::Green)->all())->toBe([])
        ->and($readiness->isReady())->toBeTrue();

    [$exit] = Store::check();
    expect($exit)->toBe(0);
});

it('isReady() sai khi có một dòng ĐỎ', function () {
    $this->drive->files[FakeGoogleDrive::ROOT_FOLDER_ID]['trashed'] = true;

    expect(app(StorageReadiness::class)->isReady())->toBeFalse();
});
