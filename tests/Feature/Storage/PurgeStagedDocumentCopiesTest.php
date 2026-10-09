<?php

use App\Actions\Schedule\PurgeStagedDocumentCopies;
use App\Actions\Storage\PushDocumentFileToRemote;
use App\Enums\DriveObjectRetirement;
use App\Enums\PushOutcome;
use App\Models\DriveObject;
use App\Support\Storage\DocumentStore;
use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Support\StagingFixtures;

/*
|--------------------------------------------------------------------------
| M14 Task 3 — dọn bản trong vùng đệm chỉ khi đã có bản ngoài Google (kế hoạch M14, R10)
|--------------------------------------------------------------------------
|
| `PurgeStagedDocumentCopies` (mỗi giờ) xoá `private/<media_id>/` và đặt `local_purge_after = NULL`
| khi CẢ BỐN điều đúng:
|   1. `media.disk = documents_remote`;
|   2. `local_purge_after` có và đã qua;
|   3. có dòng `drive_objects` SỐNG của khoá đó, đúng `drive_id` đang cấu hình, md5 = `checksum_md5`;
|   4. dòng đó có `office_copied_at <= now − office.purge_margin_hours` (24).
|
| Mỗi điều kiện có hai test, mỗi test chỉ sai ĐÚNG điều đó:
|   - sai từ trước khi chạy → giữ, và KHÔNG lấy khoá đẩy cho media đó (lọc trước khi khoá);
|   - đúng lúc lọc, sai đúng lúc lấy khoá (móc ở bước lấy khoá) → giữ: lần đọc lại dưới khoá thắng.
|
| `office_copied_at` được đặt thẳng trong test: lượt nhập biên nhận văn phòng (Task 7) nằm ở làn khác;
| luật "chỉ ImportOfficeReceipts ghi cột này" là test cấu trúc của Task 7, quét `app/`.
*/

const STG_DRIVE_ID = 'drive-kho-1';

beforeEach(function () {
    $this->freezeSecond();
    config(['vkcrm.storage.google_drive.shared_drive_id' => STG_DRIVE_ID]);
});

/**
 * Một media đủ bốn điều để dọn: đã đẩy lên kho (đĩa giả) 25 giờ trước, dòng chỉ mục sống khớp md5,
 * biên nhận văn phòng 24 giờ 1 phút trước.
 *
 * @return array{0: Media, 1: DriveObject}
 */
function stgPurgeable(string $content = '%PDF-1.4 ban can don'): array
{
    $media = StagingFixtures::media($content);
    StagingFixtures::enableRemote();

    expect(app(PushDocumentFileToRemote::class)->handle($media->id))->toBe(PushOutcome::Pushed);

    test()->travel(25)->hours();

    $object = DriveObject::query()->create([
        'drive_id' => STG_DRIVE_ID,
        'object_key' => $media->getPathRelativeToRoot(),
        'generation' => 1,
        'file_id' => 'f-'.$media->id,
        'parent_id' => 'thu-muc-thang',
        'size' => strlen($content),
        'md5' => md5($content),
        'mime_type' => 'application/pdf',
    ]);
    DriveObject::query()->whereKey($object->id)->update(['office_copied_at' => now()->subHours(24)->subMinute()]);

    return [Media::query()->findOrFail($media->id), $object->refresh()];
}

/**
 * Store khoá đẩy đếm được và móc được: ghi tên mỗi khoá được XIN, và chạy `$onAcquire($name)` ngay
 * trước khi khoá được cấp.
 */
function stgHookLocks(?Closure $onAcquire = null): ArrayObject
{
    $requested = new ArrayObject;

    $store = new class($requested, $onAcquire) extends ArrayStore
    {
        public function __construct(public ArrayObject $requested, public ?Closure $onAcquire)
        {
            parent::__construct();
        }

        public function lock($name, $seconds = 0, $owner = null)
        {
            return new class($this, $name, $seconds, $owner) extends ArrayLock
            {
                public function acquire()
                {
                    $this->store->requested[] = $this->name;

                    if ($this->store->onAcquire !== null) {
                        ($this->store->onAcquire)($this->name);
                    }

                    return parent::acquire();
                }
            };
        }
    };

    Cache::extend('stg-hooked-locks', fn () => Cache::repository($store));
    config([
        'cache.stores.stg-hooked-locks' => ['driver' => 'stg-hooked-locks'],
        'vkcrm.storage.lock_store' => 'stg-hooked-locks',
    ]);

    return $requested;
}

/** Mỗi trạng thái làm sai ĐÚNG MỘT điều kiện dọn. */
function stgBreaks(): array
{
    return [
        'media ở private (trạng thái bản quay lui cũ để lại), dù đã quá hạn và có biên nhận khớp' => [fn (Media $media, DriveObject $object) => Media::query()->whereKey($media->id)->update(['disk' => DocumentStore::STAGING_DISK, 'conversions_disk' => DocumentStore::STAGING_DISK])],
        'chưa tới local_purge_after' => [fn (Media $media, DriveObject $object) => Media::query()->whereKey($media->id)->update(['local_purge_after' => now()->addMinute()])],
        'local_purge_after là NULL' => [fn (Media $media, DriveObject $object) => Media::query()->whereKey($media->id)->update(['local_purge_after' => null])],
        'không có dòng chỉ mục sống' => [fn (Media $media, DriveObject $object) => DriveObject::query()->whereKey($object->id)->update(['object_key' => null, 'former_key' => $object->object_key, 'retired_reason' => DriveObjectRetirement::Trashed->value, 'retired_at' => now()])],
        'dòng chỉ mục ở drive_id khác' => [fn (Media $media, DriveObject $object) => DriveObject::query()->whereKey($object->id)->update(['drive_id' => 'drive-cu'])],
        'md5 chỉ mục khác checksum_md5' => [fn (Media $media, DriveObject $object) => DriveObject::query()->whereKey($object->id)->update(['md5' => str_repeat('f', 32)])],
        'office_copied_at là NULL' => [fn (Media $media, DriveObject $object) => DriveObject::query()->whereKey($object->id)->update(['office_copied_at' => null])],
        'office_copied_at mới hơn biên độ 24 giờ' => [fn (Media $media, DriveObject $object) => DriveObject::query()->whereKey($object->id)->update(['office_copied_at' => now()->subHours(23)])],
    ];
}

it('đủ bốn điều: xoá bản trong vùng đệm (cả thư mục), local_purge_after về NULL, media vẫn ở kho, không chạm kho', function () {
    [$media] = stgPurgeable();
    $key = $media->getPathRelativeToRoot();
    $remote = StagingFixtures::hookRemote();

    expect((new PurgeStagedDocumentCopies)())->toMatchArray(['purged' => 1, 'kept' => 0, 'locked' => 0, 'failed' => 0]);

    Storage::disk(DocumentStore::STAGING_DISK)->assertMissing($key);
    expect(Storage::disk(DocumentStore::STAGING_DISK)->allDirectories())->toBe([])
        ->and(StagingFixtures::row($media->id)->local_purge_after)->toBeNull()
        ->and(StagingFixtures::row($media->id)->disk)->toBe(DocumentStore::REMOTE_DISK)
        ->and($remote->calls)->toBe([]);
    Storage::disk(DocumentStore::REMOTE_DISK)->assertExists($key);
});

it('biên nhận đúng biên độ 24 giờ thì dọn được', function () {
    [$media, $object] = stgPurgeable();
    DriveObject::query()->whereKey($object->id)->update(['office_copied_at' => now()->subHours(24)]);

    expect((new PurgeStagedDocumentCopies)()['purged'])->toBe(1);
});

it('local_purge_after đúng lúc này thì dọn được', function () {
    [$media] = stgPurgeable();
    Media::query()->whereKey($media->id)->update(['local_purge_after' => now()]);

    expect((new PurgeStagedDocumentCopies)()['purged'])->toBe(1);
});

it('bản cục bộ đã mất từ trước: chỉ đặt lại cột', function () {
    [$media] = stgPurgeable();
    Storage::disk(DocumentStore::STAGING_DISK)->deleteDirectory((string) $media->id);

    expect((new PurgeStagedDocumentCopies)()['purged'])->toBe(1)
        ->and(StagingFixtures::row($media->id)->local_purge_after)->toBeNull();
});

it('sai một điều từ trước khi chạy → giữ bản cục bộ, và không xin khoá đẩy cho media đó', function (Closure $break) {
    [$media, $object] = stgPurgeable();
    $break($media, $object);
    $before = StagingFixtures::row($media->id);
    $requested = stgHookLocks();

    expect((new PurgeStagedDocumentCopies)()['purged'])->toBe(0)
        ->and($requested->getArrayCopy())->toBe([]);

    Storage::disk(DocumentStore::STAGING_DISK)->assertExists($media->getPathRelativeToRoot());
    expect(StagingFixtures::row($media->id))->toEqual($before);
})->with(stgBreaks());

it('đúng lúc lọc nhưng sai đúng lúc lấy khoá → giữ (đọc lại dưới khoá)', function (Closure $break) {
    [$media, $object] = stgPurgeable();
    $requested = stgHookLocks(function (string $name) use ($break, $media, $object): void {
        if ($name === 'document-push:'.$media->id) {
            $break($media, $object);
        }
    });

    $result = (new PurgeStagedDocumentCopies)();

    expect($requested->getArrayCopy())->toBe(['document-push:'.$media->id])
        ->and($result)->toMatchArray(['purged' => 0, 'kept' => 1]);
    Storage::disk(DocumentStore::STAGING_DISK)->assertExists($media->getPathRelativeToRoot());
})->with(stgBreaks());

it('câu UPDATE có điều kiện disk = documents_remote: dòng đổi về private ngay sau lúc xoá thư mục thì local_purge_after không bị xoá', function () {
    [$media] = stgPurgeable();
    $before = StagingFixtures::row($media->id)->local_purge_after;

    StagingFixtures::hookStaging(['afterDeleteDirectory' => fn () => Media::query()->whereKey($media->id)->update(['disk' => DocumentStore::STAGING_DISK])]);

    (new PurgeStagedDocumentCopies)();

    expect(StagingFixtures::row($media->id)->local_purge_after)->toBe($before);
});

it('không lấy được khoá đẩy: bỏ qua dòng đó; lượt sau dọn được', function () {
    [$media] = stgPurgeable();

    $held = DocumentStore::pushLock($media->id);
    expect($held->get())->toBeTrue();

    expect((new PurgeStagedDocumentCopies)())->toMatchArray(['purged' => 0, 'locked' => 1]);
    Storage::disk(DocumentStore::STAGING_DISK)->assertExists($media->getPathRelativeToRoot());

    $held->release();

    expect((new PurgeStagedDocumentCopies)()['purged'])->toBe(1);
    Storage::disk(DocumentStore::STAGING_DISK)->assertMissing($media->getPathRelativeToRoot());
});

it('chỉ dọn media đủ điều kiện, giữ media bên cạnh', function () {
    [$ready] = stgPurgeable('%PDF-1.4 mot');
    $other = StagingFixtures::media('%PDF-1.4 hai');
    expect(app(PushDocumentFileToRemote::class)->handle($other->id))->toBe(PushOutcome::Pushed);

    (new PurgeStagedDocumentCopies)();

    Storage::disk(DocumentStore::STAGING_DISK)->assertMissing($ready->getPathRelativeToRoot());
    Storage::disk(DocumentStore::STAGING_DISK)->assertExists($other->getPathRelativeToRoot());
});

it('dòng không là ứng viên (ở private, hay chưa tới hạn) không làm lượt dọn hỏi bảng drive_objects', function () {
    [$media, $object] = stgPurgeable();
    // Ở private nhưng mang đủ dấu vết của một media đã đẩy (mốc đã qua, md5): chỉ đĩa loại nó.
    $private = StagingFixtures::media('%PDF-1.4 o vung dem');
    Media::query()->whereKey($private->id)->update(['local_purge_after' => now()->subDay(), 'checksum_md5' => md5('%PDF-1.4 o vung dem')]);
    Media::query()->whereKey($media->id)->update(['local_purge_after' => now()->addDay()]);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    (new PurgeStagedDocumentCopies)();

    expect(array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'drive_objects'))))->toBe([]);
});

it('cấu trúc: lượt dọn không bao giờ chạm đĩa kho hay mạng', function () {
    $source = (string) file_get_contents(app_path('Actions/Schedule/PurgeStagedDocumentCopies.php'));
    $code = implode('', array_map(
        fn ($token) => is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1]) : $token,
        token_get_all($source),
    ));

    foreach (['DocumentStore::remote(', 'Http::', 'Illuminate\\Support\\Facades\\Http', 'Storage::disk(', 'GoogleDrive\\', 'DriveClient', 'DriveAdapter', "'documents_remote'"] as $forbidden) {
        expect(str_contains($code, $forbidden))->toBeFalse("PurgeStagedDocumentCopies chứa {$forbidden}");
    }

    // Tự kiểm: bộ quét đọc được mã thật của lớp (không phải tệp rỗng).
    expect($code)->toContain('DocumentStore::pushLock(');
});
