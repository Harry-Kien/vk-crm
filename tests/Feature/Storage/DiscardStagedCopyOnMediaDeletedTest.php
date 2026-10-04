<?php

use App\Actions\Storage\PushDocumentFileToRemote;
use App\Enums\PushOutcome;
use App\Support\Storage\DocumentStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Support\StagingFixtures;

/*
|--------------------------------------------------------------------------
| M14 Task 3 — media bị xoá: bản trong vùng đệm đi theo, nhưng chỉ sau commit (kế hoạch M14, R2)
|--------------------------------------------------------------------------
|
| Thư viện media tự xoá tệp trên `media.disk` ở sự kiện `deleted`, KHÔNG chờ commit (vendor). Với media
| đã lên kho, đĩa đó là kho: bản trong vùng đệm `private/<media_id>/` không ai xoá. Listener
| `DiscardStagedCopyOnMediaDeleted` xoá nó, với ba điều kiện:
|
|  - `media.disk` khác `private` (media ở `private` thì thư viện đã tự xoá đúng thư mục đó);
|  - chạy SAU commit: một lượt xoá bị rollback không được để dòng `media`, đã sống lại, mất bản
|    trong vùng đệm;
|  - đọc lại: dòng `media` thật sự không còn.
*/

/** Một media đã đẩy lên kho (đĩa giả), bản trong vùng đệm còn. */
function stgPushedMedia(): Media
{
    $media = StagingFixtures::media();
    StagingFixtures::enableRemote();

    expect(app(PushDocumentFileToRemote::class)->handle($media->id))->toBe(PushOutcome::Pushed);

    $media = Media::query()->findOrFail($media->id);

    expect($media->disk)->toBe(DocumentStore::REMOTE_DISK);
    Storage::disk(DocumentStore::STAGING_DISK)->assertExists($media->getPathRelativeToRoot());

    return $media;
}

it('media trên kho bị xoá trong transaction đã commit: bản trong vùng đệm bị xoá theo, cả thư mục', function () {
    $media = stgPushedMedia();
    $key = $media->getPathRelativeToRoot();

    DB::transaction(fn () => $media->delete());

    Storage::disk(DocumentStore::STAGING_DISK)->assertMissing($key);
    expect(Storage::disk(DocumentStore::STAGING_DISK)->allFiles())->toBe([])
        ->and(Storage::disk(DocumentStore::STAGING_DISK)->allDirectories())->toBe([]);
    // Thư viện media đã xoá bản trên kho (đĩa của media).
    Storage::disk(DocumentStore::REMOTE_DISK)->assertMissing($key);
});

it('xoá ngoài mọi transaction cũng dọn bản trong vùng đệm', function () {
    $media = stgPushedMedia();

    $media->delete();

    Storage::disk(DocumentStore::STAGING_DISK)->assertMissing($media->getPathRelativeToRoot());
});

it('lượt xoá trong một transaction ROLLBACK: dòng media còn, bản trong vùng đệm còn', function () {
    $media = stgPushedMedia();
    $key = $media->getPathRelativeToRoot();

    try {
        DB::transaction(function () use ($media): void {
            $media->delete();

            throw new RuntimeException('huỷ có chủ đích');
        });
    } catch (RuntimeException) {
        // Mong đợi.
    }

    expect(Media::query()->whereKey($media->id)->exists())->toBeTrue();
    Storage::disk(DocumentStore::STAGING_DISK)->assertExists($key);
});

it('sự kiện deleted mà dòng media vẫn còn (đọc lại): không xoá bản trong vùng đệm', function () {
    $media = stgPushedMedia();

    event('eloquent.deleted: '.$media::class, $media);

    expect(Media::query()->whereKey($media->id)->exists())->toBeTrue();
    Storage::disk(DocumentStore::STAGING_DISK)->assertExists($media->getPathRelativeToRoot());
});

it('media còn ở private bị xoá: chỉ thư viện media xoá thư mục của nó, listener không xoá lần hai', function () {
    $media = StagingFixtures::media();
    $directory = (string) $media->id;
    $staging = StagingFixtures::hookStaging();

    $media->delete();

    Storage::disk(DocumentStore::STAGING_DISK)->assertMissing($media->getPathRelativeToRoot());

    // Thư mục của media, viết có hay không có `/` cuối (thư viện truyền `1/`).
    $deletions = array_filter($staging->calls, fn (string $call): bool => in_array($call, ['deleteDirectory:'.$directory, 'deleteDirectory:'.$directory.'/'], true));

    expect($deletions)->toHaveCount(1);
});
