<?php

use App\Actions\Schedule\PurgeStagedDocumentCopies;
use App\Actions\Storage\PushDocumentFileToRemote;
use App\Enums\PushOutcome;
use App\Models\DriveObject;
use App\Support\Storage\DocumentStore;
use App\Support\Storage\GoogleDrive\DriveObjectName;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\FakeGoogleDrive;
use Tests\Support\OfficeReceiptFixtures as Receipts;
use Tests\Support\StagingFixtures;

/*
|--------------------------------------------------------------------------
| M14 Task 7 (viết ở Task 6) — "đi trọn đường": đẩy → nhập biên nhận → qua biên độ → dọn vùng đệm (R10)
|--------------------------------------------------------------------------
|
| Dời sang Task 6 vì cần `PushDocumentFileToRemote` + `PurgeStagedDocumentCopies` (làn m14, Task 3) và
| `ImportOfficeReceipts` (làn m14b, Task 7). Mọi bước là mã THẬT: Action đẩy trên adapter Drive thật
| (máy chủ Drive giả, nên dòng chỉ mục mang đúng md5 và cỡ Google báo), lệnh
| `vkcrm:storage:office-receipts` với `rclone` giả, rồi lượt dọn mỗi giờ. Vế âm (không biên nhận,
| biên nhận của Shared Drive khác, lệch md5, chưa qua biên độ) giữ bản cục bộ.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 20:00:00', 'Asia/Ho_Chi_Minh'));
    $this->drive = FakeGoogleDrive::install();
    $this->drive->disk();
    Receipts::configure();

    $this->media = StagingFixtures::media('%PDF-1.4 di tron duong');
    StagingFixtures::enableRemote();

    expect(app(PushDocumentFileToRemote::class)->handle($this->media->id))->toBe(PushOutcome::Pushed);

    $this->key = $this->media->getPathRelativeToRoot();
    $this->object = DriveObject::query()->where('object_key', $this->key)->sole();
});

function t6ImportReceipt(array $overrides = [], ?array $line = null, string $name = '20261007T120000Z'): int
{
    $object = test()->object;

    Receipts::fakeRclone([Receipts::fileName($name) => Receipts::receipt([
        $line ?? ['name' => DriveObjectName::fromKey($object->object_key, $object->generation), 'md5' => $object->md5, 'size' => $object->size],
    ], $overrides)]);

    return Artisan::call('vkcrm:storage:office-receipts');
}

it('đẩy → nhập biên nhận → travel qua local_purge_after và biên độ → dọn xoá bản cục bộ; tải từ kho vẫn đúng byte', function () {
    expect(t6ImportReceipt())->toBe(0)
        ->and($this->object->fresh()->office_copied_at)->not->toBeNull();

    // Ân hạn 24 giờ của tệp mới + biên độ 24 giờ của biên nhận.
    $this->travel(49)->hours();
    $counts = app(PurgeStagedDocumentCopies::class)->handle();

    expect($counts['purged'])->toBe(1)
        ->and(DocumentStore::staging()->exists($this->key))->toBeFalse()
        ->and(StagingFixtures::row($this->media->id)->local_purge_after)->toBeNull()
        ->and(DocumentStore::remote()->get($this->key))->toBe('%PDF-1.4 di tron duong');
});

it('chưa qua biên độ 24 giờ của biên nhận → giữ; qua rồi → dọn', function () {
    $this->travel(30)->hours();
    t6ImportReceipt(name: '20261008T180000Z');

    $this->travel(23)->hours();
    expect(app(PurgeStagedDocumentCopies::class)->handle()['purged'])->toBe(0)
        ->and(DocumentStore::staging()->exists($this->key))->toBeTrue();

    $this->travel(2)->hours();
    expect(app(PurgeStagedDocumentCopies::class)->handle()['purged'])->toBe(1);
});

it('không biên nhận, hay biên nhận của Shared Drive khác, hay biên nhận lệch md5 → bản cục bộ còn mãi', function (string $case) {
    match ($case) {
        'none' => null,
        'other_drive' => t6ImportReceipt(['kho' => ['team_drive' => '0AOtherDrive', 'root_folder_id' => FakeGoogleDrive::ROOT_FOLDER_ID]]),
        'md5' => t6ImportReceipt(line: ['name' => DriveObjectName::fromKey($this->key), 'md5' => md5('khac'), 'size' => $this->object->size]),
    };

    $this->travel(60)->days();
    app(PurgeStagedDocumentCopies::class)->handle();

    expect(DocumentStore::staging()->exists($this->key))->toBeTrue()
        ->and($this->object->fresh()->office_copied_at)->toBeNull();
})->with(['none', 'other_drive', 'md5']);
