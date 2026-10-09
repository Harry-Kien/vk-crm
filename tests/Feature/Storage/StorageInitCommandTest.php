<?php

use App\Support\Storage\GoogleDrive\DriveClient;
use App\Support\Storage\GoogleDrive\DriveTokenProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeGoogleDrive;

/*
|--------------------------------------------------------------------------
| M14 Task 5 — `vkcrm:storage:init`: tạo thư mục gốc `vkcrm-<APP_ENV>` trong Shared Drive (R4, R7)
|--------------------------------------------------------------------------
|
| Chỉ trên Drive giả (phán quyết C2). Lần chạy thật là Phụ lục A bước 11 — PENDING OWNER.
|
| Máy chủ giả coi Shared Drive như một mã không phải tệp; test thêm gốc Shared Drive vào danh sách
| tệp của nó như một thư mục (Drive thật cho gốc Shared Drive làm `parents` của thư mục con).
*/

beforeEach(function () {
    $this->drive = FakeGoogleDrive::install();
    app()->instance(DriveTokenProvider::class, FakeGoogleDrive::tokenProvider());

    $this->drive->files[FakeGoogleDrive::DRIVE_ID] = [
        'id' => FakeGoogleDrive::DRIVE_ID, 'name' => 'VK-CRM Kho', 'mimeType' => DriveClient::FOLDER_MIME,
        'parents' => [], 'driveId' => FakeGoogleDrive::DRIVE_ID, 'content' => '', 'trashed' => false,
    ];

    config(['vkcrm.storage.google_drive.root_folder_id' => null]);
});

function t5RootFolders(FakeGoogleDrive $drive, string $name): array
{
    return array_values(array_filter(
        $drive->files,
        fn (array $file) => $file['name'] === $name && in_array(FakeGoogleDrive::DRIVE_ID, $file['parents'], true),
    ));
}

it('tạo thư mục vkcrm-<APP_ENV> ngay dưới Shared Drive, in mã để điền GOOGLE_DRIVE_ROOT_FOLDER_ID, ghi audit', function () {
    $exit = Artisan::call('vkcrm:storage:init');
    $output = Artisan::output();

    $created = t5RootFolders($this->drive, 'vkcrm-testing');

    expect($exit)->toBe(0)
        ->and($created)->toHaveCount(1)
        ->and($created[0]['mimeType'])->toBe(DriveClient::FOLDER_MIME)
        ->and($output)->toContain('GOOGLE_DRIVE_ROOT_FOLDER_ID='.$created[0]['id']);

    $activity = Activity::query()->where('event', 'document_store_initialised')->sole();
    expect($activity->properties['folder_name'])->toBe('vkcrm-testing')
        ->and(json_encode($activity->properties))->not->toContain($created[0]['id']);
});

it('đã có thư mục cùng tên: liệt kê mã, dừng, không tạo thêm, mã thoát khác 0', function () {
    $existing = $this->drive->putFile('vkcrm-testing', '', [FakeGoogleDrive::DRIVE_ID], DriveClient::FOLDER_MIME);

    $exit = Artisan::call('vkcrm:storage:init');
    $output = Artisan::output();

    expect($exit)->not->toBe(0)
        ->and($output)->toContain($existing)
        ->and(t5RootFolders($this->drive, 'vkcrm-testing'))->toHaveCount(1)
        ->and(Activity::query()->where('event', 'document_store_initialised')->count())->toBe(0);

    expect(Http::recorded(fn (Request $request) => $request->method() === 'POST'))->toBeEmpty();
});

it('thư mục cùng tên đã vào thùng rác, hay một TỆP cùng tên, không chặn việc tạo', function () {
    $trashed = $this->drive->putFile('vkcrm-testing', '', [FakeGoogleDrive::DRIVE_ID], DriveClient::FOLDER_MIME);
    $this->drive->files[$trashed]['trashed'] = true;
    $this->drive->putFile('vkcrm-testing', 'tep', [FakeGoogleDrive::DRIVE_ID], 'text/plain');

    expect(Artisan::call('vkcrm:storage:init'))->toBe(0)
        ->and(array_filter(t5RootFolders($this->drive, 'vkcrm-testing'), fn ($f) => $f['mimeType'] === DriveClient::FOLDER_MIME && ! $f['trashed']))->toHaveCount(1);
});

it('đọc hết mọi trang danh sách con của Shared Drive trước khi kết luận chưa có', function () {
    $existing = $this->drive->putFile('vkcrm-testing', '', [FakeGoogleDrive::DRIVE_ID], DriveClient::FOLDER_MIME);

    // Trang đầu không có thư mục đó, kèm nextPageToken; trang hai mới có.
    $this->drive->respondNext('GET', 'corpora=drive', Http::response(['files' => [['id' => 'khac', 'name' => 'khac', 'mimeType' => DriveClient::FOLDER_MIME]], 'nextPageToken' => 'trang2']));

    $exit = Artisan::call('vkcrm:storage:init');

    expect($exit)->not->toBe(0)
        ->and(Artisan::output())->toContain($existing)
        ->and(t5RootFolders($this->drive, 'vkcrm-testing'))->toHaveCount(1);
});

it('thiếu mã Shared Drive hoặc khoá: mã thoát 2, không gửi request nào', function (string $key) {
    config(["vkcrm.storage.google_drive.{$key}" => null]);

    expect(Artisan::call('vkcrm:storage:init'))->toBe(2)
        ->and(Artisan::output())->toContain($key === 'shared_drive_id' ? 'GOOGLE_DRIVE_SHARED_DRIVE_ID' : 'GOOGLE_DRIVE_CREDENTIALS_PATH');

    Http::assertNothingSent();
})->with(['shared_drive_id', 'credentials_path']);

it('Drive từ chối (Shared Drive không có): mã thoát 1, câu tiếng Việt, không audit', function () {
    config(['vkcrm.storage.google_drive.shared_drive_id' => '0ASaiMaDrive']);

    $exit = Artisan::call('vkcrm:storage:init');

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain(__('storage.exceptions.container_not_found'))
        ->and(Activity::query()->where('event', 'document_store_initialised')->count())->toBe(0);
});
