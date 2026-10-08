<?php

use App\Exceptions\StoredFileTrashed;
use App\Models\Document;
use App\Models\DriveObject;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Support\Storage\DocumentStore;
use Carbon\CarbonImmutable;
use League\Flysystem\UnableToProvideChecksum;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Support\RemoteDocuments;
use Tests\Support\StagingFixtures;
use Tests\Support\StorageCommandFixtures as Ops;

/*
|--------------------------------------------------------------------------
| M14 Task 6 — `vkcrm:storage:verify [--all|--sample=N]` (R11, R15)
|--------------------------------------------------------------------------
|
| So md5 do KHO tính (Drive: `md5Checksum` của Google) và cỡ với `media.checksum_md5`/`media.size`;
| báo tệp đã vào thùng rác, bị đổi, hay thiếu. Tệp của vụ đã ghi quyết định huỷ là nhóm riêng, không
| tính là lỗi. Chỉ đọc: không ghi, không thùng rác, không đổi dòng nào.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 20:00:00', 'Asia/Ho_Chi_Minh'));
});

/** Media đã lên kho (đĩa giả), vùng đệm đã dọn. */
function t6RemoteMedia(string $content, ?Document $document = null): Media
{
    return RemoteDocuments::pushToRemote(StagingFixtures::media($content, document: $document));
}

function t6DestroyedDocument(): Document
{
    $matter = Matter::factory()->create(['closed_at' => now()->subYears(11)->toDateString()]);
    MatterArchive::factory()->create([
        'matter_id' => $matter->id,
        'retention_until' => now()->subYear()->toDateString(),
        'destroyed_at' => now()->subMonth(),
        'destruction_reason' => 'Hết hạn lưu trữ theo quy chế của văn phòng.',
        'destruction_record_no' => 'BB-TH-01',
    ]);

    return Document::factory()->for($matter)->create();
}

it('--all trên đĩa giả có decorator: bắt tệp bị đổi nội dung, tệp đã vào thùng rác, tệp thiếu; mã thoát 1', function () {
    $ok = t6RemoteMedia('%PDF-1.4 dung');
    $changed = t6RemoteMedia('%PDF-1.4 se bi doi');
    $trashed = t6RemoteMedia('%PDF-1.4 vao thung rac');
    $missing = t6RemoteMedia('%PDF-1.4 mat');
    DocumentStore::remote()->delete($missing->getPathRelativeToRoot());

    StagingFixtures::hookRemote(['checksum' => function (string $path, string $real) use ($changed, $trashed) {
        if ($path === $trashed->getPathRelativeToRoot()) {
            throw new UnableToProvideChecksum('trashed', $path, StoredFileTrashed::forKey($path));
        }

        return $path === $changed->getPathRelativeToRoot() ? md5('khac') : $real;
    }]);

    [$exit, $output] = Ops::artisan('vkcrm:storage:verify', ['--all' => true]);

    expect($exit)->toBe(1)
        ->and($output)->toMatch('/khớp[^\n]*: 1/u')
        ->and($output)->toMatch('/bị đổi[^\n]*#'.$changed->id.'/u')
        ->and($output)->toMatch('/thùng rác[^\n]*#'.$trashed->id.'/u')
        ->and($output)->toMatch('/thiếu[^\n]*#'.$missing->id.'/u')
        ->and($output)->not->toContain('#'.$ok->id)
        // Rà soát cuối M14 vòng sửa 1 (I7c): không hứa "quay lui đúng media đó" — không có lệnh quay lui
        // một media; chỉ tới Phụ lục D (bản ở văn phòng) và thùng rác của Shared Drive.
        ->and($output)->toContain(__('storage.commands.verify.problems'))
        ->and(__('storage.commands.verify.problems'))->not->toContain('quay lui đúng media')
        ->toContain('Phụ lục D')
        ->toContain('thùng rác');
});

it('cỡ trên kho khác media.size → bị đổi', function () {
    $media = t6RemoteMedia('%PDF-1.4 co');
    StagingFixtures::hookRemote(['size' => fn (string $path, int $real) => $real + 1]);

    [$exit, $output] = Ops::artisan('vkcrm:storage:verify', ['--all' => true]);

    expect($exit)->toBe(1)->and($output)->toMatch('/bị đổi[^\n]*#'.$media->id.'/u');
});

it('mọi tệp khớp → mã 0; không đổi dòng media nào', function () {
    $media = t6RemoteMedia('%PDF-1.4 sach');
    $before = (array) StagingFixtures::row($media->id);

    [$exit, $output] = Ops::artisan('vkcrm:storage:verify', ['--all' => true]);

    expect($exit)->toBe(0, $output)
        ->and((array) StagingFixtures::row($media->id))->toBe($before);
});

it('tệp của vụ đã ghi quyết định huỷ: nhóm riêng, không tính là lỗi (mã 0 nếu chỉ có nhóm đó)', function () {
    $destroyed = t6RemoteMedia('%PDF-1.4 vu da huy', t6DestroyedDocument());
    t6RemoteMedia('%PDF-1.4 binh thuong');
    DocumentStore::remote()->delete($destroyed->getPathRelativeToRoot());

    [$exit, $output] = Ops::artisan('vkcrm:storage:verify', ['--all' => true]);

    expect($exit)->toBe(0, $output)
        ->and($output)->toMatch('/huỷ[^\n]*#'.$destroyed->id.'/u')
        ->and($output)->not->toMatch('/thiếu[^\n]*#'.$destroyed->id.'/u');
});

it('--sample=N kiểm đúng N tệp', function () {
    foreach (range(1, 4) as $i) {
        t6RemoteMedia('%PDF-1.4 mau '.$i);
    }
    $remote = StagingFixtures::hookRemote();

    [$exit, $output] = Ops::artisan('vkcrm:storage:verify', ['--sample' => 2]);

    expect($exit)->toBe(0, $output)
        ->and(array_count_values($remote->calls)['checksum'])->toBe(2)
        ->and($output)->toContain('2');
});

// ---------------------------------------------------------------------------------------------
// Adapter thật trên Drive giả
// ---------------------------------------------------------------------------------------------

it('adapter thật: tệp đã vào thùng rác trên Drive, tệp CRM đã cho vào thùng rác, tệp bị đổi; không request ghi nào', function () {
    $ok = StagingFixtures::media('%PDF-1.4 ok');
    $driveTrashed = StagingFixtures::media('%PDF-1.4 drive trashed');
    $crmTrashed = StagingFixtures::media('%PDF-1.4 crm trashed');
    $changed = StagingFixtures::media('%PDF-1.4 changed');
    $drive = Ops::ready(RemoteDocuments::bindRealDriveAdapter([$ok, $driveTrashed, $crmTrashed, $changed]));

    $fileOf = fn (Media $media): string => DriveObject::query()->where('object_key', $media->getPathRelativeToRoot())->value('file_id');
    $drive->files[$fileOf($driveTrashed)]['trashed'] = true;
    $drive->md5Overrides[$drive->files[$fileOf($changed)]['name']] = md5('noi dung khac');
    DocumentStore::remote()->delete($crmTrashed->getPathRelativeToRoot());
    $writesBefore = Ops::writeRequests();

    [$exit, $output] = Ops::artisan('vkcrm:storage:verify', ['--all' => true]);

    expect($exit)->toBe(1)
        ->and(Ops::writeRequests())->toBe($writesBefore)
        ->and($output)->toMatch('/thùng rác[^\n]*#'.$driveTrashed->id.'/u')
        ->and($output)->toMatch('/thùng rác[^\n]*#'.$crmTrashed->id.'/u')
        ->and($output)->toMatch('/bị đổi[^\n]*#'.$changed->id.'/u')
        ->and($output)->not->toContain('#'.$ok->id);
});
