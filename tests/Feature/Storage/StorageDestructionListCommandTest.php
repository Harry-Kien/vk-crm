<?php

use App\Enums\Role;
use App\Models\Document;
use App\Models\DriveFolder;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\User;
use App\Support\Storage\DocumentStore;
use App\Support\Storage\GoogleDrive\DriveObjectName;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\DocumentStoreFixtures as Store;
use Tests\Support\FakeGoogleDrive;
use Tests\Support\StagingFixtures;
use Tests\Support\StorageCommandFixtures as Ops;

/*
|--------------------------------------------------------------------------
| M14 Task 6 — `vkcrm:storage:destruction-list {matter} --by=<email>` (R15)
|--------------------------------------------------------------------------
|
| CRM không xoá tệp trên kho (vai Người quản lý nội dung không xoá vĩnh viễn được; bản ở văn phòng
| không bao giờ tự xoá). Lệnh này in ba danh sách để người có quyền huỷ ở từng nơi: tên trên Drive (mọi
| thế hệ, cả dòng đã rời chỉ mục), đường vùng đệm còn trên máy chủ, đường trong remote `crypt` của văn
| phòng theo tháng. Chỉ mã và tên mờ — không tiêu đề, không mã hồ sơ, không mã tệp Drive.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 20:00:00', 'Asia/Ho_Chi_Minh'));
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->withRole(Role::Admin)->create(['email' => 'quan-tri@luatvukhang.test']);
    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create(['email' => 'luat-su@luatvukhang.test']);
    $this->matter = Matter::factory()->create([
        'title' => 'TIEU-DE-VU-BI-MAT',
        'lead_lawyer_id' => $this->lawyer->id,
        'closed_at' => now()->subYears(11)->toDateString(),
    ]);
    $this->archive = MatterArchive::factory()->create([
        'matter_id' => $this->matter->id,
        'retention_until' => now()->subYear()->toDateString(),
    ]);
});

function t6Destroyed(MatterArchive $archive): void
{
    $archive->forceFill([
        'destroyed_at' => now()->subDay(),
        'destruction_reason' => 'Hết hạn lưu trữ theo quy chế của văn phòng.',
        'destruction_record_no' => 'BB-TH-2026-07',
    ])->save();
}

function t6List(Matter $matter, string $by): array
{
    return Ops::artisan('vkcrm:storage:destruction-list', ['matter' => $matter->code, '--by' => $by]);
}

it('vụ chưa ghi quyết định huỷ → mã 2, không in gì của vụ, không audit', function () {
    [$exit, $output] = t6List($this->matter, $this->admin->email);

    expect($exit)->toBe(2)
        ->and($output)->not->toContain($this->matter->code)
        ->and(Ops::audits('matter_storage_destruction_listed'))->toBe([]);
});

it('--by là luật sư (không qua Gate recordDestruction) → mã 2; email không có, hay admin đã bị vô hiệu → mã 2', function (string $who) {
    t6Destroyed($this->archive);

    $email = match ($who) {
        'lawyer' => $this->lawyer->email,
        'unknown' => 'khong-co@luatvukhang.test',
        'inactive' => tap($this->admin)->update(['is_active' => false])->email,
    };

    [$exit] = t6List($this->matter, $email);

    expect($exit)->toBe(2)->and(Ops::audits('matter_storage_destruction_listed'))->toBe([]);
})->with(['lawyer', 'unknown', 'inactive']);

it('admin → in tên Drive (mọi thế hệ, cả dòng đã rời chỉ mục), đường vùng đệm, đường crypt theo tháng; audit; không tiêu đề, mã hồ sơ, mã tệp Drive', function () {
    t6Destroyed($this->archive);
    Http::preventStrayRequests();
    Http::fake();

    $document = Document::factory()->for($this->matter)->create();
    $media = StagingFixtures::media('%PDF-1.4 tep cua vu da huy', document: $document);
    $key = $media->getPathRelativeToRoot();
    DB::table('media')->where('id', $media->id)->update(['disk' => DocumentStore::REMOTE_DISK, 'conversions_disk' => DocumentStore::REMOTE_DISK]);

    DriveFolder::query()->create(['drive_id' => FakeGoogleDrive::DRIVE_ID, 'root_folder_id' => FakeGoogleDrive::ROOT_FOLDER_ID, 'name' => '2026-09', 'folder_id' => '1MonthFolderSept']);
    DriveFolder::query()->create(['drive_id' => FakeGoogleDrive::DRIVE_ID, 'root_folder_id' => FakeGoogleDrive::ROOT_FOLDER_ID, 'name' => '2026-10', 'folder_id' => '1MonthFolderOct']);
    Store::driveObject(['former_key' => $key, 'generation' => 1, 'retired_reason' => 'trashed', 'retired_at' => now(), 'file_id' => 'FILEIDTHUNGRAC', 'parent_id' => '1MonthFolderSept']);
    Store::driveObject(['object_key' => $key, 'generation' => 2, 'file_id' => 'FILEIDSONG', 'parent_id' => '1MonthFolderOct']);

    // Một media khác (vụ khác, id có cùng tiền tố số) không được lọt vào danh sách.
    Store::driveObject(['object_key' => $media->id.'0/'.strtolower((string) Str::ulid()).'.pdf']);

    [$exit, $output] = t6List($this->matter, $this->admin->email);

    $g1 = DriveObjectName::fromKey($key);
    $g2 = DriveObjectName::fromKey($key, 2);

    expect($exit)->toBe(0, $output)
        ->and($output)->toContain($g1)
        ->and($output)->toContain($g2)
        ->and($output)->toContain(DocumentStore::staging()->path($key))
        ->and($output)->toContain('vkoffice:kho/2026-09/'.$g1)
        ->and($output)->toContain('vkoffice:kho/2026-10/'.$g2)
        ->and($output)->not->toContain($media->id.'0~')
        ->and($output)->not->toContain('TIEU-DE-VU-BI-MAT')
        ->and($output)->not->toContain($this->matter->code)
        ->and($output)->not->toContain('FILEID');

    $audit = Ops::audits('matter_storage_destruction_listed');
    expect($audit)->toHaveCount(1)
        ->and($audit[0]->causer_id)->toBe($this->admin->id)
        ->and($audit[0]->subject_id)->toBe($this->matter->id)
        ->and($audit[0]->properties->get('drive_files'))->toBe(2)
        ->and($audit[0]->properties->get('staged_files'))->toBe(1);
    Http::assertNothingSent();
});
