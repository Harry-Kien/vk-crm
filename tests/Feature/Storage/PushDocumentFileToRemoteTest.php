<?php

use App\Actions\Storage\PushDocumentFileToRemote;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\DriveObjectRetirement;
use App\Enums\PushOutcome;
use App\Enums\Role;
use App\Exceptions\DocumentStorageMisconfigured;
use App\Exceptions\DocumentStorageUnavailable;
use App\Exceptions\StoredFileMissing;
use App\Jobs\PushDocumentFile;
use App\Models\Document;
use App\Models\DriveObject;
use App\Models\Matter;
use App\Models\Setting;
use App\Models\User;
use App\Support\Storage\DocumentStore;
use App\Support\Storage\GoogleDrive\DriveObjectName;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToProvideChecksum;
use League\Flysystem\UnableToWriteFile;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Support\FakeGoogleDrive;
use Tests\Support\StagingFixtures;

/*
|--------------------------------------------------------------------------
| M14 Task 3 — đẩy MỘT tệp từ vùng đệm lên kho (kế hoạch M14, R2, R4, R11, R13)
|--------------------------------------------------------------------------
|
| `PushDocumentFileToRemote` theo sáu bước của R2: khoá đẩy → md5/sha256 của bản trong vùng đệm →
| tải lên đúng khoá thư viện media → kiểm md5 do kho tính và kích thước → đổi đĩa bằng UPDATE có
| điều kiện → (production) ghi lần chuyển đầu tiên. Và job `PushDocumentFile` chọn thả lại, thất bại
| hay để hàng đợi thử lại theo kết quả.
|
| Đĩa kho là đĩa giả (`tests/Pest.php`), có khi bọc để chen vào giữa các bước
| (`StagingFixtures::hookRemote()`). Hành vi chỉ Drive có — thùng rác, dòng chỉ mục, số thế hệ —
| đo trên adapter THẬT với Drive giả (`FakeGoogleDrive`: `Http::fake()` +
| `Http::preventStrayRequests()`, phán quyết C2).
*/

beforeEach(function () {
    $this->freezeSecond();
});

function stgPush(int $mediaId, ?CarbonInterface $keepLocalUntil = null): PushOutcome
{
    return app(PushDocumentFileToRemote::class)->handle($mediaId, $keepLocalUntil);
}

// ---------------------------------------------------------------------------------------------
// Đẩy thành công.
// ---------------------------------------------------------------------------------------------

it('đẩy thành công: tệp lên kho đúng byte, media đổi sang kho kèm hai checksum, bản cục bộ còn và được giữ 24 giờ', function () {
    $media = StagingFixtures::media('%PDF-1.4 noi dung that cua khach');
    $key = $media->getPathRelativeToRoot();
    StagingFixtures::enableRemote();

    expect(stgPush($media->id))->toBe(PushOutcome::Pushed);

    expect(Storage::disk(DocumentStore::REMOTE_DISK)->get($key))->toBe('%PDF-1.4 noi dung that cua khach');

    $row = StagingFixtures::row($media->id);

    expect($row->disk)->toBe(DocumentStore::REMOTE_DISK)
        ->and($row->conversions_disk)->toBe(DocumentStore::REMOTE_DISK)
        ->and($row->checksum_md5)->toBe(md5('%PDF-1.4 noi dung that cua khach'))
        ->and($row->checksum_sha256)->toBe(hash('sha256', '%PDF-1.4 noi dung that cua khach'))
        ->and(CarbonImmutable::parse($row->remote_pushed_at)->equalTo(now()))->toBeTrue()
        ->and(CarbonImmutable::parse($row->local_purge_after)->equalTo(now()->addHours(24)))->toBeTrue();

    // R2: bản trong vùng đệm KHÔNG bị xoá ngay.
    Storage::disk(DocumentStore::STAGING_DISK)->assertExists($key);
});

it('thời gian ân hạn đọc từ cấu hình (DOCUMENT_STAGING_GRACE_HOURS), không viết cứng 24', function () {
    config(['vkcrm.storage.staging_grace_hours' => 48]);
    $media = StagingFixtures::media();
    StagingFixtures::enableRemote();

    stgPush($media->id);

    expect(CarbonImmutable::parse(StagingFixtures::row($media->id)->local_purge_after)->equalTo(now()->addHours(48)))->toBeTrue();
});

it('keepLocalUntil của lệnh chuyển tệp cũ (+30 ngày) thay cho ân hạn, ghi theo giờ của ứng dụng dù truyền mốc UTC', function () {
    $media = StagingFixtures::media();
    StagingFixtures::enableRemote();

    stgPush($media->id, now()->addDays(30)->utc());

    expect(StagingFixtures::row($media->id)->local_purge_after)
        ->toBe(now()->addDays(30)->timezone(config('app.timezone'))->format('Y-m-d H:i:s'));
});

it('kho chưa bật thì không đẩy gì: công tắc local, hoặc google_drive mà chưa có mốc', function (bool $driverOn, bool $markerOn) {
    $media = StagingFixtures::media();

    if ($driverOn) {
        config(['vkcrm.storage.driver' => DocumentStore::DRIVER_GOOGLE_DRIVE]);
    }

    if ($markerOn) {
        Setting::query()->create(['key' => DocumentStore::REMOTE_ENABLED_AT_KEY, 'value' => now()->subHour()->toIso8601String()]);
    }

    $remote = StagingFixtures::hookRemote();

    expect(stgPush($media->id))->toBe(PushOutcome::Disabled)
        ->and($remote->calls)->toBe([])
        ->and(StagingFixtures::row($media->id)->disk)->toBe(DocumentStore::STAGING_DISK);
})->with([
    'local, có mốc' => [false, true],
    'google_drive, chưa có mốc' => [true, false],
]);

// ---------------------------------------------------------------------------------------------
// md5 lệch.
// ---------------------------------------------------------------------------------------------

it('md5 trên kho lệch: không đổi đĩa, bản trên kho bị xoá, lỗi nổ ra để job thử lại; lượt sau đẩy được', function () {
    $media = StagingFixtures::media();
    $key = $media->getPathRelativeToRoot();
    StagingFixtures::enableRemote();

    $remote = StagingFixtures::hookRemote(['checksum' => fn (string $path, string $real): string => str_repeat('0', 32)]);

    $job = (new PushDocumentFile($media->id))->withFakeQueueInteractions();

    expect(fn () => $job->handle(app(PushDocumentFileToRemote::class)))->toThrow(UnableToWriteFile::class);

    // Hàng đợi tự thử lại theo backoff: job không tự thả, không tự đánh dấu hỏng.
    $job->assertNotReleased();
    $job->assertNotFailed();

    expect(StagingFixtures::row($media->id)->disk)->toBe(DocumentStore::STAGING_DISK)
        ->and($remote->calls)->toContain('delete');
    Storage::disk(DocumentStore::REMOTE_DISK)->assertMissing($key);
    Storage::disk(DocumentStore::STAGING_DISK)->assertExists($key);

    unset($remote->hooks['checksum']);

    expect(stgPush($media->id))->toBe(PushOutcome::Pushed);
    Storage::disk(DocumentStore::REMOTE_DISK)->assertExists($key);
});

it('cỡ trên kho lệch dù md5 khớp: cũng là lệch — không đổi đĩa, bản trên kho bị xoá', function () {
    $media = StagingFixtures::media();
    $key = $media->getPathRelativeToRoot();
    StagingFixtures::enableRemote();

    StagingFixtures::hookRemote(['size' => fn (string $path, int $real): int => $real + 1]);

    expect(fn () => stgPush($media->id))->toThrow(UnableToWriteFile::class);

    expect(StagingFixtures::row($media->id)->disk)->toBe(DocumentStore::STAGING_DISK);
    Storage::disk(DocumentStore::REMOTE_DISK)->assertMissing($key);
});

it('adapter thật: md5 Google báo sau khi tải lệch → bản thế hệ 1 vào thùng rác (dòng chỉ mục trashed), lượt sau ghi thế hệ 2', function () {
    $drive = FakeGoogleDrive::install();
    $drive->disk();

    $media = StagingFixtures::media('%PDF-1.4 ban that');
    $key = $media->getPathRelativeToRoot();
    StagingFixtures::enableRemote();

    // Lần hỏi metadata kế tiếp (lần kiểm của Action, sau khi tải xong) thấy md5 khác: "Google"
    // báo md5 lệch cho đúng tên thế hệ 1. Bản thế hệ 2 không bị đổi.
    $drive->respondNext('GET', 'sha256Checksum', function (Request $request) use ($drive, $key) {
        $drive->md5Overrides[DriveObjectName::fromKey($key, 1)] = str_repeat('a', 32);

        return $drive->handle($request, []);
    });

    expect(fn () => stgPush($media->id))->toThrow(UnableToWriteFile::class);

    $retired = DriveObject::query()->where('former_key', $key)->sole();

    expect($retired->retired_reason)->toBe(DriveObjectRetirement::Trashed)
        ->and($retired->generation)->toBe(1)
        ->and($retired->object_key)->toBeNull()
        ->and($drive->files[$retired->file_id]['trashed'])->toBeTrue()
        ->and(StagingFixtures::row($media->id)->disk)->toBe(DocumentStore::STAGING_DISK);

    expect(stgPush($media->id))->toBe(PushOutcome::Pushed);

    $live = DriveObject::query()->where('object_key', $key)->sole();

    expect($live->generation)->toBe(2)
        ->and($drive->files[$live->file_id]['name'])->toBe(DriveObjectName::fromKey($key, 2))
        ->and($drive->files[$live->file_id]['content'])->toBe('%PDF-1.4 ban that')
        ->and(StagingFixtures::row($media->id)->disk)->toBe(DocumentStore::REMOTE_DISK)
        ->and(StagingFixtures::row($media->id)->checksum_md5)->toBe($live->md5);
});

it('adapter thật: dòng chỉ mục sống mà tệp Drive đã vào thùng rác hay mất (404) → rút dòng, tải lại thế hệ 2 (rà soát cuối I5)', function (string $how) {
    $drive = FakeGoogleDrive::install();
    $drive->disk();

    $media = StagingFixtures::media('%PDF-1.4 ban that');
    $key = $media->getPathRelativeToRoot();
    $old = $drive->seed($key, '%PDF-1.4 ban that');
    StagingFixtures::enableRemote();

    if ($how === 'trashed') {
        $drive->files[$old->file_id]['trashed'] = true;
    } else {
        unset($drive->files[$old->file_id]);
    }

    expect(stgPush($media->id))->toBe(PushOutcome::Pushed);

    $live = DriveObject::query()->where('object_key', $key)->sole();

    expect($old->fresh()->object_key)->toBeNull()
        ->and($old->fresh()->former_key)->toBe($key)
        ->and($old->fresh()->retired_reason)->toBe(DriveObjectRetirement::Trashed)
        ->and($live->generation)->toBe(2)
        ->and($drive->files[$live->file_id]['content'])->toBe('%PDF-1.4 ban that')
        ->and(StagingFixtures::row($media->id)->disk)->toBe(DocumentStore::REMOTE_DISK);
})->with(['trashed', 'deleted']);

it('adapter thật: lỗi khác khi hỏi md5 của bản đã có → ném ra, không rút dòng, không tải lại', function (string $how) {
    $drive = FakeGoogleDrive::install();
    $drive->disk();

    $media = StagingFixtures::media('%PDF-1.4 ban that');
    $key = $media->getPathRelativeToRoot();
    $old = $drive->seed($key, '%PDF-1.4 ban that');
    StagingFixtures::enableRemote();

    if ($how === 'no_md5') {
        // Google trả metadata thiếu md5Checksum: `UnableToProvideChecksum` KHÔNG có gốc thùng rác/404.
        $drive->respondNext('GET', 'sha256Checksum', fn () => Http::response(['id' => $old->file_id, 'name' => 'x', 'trashed' => false, 'size' => '17']));
        $expected = UnableToProvideChecksum::class;
    } else {
        $drive->failNext('GET', 'sha256Checksum', 500, 'backendError', times: 10);
        $expected = DocumentStorageUnavailable::class;
    }

    expect(fn () => stgPush($media->id))->toThrow($expected);

    expect($old->fresh()->object_key)->toBe($key)
        ->and(DriveObject::query()->where('former_key', $key)->exists())->toBeFalse()
        ->and(StagingFixtures::row($media->id)->disk)->toBe(DocumentStore::STAGING_DISK);
})->with(['no_md5', 'google_500']);

it('tệp đã lên kho ở lượt trước mà chưa kịp đổi đĩa: md5 khớp thì KHÔNG tải lần hai (R11)', function () {
    $media = StagingFixtures::media('%PDF-1.4 da len kho');
    $key = $media->getPathRelativeToRoot();
    StagingFixtures::enableRemote();
    Storage::disk(DocumentStore::REMOTE_DISK)->put($key, '%PDF-1.4 da len kho');

    $remote = StagingFixtures::hookRemote();

    expect(stgPush($media->id))->toBe(PushOutcome::Pushed)
        ->and($remote->calls)->not->toContain('writeStream')
        ->and($remote->calls)->not->toContain('delete')
        ->and(StagingFixtures::row($media->id)->disk)->toBe(DocumentStore::REMOTE_DISK);
});

it('tệp đã lên kho ở lượt trước nhưng nội dung lệch: cho vào thùng rác rồi tải lại', function () {
    $media = StagingFixtures::media('%PDF-1.4 ban dung');
    $key = $media->getPathRelativeToRoot();
    StagingFixtures::enableRemote();
    Storage::disk(DocumentStore::REMOTE_DISK)->put($key, '%PDF-1.4 ban hong');

    $remote = StagingFixtures::hookRemote();

    expect(stgPush($media->id))->toBe(PushOutcome::Pushed)
        ->and(array_values(array_intersect($remote->calls, ['delete', 'writeStream'])))->toBe(['delete', 'writeStream'])
        ->and(Storage::disk(DocumentStore::REMOTE_DISK)->get($key))->toBe('%PDF-1.4 ban dung');
});

// ---------------------------------------------------------------------------------------------
// Từ chối đẩy.
// ---------------------------------------------------------------------------------------------

it('khoá lệch khuôn R4 (tên tệp không phải ULID): không đẩy, log critical không mang tên tệp, media ở lại vùng đệm', function () {
    Log::spy();
    $media = StagingFixtures::media(fileName: 'cccd-nguyen-van-a.pdf');
    StagingFixtures::enableRemote();

    $remote = StagingFixtures::hookRemote();

    expect(stgPush($media->id))->toBe(PushOutcome::Rejected)
        ->and($remote->calls)->toBe([])
        ->and(StagingFixtures::row($media->id)->disk)->toBe(DocumentStore::STAGING_DISK);

    Log::shouldHaveReceived('critical')->withArgs(fn (string $message, array $context): bool => ($context['media_id'] ?? null) === $media->id
        && ! str_contains(json_encode($context), 'nguyen'))->once();
});

it('khoá có tiền tố thư viện media (không còn là <media_id>/<ulid>): không đẩy', function () {
    config(['media-library.prefix' => 'tien-to']);
    $media = StagingFixtures::media();
    StagingFixtures::enableRemote();

    expect($media->getPathRelativeToRoot())->toStartWith('tien-to/')
        ->and(stgPush($media->id))->toBe(PushOutcome::Rejected)
        ->and(StagingFixtures::row($media->id)->disk)->toBe(DocumentStore::STAGING_DISK);
});

it('cặp dương của khuôn khoá: ULID viết thường không đuôi, và đuôi 8 ký tự, đều đẩy được', function (string $fileName) {
    $media = StagingFixtures::media(fileName: $fileName);
    StagingFixtures::enableRemote();

    expect(stgPush($media->id))->toBe(PushOutcome::Pushed);
})->with([
    'không đuôi' => ['01k6xq0f9m2y7c4w8r3t5v6n1b'],
    'đuôi 8 ký tự' => ['01k6xq0f9m2y7c4w8r3t5v6n1b.abcdefgh'],
]);

it('media nằm trên một đĩa không phải vùng đệm lẫn kho: Rejected, không chạm kho', function () {
    Log::spy();
    $media = StagingFixtures::media();
    Media::query()->whereKey($media->id)->update(['disk' => 'public']);
    StagingFixtures::enableRemote();
    $remote = StagingFixtures::hookRemote();

    expect(stgPush($media->id))->toBe(PushOutcome::Rejected)
        ->and($remote->calls)->toBe([])
        ->and(StagingFixtures::row($media->id)->disk)->toBe('public');

    Log::shouldHaveReceived('critical')->once();
});

it('tệp trong vùng đệm không còn mà media vẫn ở private: không đổi đĩa, log critical, StoredFileMissing', function () {
    Log::spy();
    $media = StagingFixtures::media();
    StagingFixtures::enableRemote();
    Storage::disk(DocumentStore::STAGING_DISK)->delete($media->getPathRelativeToRoot());

    expect(fn () => stgPush($media->id))->toThrow(StoredFileMissing::class);

    expect(StagingFixtures::row($media->id)->disk)->toBe(DocumentStore::STAGING_DISK)
        ->and(Storage::disk(DocumentStore::REMOTE_DISK)->allFiles())->toBe([]);

    Log::shouldHaveReceived('critical')->withArgs(fn (string $message, array $context): bool => ($context['media_id'] ?? null) === $media->id)->once();
});

// ---------------------------------------------------------------------------------------------
// Chạy lại, media biến mất, khoá.
// ---------------------------------------------------------------------------------------------

it('chạy hai lần: lần hai AlreadyRemote và không chạm kho', function () {
    $media = StagingFixtures::media();
    StagingFixtures::enableRemote();

    expect(stgPush($media->id))->toBe(PushOutcome::Pushed);

    $remote = StagingFixtures::hookRemote();

    expect(stgPush($media->id))->toBe(PushOutcome::AlreadyRemote)
        ->and($remote->calls)->toBe([]);
});

it('media không còn từ đầu: Gone, không chạm kho', function () {
    StagingFixtures::enableRemote();
    $remote = StagingFixtures::hookRemote();

    expect(stgPush(987654))->toBe(PushOutcome::Gone)
        ->and($remote->calls)->toBe([]);
});

it('media bị xoá sau khi tải lên, trước khi đổi đĩa: Gone, bản trên kho bị xoá', function () {
    $media = StagingFixtures::media();
    $key = $media->getPathRelativeToRoot();
    StagingFixtures::enableRemote();

    StagingFixtures::hookRemote(['afterWrite' => fn () => Media::query()->findOrFail($media->id)->delete()]);

    expect(stgPush($media->id))->toBe(PushOutcome::Gone)
        ->and(StagingFixtures::row($media->id))->toBeNull();

    Storage::disk(DocumentStore::REMOTE_DISK)->assertMissing($key);
});

it('adapter thật: media bị xoá trước khi đổi đĩa → dòng chỉ mục của bản vừa tải thành trashed', function () {
    $drive = FakeGoogleDrive::install();
    $drive->disk();

    $media = StagingFixtures::media();
    $key = $media->getPathRelativeToRoot();
    StagingFixtures::enableRemote();

    // Xoá dòng media đúng lúc Action hỏi md5 (sau khi tải lên xong, trước UPDATE đổi đĩa).
    $drive->respondNext('GET', 'sha256Checksum', function (Request $request) use ($drive, $media) {
        Media::query()->whereKey($media->id)->delete();

        return $drive->handle($request, []);
    });

    expect(stgPush($media->id))->toBe(PushOutcome::Gone);

    expect(DriveObject::query()->where('object_key', $key)->exists())->toBeFalse()
        ->and(DriveObject::query()->where('former_key', $key)->sole()->retired_reason)->toBe(DriveObjectRetirement::Trashed);
});

it('đĩa của media đổi giữa lúc tải lên và lúc UPDATE (lượt khác): AlreadyRemote, không ghi đè dòng, không ghi mốc lần chuyển', function () {
    app()['env'] = 'production';
    $media = StagingFixtures::media();
    StagingFixtures::enableRemote();

    StagingFixtures::hookRemote(['afterWrite' => fn () => Media::query()->whereKey($media->id)->update([
        'disk' => DocumentStore::REMOTE_DISK,
        'checksum_md5' => str_repeat('b', 32),
    ])]);

    expect(stgPush($media->id))->toBe(PushOutcome::AlreadyRemote)
        ->and(StagingFixtures::row($media->id)->checksum_md5)->toBe(str_repeat('b', 32))
        ->and(StagingFixtures::row($media->id)->remote_pushed_at)->toBeNull()
        ->and(Setting::query()->where('key', PushDocumentFileToRemote::FIRST_TRANSFER_AT_KEY)->exists())->toBeFalse();
});

it('không lấy được khoá đẩy: Locked, không chạm kho; job thả lại sau 120 giây', function () {
    $media = StagingFixtures::media();
    StagingFixtures::enableRemote();
    $remote = StagingFixtures::hookRemote();

    $held = DocumentStore::pushLock($media->id);
    expect($held->get())->toBeTrue();

    expect(stgPush($media->id))->toBe(PushOutcome::Locked)
        ->and($remote->calls)->toBe([]);

    $job = (new PushDocumentFile($media->id))->withFakeQueueInteractions();
    $job->handle(app(PushDocumentFileToRemote::class));
    $job->assertReleased(120);

    $held->release();

    expect(stgPush($media->id))->toBe(PushOutcome::Pushed);
});

it('hai job cùng một media chạy chồng: đúng một lần tải lên, job thứ hai thả lại 120 giây', function () {
    $media = StagingFixtures::media();
    StagingFixtures::enableRemote();

    $second = (new PushDocumentFile($media->id))->withFakeQueueInteractions();
    $remote = StagingFixtures::hookRemote([
        'afterWrite' => fn () => $second->handle(app(PushDocumentFileToRemote::class)),
    ]);

    $first = (new PushDocumentFile($media->id))->withFakeQueueInteractions();
    $first->handle(app(PushDocumentFileToRemote::class));

    $first->assertNotReleased();
    $second->assertReleased(120);

    expect(array_count_values($remote->calls)['writeStream'])->toBe(1)
        ->and(StagingFixtures::row($media->id)->disk)->toBe(DocumentStore::REMOTE_DISK);
});

// ---------------------------------------------------------------------------------------------
// Lượt tải đang dở lúc đổi đĩa (R2: bản trong vùng đệm còn trong thời gian ân hạn).
// ---------------------------------------------------------------------------------------------

it('lượt tải đã nạp disk = private ngay trước khi đổi đĩa vẫn trả đúng byte sau khi đổi', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $document = Document::factory()->for($matter)->group(DocumentGroup::Authority)->create(['status' => DocumentStatus::InternalDraft]);
    $media = StagingFixtures::media('%PDF-1.4 dang tai do', document: $document);
    StagingFixtures::enableRemote();

    // Route tải gọi `exists()` trên đĩa của media (private) rồi mới đọc. Lượt đẩy chen vào đúng
    // giữa hai bước đó: route đã cầm media với disk = private.
    StagingFixtures::hookStaging(['exists' => fn () => expect(stgPush($media->id))->toBe(PushOutcome::Pushed)]);

    $response = $this->actingAs($lawyer, 'web')->get($document->downloadUrlFor($lawyer));

    $response->assertOk();
    expect($response->streamedContent())->toBe('%PDF-1.4 dang tai do')
        ->and(StagingFixtures::row($media->id)->disk)->toBe(DocumentStore::REMOTE_DISK);
});

// ---------------------------------------------------------------------------------------------
// Lần chuyển đầu tiên (R13): chỉ production, chỉ ở Pushed, không ghi đè.
// ---------------------------------------------------------------------------------------------

it('production, lượt Pushed đầu tiên: ghi storage.first_transfer_at', function () {
    app()['env'] = 'production';
    $media = StagingFixtures::media();
    StagingFixtures::enableRemote();

    stgPush($media->id);

    expect(CarbonImmutable::parse(Setting::query()->where('key', PushDocumentFileToRemote::FIRST_TRANSFER_AT_KEY)->value('value'))->equalTo(now()))->toBeTrue();
});

it('production, lượt Pushed thứ hai: mốc lần chuyển đầu tiên KHÔNG đổi', function () {
    app()['env'] = 'production';
    StagingFixtures::enableRemote();

    stgPush(StagingFixtures::media()->id);
    $first = Setting::query()->where('key', PushDocumentFileToRemote::FIRST_TRANSFER_AT_KEY)->value('value');

    $this->travel(3)->days();
    expect(stgPush(StagingFixtures::media()->id))->toBe(PushOutcome::Pushed);

    expect(Setting::query()->where('key', PushDocumentFileToRemote::FIRST_TRANSFER_AT_KEY)->pluck('value')->all())->toBe([$first]);
});

it('APP_ENV=testing: Pushed không ghi mốc lần chuyển đầu tiên', function () {
    $media = StagingFixtures::media();
    StagingFixtures::enableRemote();

    expect(stgPush($media->id))->toBe(PushOutcome::Pushed)
        ->and(Setting::query()->where('key', PushDocumentFileToRemote::FIRST_TRANSFER_AT_KEY)->exists())->toBeFalse();
});

it('production, AlreadyRemote: không ghi mốc lần chuyển đầu tiên', function () {
    $media = StagingFixtures::media();
    StagingFixtures::enableRemote();
    stgPush($media->id);

    app()['env'] = 'production';

    expect(stgPush($media->id))->toBe(PushOutcome::AlreadyRemote)
        ->and(Setting::query()->where('key', PushDocumentFileToRemote::FIRST_TRANSFER_AT_KEY)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------------------------
// Job.
// ---------------------------------------------------------------------------------------------

it('job PushDocumentFile: kết nối và hàng storage, timeout 1800, 4 lượt, backoff 60/300/900, hỏng khi quá giờ', function () {
    $job = new PushDocumentFile(5);

    expect($job->connection)->toBe('storage')
        ->and($job->queue)->toBe('storage')
        ->and($job->timeout)->toBe(1800)
        ->and(PushDocumentFile::TIMEOUT_SECONDS)->toBe(1800)
        ->and($job->tries)->toBe(4)
        ->and($job->backoff())->toBe([60, 300, 900])
        ->and($job->failOnTimeout)->toBeTrue();
});

it('job: kho tạm thời không truy cập được → thả lại sau 60 giây, không hỏng', function () {
    $media = StagingFixtures::media();
    StagingFixtures::enableRemote();
    StagingFixtures::hookRemote(['afterWrite' => fn () => throw DocumentStorageUnavailable::temporarily()]);

    $job = (new PushDocumentFile($media->id))->withFakeQueueInteractions();
    $job->handle(app(PushDocumentFileToRemote::class));

    $job->assertReleased(60);
    $job->assertNotFailed();
});

it('job: kho cấu hình sai → hỏng ngay, không thả lại (cảnh báo đi qua kiểm tra sức khoẻ)', function () {
    $media = StagingFixtures::media();
    StagingFixtures::enableRemote();
    StagingFixtures::hookRemote(['afterWrite' => fn () => throw DocumentStorageMisconfigured::notConfigured(['GOOGLE_DRIVE_ROOT_FOLDER_ID'])]);

    $job = (new PushDocumentFile($media->id))->withFakeQueueInteractions();
    $job->handle(app(PushDocumentFileToRemote::class));

    $job->assertFailedWith(DocumentStorageMisconfigured::class);
    $job->assertNotReleased();
});

it('job: đẩy xong thì không thả lại, không hỏng', function () {
    $media = StagingFixtures::media();
    StagingFixtures::enableRemote();

    $job = (new PushDocumentFile($media->id))->withFakeQueueInteractions();
    $job->handle(app(PushDocumentFileToRemote::class));

    $job->assertNotReleased();
    $job->assertNotFailed();
    expect(StagingFixtures::row($media->id)->disk)->toBe(DocumentStore::REMOTE_DISK);
});
