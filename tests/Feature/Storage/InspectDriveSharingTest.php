<?php

use App\Enums\PreflightLevel;
use App\Support\Storage\CredentialFileInspector;
use App\Support\Storage\GoogleDrive\DriveTokenProvider;
use Tests\Support\DocumentStoreFixtures as Store;
use Tests\Support\FakeCredentialFile;
use Tests\Support\FakeGoogleDrive;

/*
|--------------------------------------------------------------------------
| M14 Task 5 — `InspectDriveSharing`: chia sẻ chỉ thành viên, vai fileOrganizer (R5)
|--------------------------------------------------------------------------
|
| Mỗi điều kiện của bảng R5 một test, đo qua dòng `drive_sharing` của `StorageReadiness::rows()` trên
| máy chủ Drive giả (`drives.get` + `permissions.list` thật qua `Http::fake()`). Trạng thái gốc của
| mỗi test là XANH: chỉ thành viên, chỉ người quản lý chia sẻ thư mục, chỉ người trong tổ chức, tài
| khoản dịch vụ ở vai `fileOrganizer`, hai thành viên được phép đúng vai đã khai. Mỗi test đổi đúng
| một điều.
*/

beforeEach(function () {
    $this->drive = FakeGoogleDrive::install();
    app()->instance(DriveTokenProvider::class, FakeGoogleDrive::tokenProvider());

    $this->drive->drive['restrictions'] = [
        'driveMembersOnly' => true,
        'domainUsersOnly' => true,
        'sharingFoldersRequiresOrganizerPermission' => true,
    ];

    $this->drive->permissionPages = [
        ['permissions' => [
            ['id' => 'p1', 'type' => 'user', 'role' => 'fileOrganizer', 'emailAddress' => FakeGoogleDrive::SERVICE_ACCOUNT],
            ['id' => 'p2', 'type' => 'user', 'role' => 'organizer', 'emailAddress' => 'du-phong@luatvukhang.com'],
        ]],
        // Trang thứ hai: điều kiện phải đọc HẾT mọi trang của permissions.list.
        ['permissions' => [
            ['id' => 'p3', 'type' => 'user', 'role' => 'reader', 'emailAddress' => 'van-phong-kho@luatvukhang.com'],
        ]],
    ];

    config(['vkcrm.storage.google_drive.allowed_members' => 'du-phong@luatvukhang.com:organizer, Van-Phong-Kho@LuatVuKhang.com:reader']);
});

function t5Sharing(): array
{
    return Store::readiness('drive_sharing');
}

function t5AddPermission(FakeGoogleDrive $drive, array $permission, int $page = 1): void
{
    $drive->permissionPages[$page]['permissions'][] = $permission + ['id' => 'px'.count($drive->permissionPages[$page]['permissions'])];
}

it('trạng thái gốc (đúng luật, đọc hết hai trang thành viên) là XANH', function () {
    $row = t5Sharing();

    expect($row['level'])->toBe(PreflightLevel::Green, $row['message']);
});

// ---------------------------------------------------------------------------------------------
// ĐỎ
// ---------------------------------------------------------------------------------------------

it('quyền kiểu anyone là ĐỎ, câu nói đúng là "bất kỳ ai có link"', function () {
    t5AddPermission($this->drive, ['type' => 'anyone', 'role' => 'reader']);

    $row = t5Sharing();

    expect($row['level'])->toBe(PreflightLevel::Red)
        ->and($row['message'])->toContain(__('document_store.sharing.anyone', ['role' => 'reader']));
});

it('quyền kiểu domain là ĐỎ', function () {
    t5AddPermission($this->drive, ['type' => 'domain', 'role' => 'reader', 'domain' => 'luatvukhang.com']);

    $row = t5Sharing();

    expect($row['level'])->toBe(PreflightLevel::Red)
        ->and($row['message'])->toContain('luatvukhang.com');
});

it('thành viên lạ (người hay nhóm) là ĐỎ, kể cả ở trang thứ hai', function (string $type) {
    t5AddPermission($this->drive, ['type' => $type, 'role' => 'reader', 'emailAddress' => 'sao-luu@luatvukhang.com']);

    $row = t5Sharing();

    expect($row['level'])->toBe(PreflightLevel::Red)
        ->and($row['message'])->toContain('sao-luu@luatvukhang.com');
})->with(['user', 'group']);

it('thành viên trong danh sách mang vai khác vai đã khai là ĐỎ', function () {
    $this->drive->permissionPages[1]['permissions'][0]['role'] = 'writer';

    $row = t5Sharing();

    expect($row['level'])->toBe(PreflightLevel::Red)
        ->and($row['message'])->toContain('van-phong-kho@luatvukhang.com');
});

it('tài khoản dịch vụ mang vai khác fileOrganizer là ĐỎ', function (string $role) {
    $this->drive->permissionPages[0]['permissions'][0]['role'] = $role;

    expect(t5Sharing()['level'])->toBe(PreflightLevel::Red);
})->with(['organizer', 'writer', 'reader', 'commenter']);

it('tài khoản dịch vụ ở vai fileOrganizer là XANH', function () {
    $this->drive->permissionPages[0]['permissions'][0]['role'] = 'fileOrganizer';

    expect(t5Sharing()['level'])->toBe(PreflightLevel::Green);
});

it('tài khoản dịch vụ không có trong danh sách thành viên là ĐỎ', function () {
    array_shift($this->drive->permissionPages[0]['permissions']);

    expect(t5Sharing()['level'])->toBe(PreflightLevel::Red);
});

it('driveMembersOnly tắt (hoặc vắng) là ĐỎ', function (mixed $value) {
    $this->drive->drive['restrictions']['driveMembersOnly'] = $value;

    expect(t5Sharing()['level'])->toBe(PreflightLevel::Red);
})->with(['false' => false, 'vắng' => null]);

it('GOOGLE_DRIVE_ALLOWED_MEMBERS có mục sai dạng là ĐỎ, câu nêu đúng mục đó', function (string $entry) {
    // Hai mục đúng vẫn còn, nên chỉ mục sai dạng làm dòng này đỏ (không phải một "thành viên lạ").
    config(['vkcrm.storage.google_drive.allowed_members' => 'du-phong@luatvukhang.com:organizer,van-phong-kho@luatvukhang.com:reader,'.$entry]);

    $row = t5Sharing();

    expect($row['level'])->toBe(PreflightLevel::Red)
        ->and($row['message'])->toContain($entry);
})->with([
    'thiếu vai' => 'nguoi-khac@luatvukhang.com',
    'vai lạ' => 'nguoi-khac@luatvukhang.com:owner',
    'không phải email' => 'nguoi-khac:reader',
]);

it('không đọc được email tài khoản dịch vụ từ tệp khoá là ĐỎ, câu nói đúng lý do', function () {
    app()->instance(CredentialFileInspector::class, new FakeCredentialFile(json: (string) json_encode(['type' => 'service_account'])));

    $row = t5Sharing();

    expect($row['level'])->toBe(PreflightLevel::Red)
        ->and($row['message'])->toContain(__('document_store.sharing.service_account_unknown'));
});

// ---------------------------------------------------------------------------------------------
// VÀNG
// ---------------------------------------------------------------------------------------------

it('sharingFoldersRequiresOrganizerPermission tắt là VÀNG', function () {
    $this->drive->drive['restrictions']['sharingFoldersRequiresOrganizerPermission'] = false;

    expect(t5Sharing()['level'])->toBe(PreflightLevel::Yellow);
});

it('domainUsersOnly tắt là VÀNG, kèm lời giải thích tài khoản dịch vụ là người ngoài tổ chức', function () {
    $this->drive->drive['restrictions']['domainUsersOnly'] = false;

    $row = t5Sharing();

    expect($row['level'])->toBe(PreflightLevel::Yellow)
        ->and($row['message'])->toContain('gserviceaccount.com');
});

it('một điều ĐỎ thắng mọi điều VÀNG', function () {
    $this->drive->drive['restrictions']['domainUsersOnly'] = false;
    t5AddPermission($this->drive, ['type' => 'anyone', 'role' => 'reader']);

    expect(t5Sharing()['level'])->toBe(PreflightLevel::Red);
});
