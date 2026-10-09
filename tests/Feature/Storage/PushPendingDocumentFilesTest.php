<?php

use App\Actions\Schedule\PushPendingDocumentFiles;
use App\Jobs\PushDocumentFile;
use App\Models\Setting;
use App\Support\Storage\DocumentStore;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Support\StagingFixtures;

/*
|--------------------------------------------------------------------------
| M14 Task 3 — tác vụ quét `storage.push-pending` (kế hoạch M14, R2)
|--------------------------------------------------------------------------
|
| Mỗi 15 phút xếp lại job đẩy cho media còn ở `private` mà lẽ ra đã lên kho: tạo SAU mốc bật kho
| (cận dưới — tệp cũ chỉ đi qua lệnh chuyển ngoài giờ) và đã quá 10 phút (cận trên — job sau commit
| của listener còn đang chạy). Công tắc không còn `google_drive` mà mốc còn: xoá mốc, ghi nhật ký,
| không xếp gì (đổi về `local` là TẮT; bật lại phải chạy lệnh bật).
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-04 10:00:00', config('app.timezone')));
});

/** Media trong vùng đệm, tạo lúc `$createdAt` (giờ ứng dụng). Tạo TRƯỚC khi bật kho: listener không xếp gì. */
function stgMediaCreatedAt(CarbonInterface $createdAt, string $disk = DocumentStore::STAGING_DISK): Media
{
    $media = StagingFixtures::media();
    Media::query()->whereKey($media->id)->update(['created_at' => $createdAt, 'disk' => $disk]);

    return $media;
}

/** @return list<int> media đã được xếp job đẩy, theo thứ tự */
function stgQueuedMediaIds(): array
{
    return Queue::pushed(PushDocumentFile::class)->map(fn (PushDocumentFile $job): int => $job->mediaId)->values()->all();
}

it('xếp media ở private tạo sau mốc và đã quá 10 phút, trên hàng storage', function () {
    $media = stgMediaCreatedAt(now()->subMinutes(11));
    Queue::fake();
    StagingFixtures::enableRemote(now()->subHours(2));

    expect((new PushPendingDocumentFiles)()['queued'])->toBe(1)
        ->and(stgQueuedMediaIds())->toBe([$media->id]);
    Queue::assertPushedOn('storage', PushDocumentFile::class);
});

it('biên dưới: tạo đúng lúc bật kho thì được xếp; một giây trước mốc (tệp cũ) thì không', function () {
    $enabledAt = now()->subHours(2);
    $atMarker = stgMediaCreatedAt($enabledAt);
    stgMediaCreatedAt($enabledAt->copy()->subSecond());
    Queue::fake();
    StagingFixtures::enableRemote($enabledAt);

    (new PushPendingDocumentFiles)();

    expect(stgQueuedMediaIds())->toBe([$atMarker->id]);
});

it('biên trên: đúng 10 phút thì được xếp; 9 phút 59 giây thì chưa', function () {
    $tenMinutes = stgMediaCreatedAt(now()->subMinutes(10));
    stgMediaCreatedAt(now()->subMinutes(10)->addSecond());
    Queue::fake();
    StagingFixtures::enableRemote(now()->subHours(2));

    (new PushPendingDocumentFiles)();

    expect(stgQueuedMediaIds())->toBe([$tenMinutes->id]);
});

it('không xếp media đã nằm trên kho', function () {
    stgMediaCreatedAt(now()->subHour(), DocumentStore::REMOTE_DISK);
    $staged = stgMediaCreatedAt(now()->subHour());
    Queue::fake();
    StagingFixtures::enableRemote(now()->subHours(2));

    (new PushPendingDocumentFiles)();

    expect(stgQueuedMediaIds())->toBe([$staged->id]);
});

it('mốc ghi theo UTC vẫn so đúng thời điểm với created_at theo giờ ứng dụng', function () {
    // Mốc 01:00Z = 08:00 giờ ứng dụng (UTC+7). So chuỗi thô với created_at (giờ ứng dụng, không múi)
    // thì 07:30 cũng "sau" 01:00 và tệp cũ bị xếp nhầm.
    $after = stgMediaCreatedAt(CarbonImmutable::parse('2026-10-04 08:30:00', config('app.timezone')));
    stgMediaCreatedAt(CarbonImmutable::parse('2026-10-04 07:30:00', config('app.timezone')));
    Queue::fake();
    config(['vkcrm.storage.driver' => DocumentStore::DRIVER_GOOGLE_DRIVE]);
    Setting::query()->create(['key' => DocumentStore::REMOTE_ENABLED_AT_KEY, 'value' => '2026-10-04T01:00:00+00:00']);

    (new PushPendingDocumentFiles)();

    expect(stgQueuedMediaIds())->toBe([$after->id]);
});

it('công tắc local, không mốc: không xếp gì, không ghi nhật ký', function () {
    stgMediaCreatedAt(now()->subHour());
    Queue::fake();

    expect((new PushPendingDocumentFiles)()['queued'])->toBe(0);

    Queue::assertNothingPushed();
    expect(Activity::query()->where('event', 'document_store_disabled_observed')->exists())->toBeFalse();
});

it('google_drive nhưng chưa có mốc: không xếp gì', function () {
    stgMediaCreatedAt(now()->subHour());
    Queue::fake();
    config(['vkcrm.storage.driver' => DocumentStore::DRIVER_GOOGLE_DRIVE]);

    (new PushPendingDocumentFiles)();

    Queue::assertNothingPushed();
});

it('công tắc đổi về local mà mốc còn: xoá mốc, ghi nhật ký document_store_disabled_observed, không xếp gì', function (string $driver) {
    stgMediaCreatedAt(now()->subHour());
    StagingFixtures::enableRemote(now()->subHours(2));
    Queue::fake();
    config(['vkcrm.storage.driver' => $driver]);

    $result = (new PushPendingDocumentFiles)();

    Queue::assertNothingPushed();
    expect($result['queued'])->toBe(0)
        ->and(Setting::query()->where('key', DocumentStore::REMOTE_ENABLED_AT_KEY)->exists())->toBeFalse()
        ->and(DocumentStore::remoteEnabledAt())->toBeNull();

    $activity = Activity::query()->where('event', 'document_store_disabled_observed')->sole();

    expect($activity->subject_id)->toBeNull()
        ->and(CarbonImmutable::parse($activity->properties['remote_enabled_at'])->equalTo(now()->subHours(2)))->toBeTrue();
})->with([
    'local' => ['local'],
    'gõ sai' => ['Google_Drive'],
]);

it('lần chạy sau khi mốc đã bị xoá: không ghi nhật ký lần hai', function () {
    StagingFixtures::enableRemote(now()->subHours(2));
    config(['vkcrm.storage.driver' => DocumentStore::DRIVER_LOCAL]);

    (new PushPendingDocumentFiles)();
    (new PushPendingDocumentFiles)();

    expect(Activity::query()->where('event', 'document_store_disabled_observed')->count())->toBe(1);
});
