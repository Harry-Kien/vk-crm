<?php

use App\Enums\DriveObjectRetirement;
use App\Enums\PreflightLevel;
use App\Models\SystemHealth;
use App\Support\Files\FreeSpace;
use App\Support\Storage\DocumentStore;
use App\Support\Storage\TransferDossier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\DocumentStoreFixtures as Store;
use Tests\Support\FakeGoogleDrive;

/*
|--------------------------------------------------------------------------
| M14 Task 5 — dòng trạng thái của kho (`StorageReadiness::stateRows()`; R2, R10, R13)
|--------------------------------------------------------------------------
|
| Không dòng nào ở đây gọi mạng: mọi con số đọc từ `media`, `drive_objects`, `drive_folders`,
| `settings` và `system_health`. `Http::preventStrayRequests()` giữ điều đó. Mỗi vế đi qua
| `stateRows()` và qua `vkcrm:storage:check`; lệnh đó cũng chạy các dòng sẵn sàng, nên mã Shared
| Drive để trống — các dòng mạng tự báo "chưa cấu hình" mà không gửi gì.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake();

    config([
        'vkcrm.storage.driver' => 'local',
        'vkcrm.storage.google_drive.shared_drive_id' => FakeGoogleDrive::DRIVE_ID,
        'vkcrm.storage.google_drive.credentials_path' => null,
        'vkcrm.storage.push_alert_minutes' => 60,
        'vkcrm.storage.office.receipts_path' => null,
        'vkcrm.storage.office.max_age_hours' => 36,
    ]);

    app()->instance(FreeSpace::class, new FreeSpace(fn (string $path) => 40 * 1024 ** 3));
    $this->freezeTime();
});

// ---------------------------------------------------------------------------------------------
// document_storage_enabled
// ---------------------------------------------------------------------------------------------

it('document_storage_enabled: google_drive mà chưa có mốc bật kho là ĐỎ', function () {
    config(['vkcrm.storage.driver' => DocumentStore::DRIVER_GOOGLE_DRIVE]);

    $row = Store::expectRow('document_storage_enabled', PreflightLevel::Red, state: true);

    expect($row['message'])->toContain('vkcrm:storage:enable');
});

it('document_storage_enabled: google_drive có mốc là XANH', function () {
    Store::enableRemote(now()->subDay());

    Store::expectRow('document_storage_enabled', PreflightLevel::Green, state: true);
});

it('document_storage_enabled: local là XANH (kho không bật, tệp ở máy chủ như trước)', function () {
    Store::expectRow('document_storage_enabled', PreflightLevel::Green, state: true);
});

// ---------------------------------------------------------------------------------------------
// drive_item_count
// ---------------------------------------------------------------------------------------------

it('drive_item_count: từ ngưỡng cảnh báo là VÀNG, dưới ngưỡng là XANH', function () {
    config(['vkcrm.storage.google_drive.item_warn' => 3, 'vkcrm.storage.google_drive.item_limit' => 4]);

    Store::driveObject(['object_key' => '1/a.pdf']);
    Store::driveObject(['object_key' => '2/b.pdf']);

    Store::expectRow('drive_item_count', PreflightLevel::Green, state: true);

    Store::driveObject(['object_key' => '3/c.pdf']);

    $row = Store::expectRow('drive_item_count', PreflightLevel::Yellow, state: true);

    expect($row['message'])->toContain('3')->toContain('4');
});

it('drive_item_count: đếm thư mục tháng và tệp vào thùng rác trong 30 ngày; bỏ thùng rác cũ hơn và drive khác', function () {
    config(['vkcrm.storage.google_drive.item_warn' => 3]);

    Store::driveObject(['object_key' => '1/a.pdf']);
    DB::table('drive_folders')->insert([
        'drive_id' => FakeGoogleDrive::DRIVE_ID, 'root_folder_id' => FakeGoogleDrive::ROOT_FOLDER_ID,
        'name' => '2026-10', 'folder_id' => '1FolderThang', 'created_at' => now(), 'updated_at' => now(),
    ]);

    // Không tính: thùng rác của Shared Drive tự xoá sau 30 ngày; dòng của Shared Drive khác.
    Store::driveObject(['former_key' => '2/b.pdf', 'retired_reason' => DriveObjectRetirement::Trashed->value, 'retired_at' => now()->subDays(31)]);
    Store::driveObject(['object_key' => '4/d.pdf', 'drive_id' => '0AShareDriveKhac']);

    Store::expectRow('drive_item_count', PreflightLevel::Green, state: true);

    // Tính: vào thùng rác 29 ngày trước — vẫn còn trên Drive.
    Store::driveObject(['former_key' => '3/c.pdf', 'retired_reason' => DriveObjectRetirement::Trashed->value, 'retired_at' => now()->subDays(29)]);

    Store::expectRow('drive_item_count', PreflightLevel::Yellow, state: true);
});

it('drive_item_count: tệp bị thay khi dựng lại chỉ mục vẫn nằm trên Drive nên vẫn được đếm', function () {
    config(['vkcrm.storage.google_drive.item_warn' => 3]);

    Store::driveObject(['object_key' => '1/a.pdf']);
    Store::driveObject(['object_key' => '2/b.pdf']);

    Store::expectRow('drive_item_count', PreflightLevel::Green, state: true);

    Store::driveObject(['former_key' => '3/c.pdf', 'retired_reason' => DriveObjectRetirement::Superseded->value, 'retired_at' => now()->subYear()]);

    Store::expectRow('drive_item_count', PreflightLevel::Yellow, state: true);
});

// ---------------------------------------------------------------------------------------------
// document_push_backlog
// ---------------------------------------------------------------------------------------------

it('document_push_backlog: tệp cũ (tạo trước mốc) ở máy chủ không làm dòng này vàng, và được đếm riêng', function () {
    Store::enableRemote(now()->subHours(5));
    Store::media(['created_at' => now()->subDays(3)]);
    Store::media(['created_at' => now()->subDays(2)]);
    Store::media(['created_at' => now()->subMinutes(10)]); // tệp mới, chưa quá hạn: không phải tệp cũ

    $row = Store::expectRow('document_push_backlog', PreflightLevel::Green, state: true);

    expect($row['message'])->toBe('document_push_backlog: '.__('document_store.readiness.backlog_ok', ['legacy' => 2]));
});

it('document_push_backlog: tệp tạo sau mốc, chờ quá push_alert_minutes là VÀNG', function () {
    Store::enableRemote(now()->subHours(5));
    Store::media(['created_at' => now()->subMinutes(61)]);

    Store::expectRow('document_push_backlog', PreflightLevel::Yellow, state: true);
});

it('document_push_backlog: tệp tạo sau mốc nhưng mới chờ chưa tới push_alert_minutes là XANH', function () {
    Store::enableRemote(now()->subHours(5));
    Store::media(['created_at' => now()->subMinutes(59)]);

    Store::expectRow('document_push_backlog', PreflightLevel::Green, state: true);
});

it('document_push_backlog: tệp đã lên kho không phải tồn đọng', function () {
    Store::enableRemote(now()->subHours(5));
    Store::remoteMedia(media: ['created_at' => now()->subHours(2)]);

    Store::expectRow('document_push_backlog', PreflightLevel::Green, state: true);
});

it('document_push_backlog: chưa có mốc thì mọi tệp ở máy chủ là tệp cũ, không tồn đọng', function () {
    config(['vkcrm.storage.driver' => DocumentStore::DRIVER_GOOGLE_DRIVE]);
    Store::media(['created_at' => now()->subDays(1)]);
    Store::media(['created_at' => now()->subMinutes(90)]);

    $row = Store::expectRow('document_push_backlog', PreflightLevel::Green, state: true);

    expect($row['message'])->toBe('document_push_backlog: '.__('document_store.readiness.backlog_ok', ['legacy' => 2]));
});

// ---------------------------------------------------------------------------------------------
// document_office_copy (R10)
// ---------------------------------------------------------------------------------------------

it('document_office_copy: chưa cấu hình office.receipts_path là VÀNG "chưa có máy văn phòng"', function () {
    $row = Store::expectRow('document_office_copy', PreflightLevel::Yellow, state: true);

    expect($row['message'])->toContain(__('document_store.readiness.office_not_configured_short'));
});

it('document_office_copy: biên nhận gần nhất còn mới, không lỗi là XANH', function () {
    config(['vkcrm.storage.office.receipts_path' => 'gdrive:VK-CRM-backups/office-receipts/test']);
    SystemHealth::current()->forceFill(['last_office_receipt_at' => now()->subHours(35)])->save();

    Store::expectRow('document_office_copy', PreflightLevel::Green, state: true);
});

it('document_office_copy: biên nhận gần nhất quá office.max_age_hours là VÀNG', function () {
    config(['vkcrm.storage.office.receipts_path' => 'gdrive:VK-CRM-backups/office-receipts/test']);
    SystemHealth::current()->forceFill(['last_office_receipt_at' => now()->subHours(37)])->save();

    Store::expectRow('document_office_copy', PreflightLevel::Yellow, state: true);
});

it('document_office_copy: đã cấu hình mà chưa từng nhận biên nhận nào là VÀNG', function () {
    config(['vkcrm.storage.office.receipts_path' => 'gdrive:VK-CRM-backups/office-receipts/test']);

    Store::expectRow('document_office_copy', PreflightLevel::Yellow, state: true);
});

it('document_office_copy: có lỗi biên nhận gần nhất là VÀNG', function () {
    config(['vkcrm.storage.office.receipts_path' => 'gdrive:VK-CRM-backups/office-receipts/test']);
    SystemHealth::current()->forceFill([
        'last_office_receipt_at' => now()->subHour(),
        'last_office_receipt_error' => 'Biên nhận receipt-x.json bị từ chối: sai mã Shared Drive.',
    ])->save();

    Store::expectRow('document_office_copy', PreflightLevel::Yellow, state: true);
});

it('document_office_copy: đếm media trên kho chưa có biên nhận khớp md5 trên đúng Shared Drive', function () {
    config(['vkcrm.storage.office.receipts_path' => 'gdrive:VK-CRM-backups/office-receipts/test']);
    SystemHealth::current()->forceFill(['last_office_receipt_at' => now()->subHour()])->save();

    Store::remoteMedia(now()->subHours(30));                                    // có biên nhận
    Store::remoteMedia();                                                         // chưa có
    Store::remoteMedia(now()->subHours(30), object: ['md5' => str_repeat('a', 32)]); // biên nhận của bản khác md5
    Store::remoteMedia(now()->subHours(30), object: ['drive_id' => '0AShareDriveKhac']); // drive khác
    Store::remoteMedia(now()->subHours(30), object: ['object_key' => null, 'former_key' => 'x', 'retired_reason' => 'trashed', 'retired_at' => now()]); // không sống
    Store::media(); // còn ở vùng đệm: không phải "media trên kho"

    $row = Store::expectRow('document_office_copy', PreflightLevel::Green, state: true);

    expect($row['message'])->toContain(__('document_store.readiness.office_unreceipted', ['count' => 4]));
});

// ---------------------------------------------------------------------------------------------
// data_transfer_dossier (R13) — chỉ production
// ---------------------------------------------------------------------------------------------

function t5Production(): void
{
    config(['app.env' => 'production', 'vkcrm.storage.driver' => DocumentStore::DRIVER_GOOGLE_DRIVE]);
}

it('data_transfer_dossier: production + google_drive, không ngày hồ sơ, không ý kiến là ĐỎ', function () {
    t5Production();

    $row = Store::expectRow('data_transfer_dossier', PreflightLevel::Red, state: true);

    expect($row['message'])->toContain(__('document_store.page.title'));
});

it('data_transfer_dossier: có ý kiến luật sư cho chuyển trước là XANH', function () {
    t5Production();
    Store::setting(TransferDossier::KEYS['transfer_before_dossier_on'], '2026-10-01');

    Store::expectRow('data_transfer_dossier', PreflightLevel::Green, state: true);
});

it('data_transfer_dossier: có ngày hồ sơ là XANH', function () {
    t5Production();
    Store::setting(TransferDossier::KEYS['transfer_dossier_on'], '2026-10-02');

    $row = Store::expectRow('data_transfer_dossier', PreflightLevel::Green, state: true);

    expect($row['message'])->toContain(__('document_store.readiness.dossier_filed', ['date' => '02/10/2026']));
});

it('data_transfer_dossier: đồng hồ 60 ngày — +44 XANH, +45 VÀNG, +60 VÀNG, +61 ĐỎ khi chưa có ngày hồ sơ', function (int $days, PreflightLevel $level) {
    t5Production();
    Store::setting(TransferDossier::KEYS['transfer_before_dossier_on'], '2026-08-01');
    Store::setting(TransferDossier::FIRST_TRANSFER_AT_KEY, now()->subDays($days)->toIso8601String());

    $row = Store::expectRow('data_transfer_dossier', $level, state: true);

    if ($level === PreflightLevel::Yellow) {
        expect($row['message'])->toContain((string) (TransferDossier::DUE_DAYS - $days));
    }
})->with([
    '+44' => [44, PreflightLevel::Green],
    '+45' => [45, PreflightLevel::Yellow],
    '+60' => [60, PreflightLevel::Yellow],
    '+61' => [61, PreflightLevel::Red],
]);

it('data_transfer_dossier: có ngày hồ sơ thì đồng hồ dừng, kể cả quá 60 ngày', function () {
    t5Production();
    Store::setting(TransferDossier::FIRST_TRANSFER_AT_KEY, now()->subDays(90)->toIso8601String());
    Store::setting(TransferDossier::KEYS['transfer_dossier_on'], '2026-08-01');

    Store::expectRow('data_transfer_dossier', PreflightLevel::Green, state: true);
});

it('data_transfer_dossier: ngày hồ sơ không có thật (sửa tay bảng settings) bị coi như chưa có', function () {
    t5Production();
    Store::setting(TransferDossier::KEYS['transfer_dossier_on'], '2026-02-30');

    Store::expectRow('data_transfer_dossier', PreflightLevel::Red, state: true);
});

it('data_transfer_dossier: production + local, chưa chuyển gì là XANH', function () {
    config(['app.env' => 'production', 'vkcrm.storage.driver' => 'local']);

    Store::expectRow('data_transfer_dossier', PreflightLevel::Green, state: true);
});

it('data_transfer_dossier: ngoài production không có dòng này', function () {
    config(['vkcrm.storage.driver' => DocumentStore::DRIVER_GOOGLE_DRIVE]);

    expect(Store::state('data_transfer_dossier'))->toBeNull();

    [, $output] = Store::check();
    expect($output)->not->toContain('data_transfer_dossier:');
});

// ---------------------------------------------------------------------------------------------
// media_on_remote_while_local, disk_free_space_available
// ---------------------------------------------------------------------------------------------

it('media_on_remote_while_local: công tắc local mà còn media trên kho là VÀNG, đếm đúng media trên kho', function () {
    Store::remoteMedia();
    Store::media(); // ở vùng đệm: không đếm

    $row = Store::expectRow('media_on_remote_while_local', PreflightLevel::Yellow, state: true);

    expect($row['message'])->toBe('media_on_remote_while_local: '.__('document_store.readiness.remote_while_local', ['count' => 1]));
});

it('media_on_remote_while_local: google_drive có media trên kho, hoặc local không có, là XANH', function (string $driver, bool $remote) {
    config(['vkcrm.storage.driver' => $driver]);

    if ($remote) {
        Store::remoteMedia();
    }

    Store::expectRow('media_on_remote_while_local', PreflightLevel::Green, state: true);
})->with([
    'google_drive, có media trên kho' => ['google_drive', true],
    'local, không media nào trên kho' => ['local', false],
]);

it('disk_free_space_available: FreeSpace không đo được (null) là VÀNG', function () {
    app()->instance(FreeSpace::class, new FreeSpace('ham_khong_ton_tai_t5'));

    Store::expectRow('disk_free_space_available', PreflightLevel::Yellow, state: true);
});

it('disk_free_space_available: đo được là XANH', function () {
    Store::expectRow('disk_free_space_available', PreflightLevel::Green, state: true);
});
