<?php

use App\Actions\Schedule\PushPendingDocumentFiles;
use App\Actions\Storage\PushDocumentFileToRemote;
use App\Exceptions\DocumentStorageMisconfigured;
use App\Exceptions\DocumentStorageUnavailable;
use App\Jobs\PushDocumentFile;
use App\Support\Storage\DocumentStore;
use App\Support\Storage\PushBackoff;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Support\StagingFixtures;

/*
|--------------------------------------------------------------------------
| M14 rà soát cuối vòng sửa 1 (I4) — kho hỏng lâu không được làm đầy bảng failed_jobs
|--------------------------------------------------------------------------
|
| Trước vòng sửa: `storage.push-pending` (15 phút) xếp lại một job cho MỌI media còn ở vùng đệm, không
| khử trùng; job gặp `DocumentStorageMisconfigured` thì `fail()` ngay (kế hoạch Task 3 — giữ nguyên). Kho
| cấu hình sai (xoay khoá R6, hết dung lượng, chạm 400.000 mục) thì mỗi media thêm một dòng `failed_jobs`
| kèm stack trace mỗi 15 phút — 200 tệp chờ ≈ 19.200 dòng/ngày trên CSDL của shared hosting, không gì dọn.
| Kho sập lâu (`DocumentStorageUnavailable`) cũng vậy sau 4 lượt thả lại.
|
| Sau vòng sửa: job `ShouldBeUnique` theo media; một lần hỏng vì cấu hình dừng MỌI lượt đẩy 60 phút, một
| lần kho không trả lời dừng 15 phút (`PushBackoff`): trong lúc dừng, push-pending không xếp gì và job
| còn trong hàng tự xoá không chạm kho. Media vừa làm hỏng xếp CUỐI lượt sau (đánh dấu 24 giờ), để một
| tệp "độc" không đứng đầu mọi lượt và chặn các tệp khác. Đo bằng worker THẬT của hàng `storage` (bảng
| `jobs`, `failed_jobs`).
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-04 10:00:00', config('app.timezone')));
});

const PJB_WORKER_MEMORY_MB = 1048576;

/** Media trong vùng đệm tạo 11 phút trước (đủ tuổi cho push-pending), tạo TRƯỚC khi bật kho. */
function pjbMedia(): Media
{
    $media = StagingFixtures::media();
    Media::query()->whereKey($media->id)->update(['created_at' => now()->subMinutes(11)]);

    return $media;
}

/**
 * Worker thật của hàng `storage`, chạy tới khi hàng trống (job thả lại có hạn chưa tới thì ở lại).
 * `--memory` rất lớn: worker dừng sau MỘT job khi bộ nhớ tiến trình vượt trần (mặc định 128 MB), và
 * tiến trình của cả bộ test song song đã vượt trần đó từ lâu — lượt chạy cả bộ đầu tiên của vòng sửa
 * đỏ đúng vì vậy (worker để lại các job sau job đầu).
 */
function pjbWork(): void
{
    Artisan::call('queue:work', [
        'connection' => 'storage',
        '--queue' => 'storage',
        '--stop-when-empty' => true,
        '--sleep' => 0,
        '--memory' => PJB_WORKER_MEMORY_MB,
    ]);
}

function pjbQueued(): int
{
    return DB::table('jobs')->where('queue', 'storage')->count();
}

function pjbFailed(): int
{
    return DB::table('failed_jobs')->count();
}

it('job đẩy là duy nhất theo media: push-pending chạy hai lần khi job còn trong hàng vẫn chỉ một job mỗi media', function () {
    $media = [pjbMedia(), pjbMedia()];
    StagingFixtures::enableRemote(now()->subHours(2));

    (new PushPendingDocumentFiles)();
    (new PushPendingDocumentFiles)();

    expect(pjbQueued())->toBe(2);

    $job = new PushDocumentFile($media[0]->id);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe((string) $media[0]->id)
        ->and($job->uniqueFor())->toBeGreaterThanOrEqual(config('queue.connections.storage.retry_after'))
        ->and($job->uniqueVia())->toBe(Cache::store(config('vkcrm.storage.lock_store')));
});

it('kho cấu hình sai suốt hai giờ: một dòng failed_jobs mỗi giờ, không phải mỗi media mỗi 15 phút', function () {
    $media = collect(range(1, 5))->map(fn () => pjbMedia());
    StagingFixtures::enableRemote(now()->subHours(2));
    // Móc `checksum` chạy ở MỌI lượt (cả lượt đầu, sau khi ghi, lẫn lượt sau gặp bản đã ghi), nên kho
    // hỏng thật suốt hai giờ — không lượt nào "dùng lại bản trên kho" mà thành công.
    $remote = StagingFixtures::hookRemote(['checksum' => fn () => throw DocumentStorageMisconfigured::notConfigured(['GOOGLE_DRIVE_ROOT_FOLDER_ID'])]);

    $failedAfterCycle = [];
    $queuedWhilePaused = [];

    foreach (range(0, 7) as $cycle) {
        (new PushPendingDocumentFiles)();

        if (PushBackoff::paused()) {
            $queuedWhilePaused[] = pjbQueued();
        }

        pjbWork();
        $failedAfterCycle[] = pjbFailed();
        $this->travel(15)->minutes();
    }

    // Phút 0 và phút 60: một job hỏng mỗi lượt, các job còn lại của lượt đó tự xoá.
    expect($failedAfterCycle)->toBe([1, 1, 1, 1, 2, 2, 2, 2])
        ->and(array_count_values($remote->calls)['checksum'])->toBe(2)
        // Trong lúc dừng, push-pending không xếp gì.
        ->and($queuedWhilePaused)->toBe([0, 0, 0, 0, 0, 0])
        ->and(pjbQueued())->toBe(0)
        ->and($media->every(fn (Media $m): bool => StagingFixtures::row($m->id)->disk === DocumentStore::STAGING_DISK))->toBeTrue();
});

it('kho không trả lời suốt hai giờ: không dòng failed_jobs nào, mỗi lượt 15 phút hỏi kho một lần', function () {
    collect(range(1, 5))->each(fn () => pjbMedia());
    StagingFixtures::enableRemote(now()->subHours(2));
    $remote = StagingFixtures::hookRemote(['checksum' => fn () => throw DocumentStorageUnavailable::temporarily()]);

    foreach (range(0, 7) as $cycle) {
        (new PushPendingDocumentFiles)();
        pjbWork();
        // Job thả lại sau 60 giây; lượt sau của nó thấy kho đang dừng và tự xoá.
        $this->travel(61)->seconds();
        pjbWork();
        $this->travel(15)->minutes();
    }

    expect(pjbFailed())->toBe(0)
        ->and(pjbQueued())->toBe(0)
        ->and(array_count_values($remote->calls)['checksum'])->toBe(8);
});

it('một tệp "độc" hỏng mãi không chặn các tệp khác: lượt sau nó xếp cuối, các tệp còn lại lên kho trước', function () {
    $poison = pjbMedia();
    $healthy = [pjbMedia(), pjbMedia()];
    $poisonKey = $poison->getPathRelativeToRoot();
    StagingFixtures::enableRemote(now()->subHours(2));
    StagingFixtures::hookRemote(['checksum' => function (string $path, string $real) use ($poisonKey): string {
        if ($path === $poisonKey) {
            throw DocumentStorageMisconfigured::notConfigured(['GOOGLE_DRIVE_ROOT_FOLDER_ID']);
        }

        return $real;
    }]);

    foreach (range(0, 5) as $cycle) {
        (new PushPendingDocumentFiles)();
        pjbWork();
        $this->travel(15)->minutes();
    }

    expect(StagingFixtures::row($healthy[0]->id)->disk)->toBe(DocumentStore::REMOTE_DISK)
        ->and(StagingFixtures::row($healthy[1]->id)->disk)->toBe(DocumentStore::REMOTE_DISK)
        ->and(StagingFixtures::row($poison->id)->disk)->toBe(DocumentStore::STAGING_DISK)
        // Phút 0 (đứng đầu) và phút 60 (xếp cuối, sau hai tệp lành).
        ->and(pjbFailed())->toBe(2);
});

it('job lấy ra lúc kho đang dừng: tự xoá, không chạm kho, không hỏng, không thả lại', function () {
    $media = pjbMedia();
    StagingFixtures::enableRemote(now()->subHours(2));
    $remote = StagingFixtures::hookRemote();
    PushBackoff::pauseAfter(new DocumentStorageMisconfigured('x'), $media->id);

    $job = (new PushDocumentFile($media->id))->withFakeQueueInteractions();
    $job->handle(app(PushDocumentFileToRemote::class));

    $job->assertDeleted();
    $job->assertNotFailed();
    $job->assertNotReleased();
    expect($remote->calls)->toBe([]);
});

it('dấu dừng chỉ kéo dài, không rút ngắn: lỗi kho không trả lời ngay sau lỗi cấu hình không mở lại sau 15 phút', function () {
    PushBackoff::pauseAfter(new DocumentStorageMisconfigured('x'), 1);
    $this->travel(20)->minutes();
    PushBackoff::pauseAfter(DocumentStorageUnavailable::temporarily(), 2);
    $this->travel(30)->minutes();

    expect(PushBackoff::paused())->toBeTrue();

    $this->travel(10)->minutes();

    expect(PushBackoff::paused())->toBeFalse();
});
