<?php

use App\Enums\DriveObjectRetirement;
use App\Models\DriveObject;
use App\Support\Storage\GoogleDrive\DriveObjectIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| M14 Task 2 — DriveObjectIndex: chỉ mục khoá → tệp Drive (kế hoạch M14, R4, R8)
|--------------------------------------------------------------------------
|
| Dòng sống có `object_key`; dòng đã rời giữ khoá ở `former_key` và không bao giờ bị xoá. Rời chỉ mục
| và đổi khoá là câu UPDATE có điều kiện trên khoá đang thấy: một đối tượng đã cũ (dòng đã bị người
| khác cho rời, hay đổi khoá) không ghi đè được trạng thái mới hơn.
*/

uses(RefreshDatabase::class);

function indexRow(array $attributes = []): DriveObject
{
    static $sequence = 0;
    $sequence++;

    return DriveObject::query()->create(array_merge([
        'drive_id' => 'drive',
        'object_key' => '18/a'.$sequence.'.pdf',
        'generation' => 1,
        'file_id' => 'file-'.$sequence,
        'parent_id' => 'folder',
        'size' => 1,
        'md5' => str_repeat('a', 32),
        'mime_type' => 'application/pdf',
    ], $attributes));
}

it('thế hệ kế tiếp của khoá chưa từng có là 1; sau các dòng đã rời là thế hệ cao nhất + 1', function () {
    $index = new DriveObjectIndex;

    expect($index->nextGeneration('18/a.pdf'))->toBe(1);

    // Thế hệ không liền nhau (1 và 4): đếm dòng sẽ trả 3, một tên có thể đã nằm trên Drive.
    indexRow(['object_key' => null, 'former_key' => '18/a.pdf', 'generation' => 1, 'retired_reason' => DriveObjectRetirement::Trashed]);
    indexRow(['object_key' => null, 'former_key' => '18/a.pdf', 'generation' => 4, 'retired_reason' => DriveObjectRetirement::Superseded]);
    indexRow(['object_key' => null, 'former_key' => '18/khac.pdf', 'generation' => 7, 'retired_reason' => DriveObjectRetirement::Trashed]);

    expect($index->nextGeneration('18/a.pdf'))->toBe(5);
});

it('retire: dòng rời chỉ mục sống với khoá cũ, lý do và lúc', function () {
    $row = indexRow(['object_key' => '18/a.pdf']);

    (new DriveObjectIndex)->retire($row, DriveObjectRetirement::Trashed);

    $row->refresh();

    expect($row->object_key)->toBeNull()
        ->and($row->former_key)->toBe('18/a.pdf')
        ->and($row->retired_reason)->toBe(DriveObjectRetirement::Trashed)
        ->and($row->retired_at)->not->toBeNull();
});

it('retire trên một đối tượng đã cũ không ghi đè dòng đã rời vì lý do khác', function () {
    $row = indexRow(['object_key' => '18/a.pdf']);
    $stale = DriveObject::query()->findOrFail($row->id);

    DriveObject::query()->whereKey($row->id)->update([
        'object_key' => null,
        'former_key' => '18/a.pdf',
        'retired_reason' => DriveObjectRetirement::Superseded->value,
        'retired_at' => now()->subDay(),
    ]);

    (new DriveObjectIndex)->retire($stale, DriveObjectRetirement::Trashed);

    expect($row->fresh()->retired_reason)->toBe(DriveObjectRetirement::Superseded);
});

it('rekey trên một đối tượng đã cũ không đổi dòng', function () {
    $row = indexRow(['object_key' => '18/a.pdf']);
    $stale = DriveObject::query()->findOrFail($row->id);
    DriveObject::query()->whereKey($row->id)->update(['object_key' => '18/b.pdf']);

    (new DriveObjectIndex)->rekey($stale, '18/c.pdf', 4);

    expect($row->fresh()->object_key)->toBe('18/b.pdf')
        ->and($row->fresh()->generation)->toBe(1);
});

it('liveUnder và hasLiveUnder chỉ thấy dòng sống, theo thứ tự khoá; gốc là mọi dòng sống', function () {
    indexRow(['object_key' => '18/b.pdf']);
    indexRow(['object_key' => '18/a.pdf']);
    indexRow(['object_key' => null, 'former_key' => '18/c.pdf', 'retired_reason' => DriveObjectRetirement::Trashed]);
    indexRow(['object_key' => '19/a.pdf']);
    $index = new DriveObjectIndex;

    expect($index->liveUnder('18')->pluck('object_key')->all())->toBe(['18/a.pdf', '18/b.pdf'])
        ->and($index->liveUnder('')->pluck('object_key')->all())->toBe(['18/a.pdf', '18/b.pdf', '19/a.pdf'])
        ->and($index->hasLiveUnder('19'))->toBeTrue()
        ->and($index->hasLiveUnder('20'))->toBeFalse();
});
