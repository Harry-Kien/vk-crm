<?php

use App\Actions\Storage\PushDocumentFileToRemote;
use App\Enums\ChecklistItemStatus;
use App\Enums\DocumentGroup;
use App\Enums\PushOutcome;
use App\Enums\Role;
use App\Exceptions\FileRejected;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\DocumentsRelationManager;
use App\Filament\Portal\Pages\SubmitDocument;
use App\Jobs\PushDocumentFile;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use App\Support\Files\VirusScanner;
use App\Support\Storage\DocumentStore;
use Carbon\CarbonInterface;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Support\StagingFixtures;

/*
|--------------------------------------------------------------------------
| M14 Task 3 — tệp mới vào vùng đệm, job đẩy được xếp SAU commit (kế hoạch M14, R2)
|--------------------------------------------------------------------------
|
| Ba Action ghi tệp không đổi: chúng vẫn ghi xuống `private` trong transaction. Listener
| `QueueDocumentFilePush` (đăng ký tường minh trên `eloquent.created` của `Media`, không qua tự dò)
| xếp `PushDocumentFile` bằng `->afterCommit()` khi kho đã BẬT (`pushesNewFiles()`) và media nằm ở
| `private`.
|
| Đi qua màn hình thật: bảng "Tài liệu" của nhân sự (`DocumentsRelationManager`) và trang nộp của cổng
| khách (`SubmitDocument`). "Sau commit" đo bằng nghĩa, không bằng cờ: kết nối `storage` đổi sang
| `sync` và Action đẩy được thay bằng bản ghi lại MỨC TRANSACTION lúc job chạy — thiếu
| `->afterCommit()` thì job chạy ngay trong transaction của Action ghi tệp (mức sâu hơn), và một lần
| rollback vẫn để lại một lời gọi. `Queue::fake()` không phân biệt được (nó ghi job ngay cả khi có
| `afterCommit`).
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->activated()->create(['client_id' => $this->client->id]);
    $this->matter = Matter::factory()->for($this->client)->create([
        'is_published_to_portal' => true,
        'lead_lawyer_id' => $this->lawyer->id,
    ]);
    $this->item = MatterChecklistItem::factory()->for($this->matter)->create(['status' => ChecklistItemStatus::Missing]);
});

function stgPdf(string $name = 'thong-bao.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, StagingFixtures::PDF);
}

function stgStaffUpload(UploadedFile $file): Testable
{
    Filament::setCurrentPanel('admin');

    return test()->actingAs(test()->lawyer, 'web')
        ->livewire(DocumentsRelationManager::class, ['ownerRecord' => test()->matter, 'pageClass' => ViewMatter::class])
        ->callAction(TestAction::make('upload')->table(), data: [
            'file' => $file,
            'title' => 'Thông báo thụ lý',
            'group' => DocumentGroup::Authority->value,
        ]);
}

function stgClientSubmit(UploadedFile $file): Testable
{
    Filament::setCurrentPanel('portal');

    return test()->actingAs(test()->clientUser, 'client')
        ->livewire(SubmitDocument::class, ['record' => test()->matter->getKey()])
        ->call('chooseItem', test()->item->getKey())
        ->set('data.file', $file)
        ->call('submit');
}

/**
 * Kết nối `storage` chạy đồng bộ, Action đẩy được thay bằng bản ghi lại media và mức transaction
 * lúc job chạy. Trả mức transaction "ngoài cùng" của test (RefreshDatabase mở sẵn một mức).
 */
function stgRecordPushes(ArrayObject $calls): int
{
    config(['queue.connections.storage' => ['driver' => 'sync']]);

    app()->bind(PushDocumentFileToRemote::class, fn () => new class($calls) extends PushDocumentFileToRemote
    {
        public function __construct(private ArrayObject $calls) {}

        public function handle(int $mediaId, ?CarbonInterface $keepLocalUntil = null): PushOutcome
        {
            $this->calls[] = ['media_id' => $mediaId, 'level' => DB::transactionLevel()];

            return PushOutcome::Pushed;
        }
    });

    return DB::transactionLevel();
}

// ---------------------------------------------------------------------------------------------
// Khi nào KHÔNG có job.
// ---------------------------------------------------------------------------------------------

it('DOCUMENT_STORAGE=local: tải lên như hôm nay (tệp ở private), không job đẩy nào', function () {
    Queue::fake();

    stgStaffUpload(stgPdf())->assertHasNoActionErrors();

    $media = Media::query()->sole();

    expect($media->disk)->toBe(DocumentStore::STAGING_DISK);
    Storage::disk(DocumentStore::STAGING_DISK)->assertExists($media->getPathRelativeToRoot());
    Queue::assertNotPushed(PushDocumentFile::class);
});

it('google_drive nhưng CHƯA có mốc bật kho: không job đẩy nào', function () {
    Queue::fake();
    config(['vkcrm.storage.driver' => DocumentStore::DRIVER_GOOGLE_DRIVE]);

    stgStaffUpload(stgPdf())->assertHasNoActionErrors();

    expect(Media::query()->count())->toBe(1);
    Queue::assertNotPushed(PushDocumentFile::class);
});

it('media tạo thẳng trên một đĩa khác private: không job đẩy nào; cặp dương trên private thì có', function () {
    Queue::fake();
    StagingFixtures::enableRemote();

    $document = Document::factory()->for($this->matter)->create();
    $document->addMedia(stgPdf())->usingFileName('01k6xq0f9m2y7c4w8r3t5v6n1b.pdf')->toMediaCollection('file', DocumentStore::REMOTE_DISK);

    Queue::assertNotPushed(PushDocumentFile::class);

    $staged = StagingFixtures::media();

    Queue::assertPushed(PushDocumentFile::class, fn (PushDocumentFile $job): bool => $job->mediaId === $staged->id);
    Queue::assertPushed(PushDocumentFile::class, 1);
});

// ---------------------------------------------------------------------------------------------
// Có mốc: job sau commit, qua hai màn hình thật.
// ---------------------------------------------------------------------------------------------

it('nhân sự tải lên qua bảng Tài liệu khi kho đã bật: đúng một job đẩy media vừa tạo, hàng storage, sau commit', function () {
    Queue::fake();
    StagingFixtures::enableRemote();

    stgStaffUpload(stgPdf())->assertHasNoActionErrors();

    $media = Media::query()->sole();

    Queue::assertPushedOn('storage', PushDocumentFile::class, fn (PushDocumentFile $job): bool => $job->mediaId === $media->id
        && $job->connection === 'storage'
        && $job->afterCommit === true);
    Queue::assertPushed(PushDocumentFile::class, 1);
});

it('nhân sự tải lên: job chạy khi transaction của Action ghi tệp đã commit, không phải bên trong nó', function () {
    StagingFixtures::enableRemote();
    $calls = new ArrayObject;
    $base = stgRecordPushes($calls);

    stgStaffUpload(stgPdf())->assertHasNoActionErrors();

    expect($calls->getArrayCopy())->toBe([['media_id' => Media::query()->sole()->id, 'level' => $base]]);
});

it('khách nộp qua cổng khi kho đã bật: job chạy sau commit, cho đúng media vừa tạo', function () {
    StagingFixtures::enableRemote();
    $calls = new ArrayObject;
    $base = stgRecordPushes($calls);

    stgClientSubmit(stgPdf('so-do.pdf'))->assertHasNoErrors();

    $media = Media::query()->sole();

    expect($media->disk)->toBe(DocumentStore::STAGING_DISK)
        ->and($calls->getArrayCopy())->toBe([['media_id' => $media->id, 'level' => $base]]);
});

it('nhân sự tải lên mà transaction rollback sau addMedia: không job nào chạy, không tài liệu nào', function () {
    StagingFixtures::enableRemote();
    $calls = new ArrayObject;
    stgRecordPushes($calls);
    Event::listen(MediaHasBeenAddedEvent::class, fn () => throw new RuntimeException('ép lỗi sau addMedia'));

    expect(fn () => stgStaffUpload(stgPdf()))->toThrow(RuntimeException::class, 'ép lỗi sau addMedia');

    expect($calls->getArrayCopy())->toBe([])
        ->and(Document::query()->count())->toBe(0)
        ->and(Media::query()->count())->toBe(0);
});

it('khách nộp qua cổng mà transaction rollback sau addMedia: không job nào chạy, không tài liệu nào', function () {
    StagingFixtures::enableRemote();
    $calls = new ArrayObject;
    stgRecordPushes($calls);
    Event::listen(MediaHasBeenAddedEvent::class, fn () => throw new RuntimeException('ép lỗi sau addMedia'));

    expect(fn () => stgClientSubmit(stgPdf('so-do.pdf')))->toThrow(RuntimeException::class, 'ép lỗi sau addMedia');

    expect($calls->getArrayCopy())->toBe([])
        ->and(Document::query()->count())->toBe(0)
        ->and(Media::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------------------------
// Cổng tệp vẫn chặn TRƯỚC (SPEC §6.6 bước 2-5): không media, không job, kho rỗng.
// ---------------------------------------------------------------------------------------------

it('tệp bị chặn trước khi vào hệ thống: không media, không job, đĩa kho rỗng', function (Closure $file, ?Closure $scanner, string $errorField) {
    Queue::fake();
    StagingFixtures::enableRemote();

    if ($scanner !== null) {
        app()->instance(VirusScanner::class, $scanner());
    }

    stgStaffUpload($file())->assertHasActionErrors([$errorField]);

    expect(Document::query()->count())->toBe(0)
        ->and(Media::query()->count())->toBe(0)
        ->and(Storage::disk(DocumentStore::REMOTE_DISK)->allFiles())->toBe([]);
    Queue::assertNotPushed(PushDocumentFile::class);
})->with([
    'tệp .svg' => [fn () => UploadedFile::fake()->createWithContent('chu-ky.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'), null, 'file'],
    '.pdf mang MIME application/x-dosexec' => [fn () => UploadedFile::fake()->createWithContent('so-do.pdf', stgDosExecutableBytes()), null, 'file'],
    'VirusScanner từ chối' => [fn () => stgPdf(), fn () => new class implements VirusScanner
    {
        public function scan(string $path): void
        {
            throw FileRejected::virusDetected();
        }

        public function isActive(): bool
        {
            return true;
        }
    }, 'file'],
]);

it('khách nộp tệp bị VirusScanner từ chối khi kho đã bật: không media, không job, đĩa kho rỗng', function () {
    Queue::fake();
    StagingFixtures::enableRemote();
    app()->instance(VirusScanner::class, new class implements VirusScanner
    {
        public function scan(string $path): void
        {
            throw FileRejected::virusDetected();
        }

        public function isActive(): bool
        {
            return true;
        }
    });

    stgClientSubmit(stgPdf('so-do.pdf'))->assertHasErrors('data.file');

    expect(Media::query()->count())->toBe(0)
        ->and(Storage::disk(DocumentStore::REMOTE_DISK)->allFiles())->toBe([]);
    Queue::assertNotPushed(PushDocumentFile::class);
});

/** MZ + PE tối thiểu: `finfo` nhận ra `application/x-dosexec` (cùng khuôn `FileGuardTest`). */
function stgDosExecutableBytes(): string
{
    $header = str_repeat("\x00", 64);
    $header[0] = 'M';
    $header[1] = 'Z';
    $pointer = pack('V', 0x40);

    for ($i = 0; $i < 4; $i++) {
        $header[0x3C + $i] = $pointer[$i];
    }

    return $header."PE\x00\x00".pack('v', 0x014C).pack('v', 0).str_repeat("\x00", 16).str_repeat("\x00", 200);
}
