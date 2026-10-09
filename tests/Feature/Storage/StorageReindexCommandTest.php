<?php

use App\Enums\DriveObjectRetirement;
use App\Models\DriveFolder;
use App\Models\DriveObject;
use App\Support\Storage\DocumentStore;
use App\Support\Storage\GoogleDrive\DriveObjectName;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\DocumentStoreFixtures as Store;
use Tests\Support\FakeGoogleDrive;
use Tests\Support\StorageCommandFixtures as Ops;

/*
|--------------------------------------------------------------------------
| M14 Task 6 — `vkcrm:storage:reindex --drive=<id> --root=<id> [--dry-run]` (R4, R11)
|--------------------------------------------------------------------------
|
| Dựng lại chỉ mục `drive_objects` của MỘT Shared Drive từ danh sách tệp (tên → khoá + thế hệ), và
| `drive_folders` của thư mục gốc từ các thư mục tháng có sẵn. Hai tuỳ chọn phải bằng cấu hình. Chọn
| bản có md5 bằng `media.checksum_md5` (thế hệ cao nhất có md5 khớp); phần còn lại chỉ được báo. Dòng
| của Shared Drive khác nhường chỗ (`superseded`). Không bao giờ xoá dòng nào. Client THẬT trên máy chủ
| Drive giả.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 20:00:00', 'Asia/Ho_Chi_Minh'));
    $this->drive = Ops::ready();
    $this->month = Ops::monthFolder($this->drive, '2026-10');
});

/** Một media đã ở kho với md5 của `$content`; trả [id, khoá]. */
function t6IndexedMedia(string $content, bool $withChecksum = true): array
{
    $id = Store::media([
        'disk' => DocumentStore::REMOTE_DISK,
        'conversions_disk' => DocumentStore::REMOTE_DISK,
        'size' => strlen($content),
        'checksum_md5' => $withChecksum ? md5($content) : null,
    ]);

    return [$id, $id.'/'.DB::table('media')->where('id', $id)->value('file_name')];
}

function t6Reindex(array $options = []): array
{
    return Ops::artisan('vkcrm:storage:reindex', array_merge([
        '--drive' => FakeGoogleDrive::DRIVE_ID,
        '--root' => FakeGoogleDrive::ROOT_FOLDER_ID,
    ], $options));
}

it('dựng lại đúng khoá, thế hệ, mã tệp, thư mục cha, md5, cỡ; và thư mục tháng vào drive_folders', function () {
    [, $a] = t6IndexedMedia('noi dung a');
    [, $b] = t6IndexedMedia('noi dung b');
    $fileA = $this->drive->putFile(DriveObjectName::fromKey($a), 'noi dung a', [$this->month], 'application/pdf');
    $fileB = $this->drive->putFile(DriveObjectName::fromKey($b, 3), 'noi dung b', [$this->month], 'application/pdf');

    [$exit, $output] = t6Reindex();

    $rowA = DriveObject::query()->where('object_key', $a)->sole();
    $rowB = DriveObject::query()->where('object_key', $b)->sole();

    expect($exit)->toBe(0, $output)
        ->and([$rowA->drive_id, $rowA->generation, $rowA->file_id, $rowA->parent_id, $rowA->md5, $rowA->size, $rowA->mime_type])
        ->toBe([FakeGoogleDrive::DRIVE_ID, 1, $fileA, $this->month, md5('noi dung a'), 10, 'application/pdf'])
        ->and([$rowB->generation, $rowB->file_id])->toBe([3, $fileB])
        ->and(DriveFolder::query()->where('root_folder_id', FakeGoogleDrive::ROOT_FOLDER_ID)->pluck('folder_id', 'name')->all())
        ->toBe(['2026-10' => $this->month]);
});

it('--dry-run in khác biệt, không ghi dòng nào', function () {
    [, $a] = t6IndexedMedia('noi dung a');
    $this->drive->putFile(DriveObjectName::fromKey($a), 'noi dung a', [$this->month]);

    [$exit, $output] = t6Reindex(['--dry-run' => true]);

    expect($exit)->toBe(0)
        ->and($output)->toMatch('/thêm[^\n]*: 1/u')
        ->and(DriveObject::query()->count())->toBe(0)
        ->and(DriveFolder::query()->count())->toBe(0);
});

it('--drive hay --root khác cấu hình → mã 2, không request nào', function (string $option) {
    Http::fake();

    [$exit, $output] = t6Reindex([$option => 'MotDriveKhac']);

    expect($exit)->toBe(2)->and($output)->toContain('optimize');
    Http::assertNothingSent();
})->with(['--drive', '--root']);

it('khoá đang sống ở Shared Drive khác → dòng cũ thành superseded, dòng mới sống, không vi phạm unique', function () {
    [, $key] = t6IndexedMedia('noi dung');
    $old = Store::driveObject(['drive_id' => '0AOldSharedDrive', 'object_key' => $key, 'md5' => md5('noi dung'), 'size' => 8]);
    $file = $this->drive->putFile(DriveObjectName::fromKey($key), 'noi dung', [$this->month]);

    [$exit, $output] = t6Reindex();

    $previous = DriveObject::query()->findOrFail($old);
    expect($exit)->toBe(0, $output)
        ->and($previous->object_key)->toBeNull()
        ->and($previous->former_key)->toBe($key)
        ->and($previous->retired_reason)->toBe(DriveObjectRetirement::Superseded)
        ->and(DriveObject::query()->where('object_key', $key)->sole()->file_id)->toBe($file);
});

it('một lượt đẩy sau reindex không tạo thư mục tháng thứ hai', function () {
    t6Reindex();
    $this->drive->disk();

    DocumentStore::remote()->put('99/'.strtolower((string) Str::ulid()).'.pdf', 'tep moi');

    expect($this->drive->folders())->toHaveCount(1)
        ->and(DriveFolder::query()->count())->toBe(1);
});

it('hai tệp trùng tên, một khớp md5 → chọn đúng tệp đó; tệp kia được báo, không ghi, không xoá', function () {
    [, $key] = t6IndexedMedia('ban dung');
    $this->drive->putFile(DriveObjectName::fromKey($key), 'ban hong', [$this->month]);
    $good = $this->drive->putFile(DriveObjectName::fromKey($key), 'ban dung', [$this->month]);

    [$exit, $output] = t6Reindex();

    expect(DriveObject::query()->where('object_key', $key)->sole()->file_id)->toBe($good)
        ->and(DriveObject::query()->count())->toBe(1)
        ->and($output)->toContain(DriveObjectName::fromKey($key))
        ->and(Ops::writeRequests())->toBe([]);
});

it('nhiều thế hệ: chọn thế hệ CAO NHẤT có md5 khớp', function () {
    [, $key] = t6IndexedMedia('ban dung');
    // Thế hệ 1 và 2 cùng khớp md5 (tải lại cùng nội dung); thế hệ 3 lệch. Chọn thế hệ 2.
    $g1 = $this->drive->putFile(DriveObjectName::fromKey($key), 'ban dung', [$this->month]);
    $g2 = $this->drive->putFile(DriveObjectName::fromKey($key, 2), 'ban dung', [$this->month]);
    $this->drive->putFile(DriveObjectName::fromKey($key, 3), 'ban hong', [$this->month]);

    t6Reindex();

    $row = DriveObject::query()->where('object_key', $key)->sole();
    expect([$row->generation, $row->file_id])->toBe([2, $g2])
        ->and($row->file_id)->not->toBe($g1);
});

it('media không có checksum_md5 → không chọn, chỉ báo', function () {
    [, $key] = t6IndexedMedia('noi dung', withChecksum: false);
    $this->drive->putFile(DriveObjectName::fromKey($key), 'noi dung', [$this->month]);

    [, $output] = t6Reindex();

    expect(DriveObject::query()->count())->toBe(0)
        ->and($output)->toMatch('/checksum[^\n]*: 1/u');
});

it('tên lạ (chỉ đếm) và tệp thăm dò preflight bị báo, không ghi; tệp không còn media bị báo, không ghi', function () {
    $this->drive->putFile('ghi-chu-cua-ai-do.txt', 'x', [$this->month]);
    $this->drive->putFile('preflight~'.str_repeat('a', 26).'.txt', 'x', [$this->month]);
    $this->drive->putFile(DriveObjectName::fromKey('987654/'.strtolower((string) Str::ulid()).'.pdf'), 'x', [$this->month]);

    [$exit, $output] = t6Reindex();

    expect($exit)->toBe(0)
        ->and(DriveObject::query()->count())->toBe(0)
        // Tên do người đặt tay có thể mang tên khách: chỉ đếm, không in.
        ->and($output)->toMatch('/Tên lạ[^\n]*: 1/u')
        ->and($output)->not->toContain('ghi-chu-cua-ai-do')
        ->and($output)->toMatch('/thăm dò[^\n]*: 1/u')
        ->and($output)->toMatch('/không còn media[^\n]*: 1/u');
});

it('không bao giờ xoá dòng nào: dòng đã rời và dòng sống cũ vẫn còn', function () {
    [, $key] = t6IndexedMedia('noi dung');
    $retired = Store::driveObject(['former_key' => $key, 'retired_reason' => 'trashed', 'retired_at' => now()]);
    $other = Store::driveObject(['drive_id' => '0AOldSharedDrive', 'object_key' => $key]);
    $this->drive->putFile(DriveObjectName::fromKey($key, 2), 'noi dung', [$this->month]);

    t6Reindex();

    expect(DriveObject::query()->whereKey([$retired, $other])->count())->toBe(2)
        ->and(DriveObject::query()->count())->toBe(3);
});

it('dòng sống đã trỏ đúng tệp trên drive này → giữ nguyên, không ghi gì', function () {
    [, $key] = t6IndexedMedia('noi dung');
    $file = $this->drive->putFile(DriveObjectName::fromKey($key), 'noi dung', [$this->month]);
    $row = Store::driveObject(['object_key' => $key, 'file_id' => $file, 'md5' => md5('noi dung'), 'size' => 8, 'parent_id' => $this->month]);

    [$exit, $output] = t6Reindex();

    expect($exit)->toBe(0)
        ->and(DriveObject::query()->count())->toBe(1)
        ->and(DriveObject::query()->findOrFail($row)->object_key)->toBe($key)
        ->and($output)->toMatch('/giữ nguyên[^\n]*: 1/u');
});
