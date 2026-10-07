<?php

use App\Models\Document;
use App\Models\DriveObject;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Support\Storage\DocumentStore;
use App\Support\Storage\GoogleDrive\DriveObjectName;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Support\DocumentStoreFixtures as Store;
use Tests\Support\FakeGoogleDrive;
use Tests\Support\RemoteDocuments;
use Tests\Support\StagingFixtures;
use Tests\Support\StorageCommandFixtures as Ops;

/*
|--------------------------------------------------------------------------
| M14 Task 6 — `vkcrm:storage:orphans`: CHỈ báo cáo (R4, R11, R15)
|--------------------------------------------------------------------------
|
| Lưới cho mọi đường để lại tệp lệch mà không ai biết: `DefaultFileRemover` nuốt lỗi khi xoá media,
| một job bị giết sau khi tải lên mà trước khi ghi chỉ mục, Drive cho trùng tên, thư mục vùng đệm của
| media đã xoá (rà soát Task 3, m1, m2), và tệp thăm dò `preflight/` của kiểm tra sẵn sàng (rà soát
| Task 5, m7). Không lệnh xoá hay thùng rác nào được gửi; không dòng nào bị ghi.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 20:00:00', 'Asia/Ho_Chi_Minh'));
    $this->clean = StagingFixtures::media('%PDF-1.4 sach');
    $this->drive = Ops::ready(RemoteDocuments::bindRealDriveAdapter([$this->clean]));
});

it('kho sạch → mã 0, không nhóm lỗi nào', function () {
    [$exit, $output] = Ops::artisan('vkcrm:storage:orphans');

    expect($exit)->toBe(0, $output)->and($output)->not->toContain('#'.$this->clean->id);
});

it('liệt kê mọi nhóm; không request ghi/xoá nào, không dòng nào đổi, thư mục vùng đệm mồ côi còn nguyên', function () {
    // 1. Dòng chỉ mục sống không còn media (media bị xoá mà thùng rác hỏng, rà soát Task 3 m2).
    $ghostKey = '555555/'.strtolower((string) Str::ulid()).'.pdf';
    $this->drive->seed($ghostKey, 'ma');

    // 2. Tệp trên Drive không có trong chỉ mục (job bị giết sau khi tải lên).
    $loose = DriveObjectName::fromKey('666666/'.strtolower((string) Str::ulid()).'.pdf');
    $this->drive->putFile($loose, 'roi', [FakeGoogleDrive::ROOT_FOLDER_ID]);

    // 3. Media trên kho mà chỉ mục không có tệp.
    $lost = Store::media(['disk' => DocumentStore::REMOTE_DISK, 'conversions_disk' => DocumentStore::REMOTE_DISK]);

    // 4. Hai tệp trùng tên trên Drive.
    $twin = DriveObjectName::fromKey('777777/'.strtolower((string) Str::ulid()).'.pdf');
    $this->drive->putFile($twin, 'mot', [FakeGoogleDrive::ROOT_FOLDER_ID]);
    $this->drive->putFile($twin, 'hai', [FakeGoogleDrive::ROOT_FOLDER_ID]);

    // 5. Tệp thăm dò preflight còn sống (thùng rác hỏng).
    $probeKey = 'preflight/'.str_repeat('b', 26).'.txt';
    $this->drive->seed($probeKey, 'tham do');

    // 6. Thư mục vùng đệm của một media đã không còn dòng (rà soát Task 3 m1).
    DocumentStore::staging()->put('424242/'.strtolower((string) Str::ulid()).'.pdf', 'mo coi');

    $rows = DriveObject::query()->orderBy('id')->get()->toArray();
    $writesBefore = Ops::writeRequests();

    [$exit, $output] = Ops::artisan('vkcrm:storage:orphans');

    expect($exit)->toBe(1)
        ->and($output)->toMatch('/không có media[^\n]*'.preg_quote($ghostKey, '/').'/u')
        ->and($output)->toMatch('/không có trong chỉ mục[^\n]*'.preg_quote($loose, '/').'/u')
        ->and($output)->toMatch('/không có tệp[^\n]*#'.$lost.'/u')
        ->and($output)->toMatch('/trùng tên[^\n]*'.preg_quote($twin, '/').'/u')
        ->and($output)->toMatch('/thăm dò[^\n]*'.preg_quote($probeKey, '/').'/u')
        ->and($output)->toMatch('/vùng đệm[^\n]*424242/u')
        ->and($output)->not->toContain('#'.$this->clean->id)
        ->and(Ops::writeRequests())->toBe($writesBefore)
        ->and(DriveObject::query()->orderBy('id')->get()->toArray())->toBe($rows)
        ->and(DocumentStore::staging()->directories())->toContain('424242');
});

it('tệp thăm dò preflight còn sống chỉ là thông tin: mã 0 nếu chỉ có nó', function () {
    $this->drive->seed('preflight/'.str_repeat('c', 26).'.txt', 'tham do');

    [$exit, $output] = Ops::artisan('vkcrm:storage:orphans');

    expect($exit)->toBe(0, $output)->and($output)->toContain('preflight/');
});

it('media của vụ đã ghi huỷ mà không còn tệp: nhóm riêng, không tính là lỗi', function () {
    $matter = Matter::factory()->create(['closed_at' => now()->subYears(11)->toDateString()]);
    MatterArchive::factory()->create([
        'matter_id' => $matter->id,
        'retention_until' => now()->subYear()->toDateString(),
        'destroyed_at' => now()->subMonth(),
    ]);
    $document = Document::factory()->for($matter)->create();
    $destroyed = Store::media([
        'model_id' => $document->id,
        'disk' => DocumentStore::REMOTE_DISK,
        'conversions_disk' => DocumentStore::REMOTE_DISK,
    ]);

    [$exit, $output] = Ops::artisan('vkcrm:storage:orphans');

    expect($exit)->toBe(0, $output)
        ->and($output)->toMatch('/huỷ[^\n]*#'.$destroyed.'/u')
        ->and($output)->not->toMatch('/không có tệp[^\n]*#'.$destroyed.'/u');
});

it('Drive không liệt kê được → vẫn báo các nhóm từ chỉ mục, nói rõ phần chưa kiểm, mã 1', function () {
    $lost = Store::media(['disk' => DocumentStore::REMOTE_DISK, 'conversions_disk' => DocumentStore::REMOTE_DISK]);
    $this->drive->failNext('GET', 'drive/v3/files?', 0, times: 10);

    [$exit, $output] = Ops::artisan('vkcrm:storage:orphans');

    expect($exit)->toBe(1)
        ->and($output)->toMatch('/không có tệp[^\n]*#'.$lost.'/u')
        ->and($output)->toContain('Không liệt kê được');
});
