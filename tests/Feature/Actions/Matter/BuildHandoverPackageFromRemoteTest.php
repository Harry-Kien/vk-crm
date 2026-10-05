<?php

use App\Actions\Matter\BuildHandoverPackage;
use App\Actions\Matter\RecordHandoverPackageFailure;
use App\Actions\Matter\RequestHandoverPackage;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\DriveObjectRetirement;
use App\Enums\HandoverPackageStatus;
use App\Enums\InstalmentStatus;
use App\Enums\Role;
use App\Exceptions\DocumentStorageMisconfigured;
use App\Exceptions\DocumentStorageUnavailable;
use App\Exceptions\HandoverPackageFailed;
use App\Jobs\GenerateHandoverPackage;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Models\DriveObject;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\Payment;
use App\Models\User;
use App\Support\Files\FreeSpace;
use App\Support\Storage\DocumentStore;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\UnableToReadFile;
use Spatie\MediaLibrary\MediaCollections\Filesystem as MediaFilesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Support\RemoteDocuments;
use Tests\Support\SpyFilesystem;

/*
|--------------------------------------------------------------------------
| M14 Task 4 — gói bàn giao dựng từ media nằm trên kho (kế hoạch M14, R12)
|--------------------------------------------------------------------------
|
| `BuildHandoverPackage` không còn hỏi `$disk->path()`: tệp trên kho được TẢI về `work_dir/src/<NN>`
| (`MaterialiseStoredFile`, kiểm cỡ và md5), chỗ trống được kiểm trước khi đo được (`FreeSpace`), và
| `src/` bị xoá TRƯỚC khi medialibrary chép zip. Mọi khẳng định về nội dung đọc lại zip ĐÃ GHI (khuôn
| M7 R2), không mảng đầu vào.
*/

const HR_MB = 1024 * 1024;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Queue::fake();

    // Khoá trên kho là `<media_id>/<tên>` (khuôn R4, không tiền tố): đẩy chuỗi id của `media` tới
    // một số ngẫu nhiên để các test không ghi, xoá, ghi lại cùng đường trên gốc đĩa giả.
    if (DB::connection()->getDriverName() === 'sqlite') {
        DB::table('sqlite_sequence')->updateOrInsert(['name' => 'media'], ['seq' => random_int(1_000, 9_000_000)]);
    }

    $this->workRoot = storage_path('framework/testing/handover-remote-'.Str::random(16));
    config(['vkcrm.handover.work_dir' => $this->workRoot]);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->client = Client::factory()->create();
    $this->matter = Matter::factory()->for($this->client)->create([
        'lead_lawyer_id' => $this->lawyer->id,
        'closed_at' => now()->subDay()->toDateString(),
    ]);

    $this->archive = MatterArchive::factory()->create([
        'matter_id' => $this->matter->id,
        'archived_by' => $this->lawyer->id,
        'handover_status' => HandoverPackageStatus::Generating,
        'handover_requested_at' => now()->startOfSecond(),
        'handover_requested_by' => $this->lawyer->id,
    ]);

    $this->token = $this->archive->fresh()->handover_requested_at->getTimestamp();
});

afterEach(fn () => File::deleteDirectory($this->workRoot));

/** Tài liệu có tệp thật trong vùng đệm, tên tệp đúng khuôn khoá R4. */
function hrDocument(Matter $matter, DocumentGroup $group, DocumentStatus $status, string $title, string $content, array $attributes = []): Document
{
    $document = Document::factory()->create([
        'matter_id' => $matter->id,
        'group' => $group,
        'status' => $status,
        'title' => $title,
        ...$attributes,
    ]);

    $document->addMedia(UploadedFile::fake()->createWithContent('nguon.pdf', $content))
        ->usingName($title)
        ->usingFileName(Str::lower((string) Str::ulid()).'.pdf')
        ->toMediaCollection('file');

    return $document->refresh();
}

/** Đẩy tệp của tài liệu lên kho (Action thật của Task 3) và dọn vùng đệm: tệp CHỈ còn trên kho. */
function hrRemote(Document $document): Document
{
    $media = RemoteDocuments::pushToRemote($document->getFirstMedia('file'));

    expect($media->disk)->toBe(DocumentStore::REMOTE_DISK)
        ->and(DocumentStore::staging()->exists($media->getPathRelativeToRoot()))->toBeFalse();

    return $document->refresh();
}

function hrBuild(object $test): ?Document
{
    return app(BuildHandoverPackage::class)->handle($test->matter->id, $test->token);
}

function hrRequestAgain(object $test, int $seconds): void
{
    MatterArchive::query()->whereKey($test->archive->id)->update([
        'handover_status' => HandoverPackageStatus::Generating->value,
        'handover_requested_at' => now()->addSeconds($seconds)->startOfSecond(),
    ]);

    $test->token = $test->archive->fresh()->handover_requested_at->getTimestamp();
}

/** @return array<string, string> tên entry => nội dung, đọc từ zip ĐÃ GHI của gói */
function hrZip(Document $package): array
{
    $media = $package->getFirstMedia('file');
    $zip = new ZipArchive;

    expect($zip->open(Storage::disk($media->disk)->path($media->getPathRelativeToRoot()), ZipArchive::RDONLY))->toBeTrue();

    $entries = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $entries[$name] = (string) $zip->getFromIndex($i);
    }

    $zip->close();
    ksort($entries);

    return $entries;
}

function hrWorkRootIsEmpty(): bool
{
    $directory = config('vkcrm.handover.work_dir');

    return ! is_dir($directory) || File::allFiles($directory) === [] && File::directories($directory) === [];
}

function hrPackages(): int
{
    return Document::query()->where('title', 'like', 'Gói bàn giao%')->count();
}

/** `FreeSpace` cho hai nơi: thư mục làm việc của gói, và gốc đĩa `private`. */
function hrFreeSpace(object $test, ?int $work, ?int $staging): void
{
    app()->instance(FreeSpace::class, new FreeSpace(function (string $path) use ($test, $work, $staging) {
        $value = str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', $test->workRoot)) ? $work : $staging;

        return $value ?? false;
    }));
}

// ---------------------------------------------------------------------------------------------
// Gói từ media trên kho: zip thật, đúng byte, đúng luật chọn
// ---------------------------------------------------------------------------------------------

it('gói dựng từ media CHỈ còn trên kho: giải nén đúng byte, tên tiếng Việt còn nguyên; nhóm D, xoá mềm, B còn nháp, đã rút vắng mặt', function () {
    $accepted = hrRemote(hrDocument($this->matter, DocumentGroup::ClientProvided, DocumentStatus::Published, 'Bản sao căn cước công dân', 'NOI-DUNG-CCCD'));
    $ruling = hrRemote(hrDocument($this->matter, DocumentGroup::Authority, DocumentStatus::SignedFiled, 'Quyết định thụ lý vụ án', 'NOI-DUNG-THU-LY', ['issued_at' => now()->subDays(3)->toDateString()]));
    $internal = hrRemote(hrDocument($this->matter, DocumentGroup::Internal, DocumentStatus::Published, 'Ghi chú nội bộ của luật sư', 'NOI-DUNG-NOI-BO'));
    $draft = hrRemote(hrDocument($this->matter, DocumentGroup::Issued, DocumentStatus::InternalDraft, 'Bản nháp đơn khởi kiện', 'NOI-DUNG-NHAP'));
    $deleted = hrRemote(hrDocument($this->matter, DocumentGroup::Issued, DocumentStatus::SignedFiled, 'Đơn đã xoá', 'NOI-DUNG-DA-XOA'));
    hrRemote(hrDocument($this->matter, DocumentGroup::Issued, DocumentStatus::Retracted, 'Văn bản đã rút', 'NOI-DUNG-DA-RUT', ['retracted_at' => now()]));

    $deleted->delete();

    $package = hrBuild($this);
    $entries = hrZip($package);
    $contents = array_values(array_diff_key($entries, [BuildHandoverPackage::INDEX_ENTRY => true]));
    sort($contents);

    expect(array_keys($entries))->toBe([
        'A/01-Bản sao căn cước công dân.pdf',
        'C/02-Quyết định thụ lý vụ án.pdf',
        BuildHandoverPackage::INDEX_ENTRY,
    ])
        ->and($entries['A/01-Bản sao căn cước công dân.pdf'])->toBe('NOI-DUNG-CCCD')
        ->and($entries['C/02-Quyết định thụ lý vụ án.pdf'])->toBe('NOI-DUNG-THU-LY')
        ->and($contents)->not->toContain('NOI-DUNG-NOI-BO')
        ->and($contents)->not->toContain('NOI-DUNG-NHAP')
        ->and($contents)->not->toContain('NOI-DUNG-DA-XOA')
        ->and($contents)->not->toContain('NOI-DUNG-DA-RUT')
        // Nguồn vẫn ở kho, gói mới vào vùng đệm như mọi tệp (R2).
        ->and($accepted->getFirstMedia('file')->disk)->toBe(DocumentStore::REMOTE_DISK)
        ->and($ruling->getFirstMedia('file')->disk)->toBe(DocumentStore::REMOTE_DISK)
        ->and($package->getFirstMedia('file')->disk)->toBe(DocumentStore::STAGING_DISK)
        ->and(hrWorkRootIsEmpty())->toBeTrue();

    expect($internal->exists && $draft->exists)->toBeTrue();
});

it('gói từ kho đọc mỗi tệp đúng MỘT lần (readStream) và không bao giờ hỏi đường dẫn cục bộ của đĩa kho', function () {
    hrRemote(hrDocument($this->matter, DocumentGroup::Authority, DocumentStatus::SignedFiled, 'Bản án sơ thẩm', 'BAN-AN'));
    hrRemote(hrDocument($this->matter, DocumentGroup::Issued, DocumentStatus::SignedFiled, 'Đơn khởi kiện', 'DON-KHOI-KIEN'));
    $spy = SpyFilesystem::install();

    $package = hrBuild($this);

    expect($spy->count('readStream'))->toBe(2)
        ->and($spy->count('read'))->toBe(0)
        ->and(array_values(array_diff_key(hrZip($package), [BuildHandoverPackage::INDEX_ENTRY => true])))->toEqualCanonicalizing(['BAN-AN', 'DON-KHOI-KIEN']);
});

// ---------------------------------------------------------------------------------------------
// Chỗ trống: 2T + 50 MB ở thư mục làm việc, T + 50 MB ở gốc đĩa private
// ---------------------------------------------------------------------------------------------

it('thiếu chỗ trống ở thư mục làm việc (2T + 50 MB − 1): lỗi tiếng Việt, không zip dở, không tài liệu', function () {
    $document = hrRemote(hrDocument($this->matter, DocumentGroup::Authority, DocumentStatus::SignedFiled, 'Bản án', str_repeat('x', 1000)));
    $total = (int) $document->getFirstMedia('file')->size;
    hrFreeSpace($this, work: 2 * $total + 50 * HR_MB - 1, staging: PHP_INT_MAX);
    $spy = SpyFilesystem::install();

    expect(fn () => hrBuild($this))->toThrow(HandoverPackageFailed::class, 'Máy chủ không đủ chỗ trống');

    expect(hrPackages())->toBe(0)
        // Kiểm TRƯỚC khi tải gì về.
        ->and($spy->count('readStream'))->toBe(0)
        ->and($this->archive->fresh()->handover_document_id)->toBeNull()
        ->and(hrWorkRootIsEmpty())->toBeTrue();
});

it('đúng biên chỗ trống (2T + 50 MB ở thư mục làm việc, T + 50 MB ở gốc private): gói dựng được', function () {
    $document = hrRemote(hrDocument($this->matter, DocumentGroup::Authority, DocumentStatus::SignedFiled, 'Bản án', str_repeat('x', 1000)));
    $total = (int) $document->getFirstMedia('file')->size;
    hrFreeSpace($this, work: 2 * $total + 50 * HR_MB, staging: $total + 50 * HR_MB);

    expect(hrBuild($this))->toBeInstanceOf(Document::class);
});

it('thiếu chỗ trống ở gốc đĩa private (T + 50 MB − 1): lỗi tiếng Việt, không tài liệu', function () {
    $document = hrRemote(hrDocument($this->matter, DocumentGroup::Authority, DocumentStatus::SignedFiled, 'Bản án', str_repeat('x', 1000)));
    $total = (int) $document->getFirstMedia('file')->size;
    hrFreeSpace($this, work: PHP_INT_MAX, staging: $total + 50 * HR_MB - 1);

    expect(fn () => hrBuild($this))->toThrow(HandoverPackageFailed::class, 'Máy chủ không đủ chỗ trống');

    expect(hrPackages())->toBe(0)->and(hrWorkRootIsEmpty())->toBeTrue();
});

it('không đo được chỗ trống (disk_free_space bị tắt): gói VẪN dựng được, có log warning', function (string $mode) {
    $document = hrDocument($this->matter, DocumentGroup::Authority, DocumentStatus::SignedFiled, 'Bản án', 'BAN-AN');

    if ($mode === 'remote') {
        hrRemote($document);
    }

    // Tên hàm không tồn tại = hàm bị tắt trong `disable_functions` (PHP 8: hàm bị tắt là hàm không có).
    app()->instance(FreeSpace::class, new FreeSpace('vkcrm_disk_free_space_disabled'));
    Log::spy();

    $package = hrBuild($this);

    expect(hrZip($package))->toHaveKey('C/01-Bản án.pdf');

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => $message === __('storage.read.log.free_space_unknown'))->once();
})->with(['local', 'remote']);

// ---------------------------------------------------------------------------------------------
// src/ bị xoá TRƯỚC khi medialibrary chép zip (đỉnh ≈ 2T, không 3T)
// ---------------------------------------------------------------------------------------------

it('work_dir/src không còn lúc medialibrary chép zip vào kho, dù tệp đã được tải về đó', function () {
    hrRemote(hrDocument($this->matter, DocumentGroup::Authority, DocumentStatus::SignedFiled, 'Bản án', 'BAN-AN'));

    $seen = (object) ['calls' => 0, 'srcExisted' => null, 'zipExisted' => null];
    $source = BuildHandoverPackage::workDirectory($this->matter->id, $this->token).DIRECTORY_SEPARATOR.'src';

    $this->app->bind(MediaFilesystem::class, fn ($app) => new class($app->make(FilesystemFactory::class), $seen, $source) extends MediaFilesystem
    {
        public function __construct(FilesystemFactory $filesystem, private object $seen, private string $source)
        {
            parent::__construct($filesystem);
        }

        public function copyToMediaLibrary(string $pathToFile, Media $media, ?string $type = null, ?string $targetFileName = null): void
        {
            $this->seen->calls++;
            $this->seen->srcExisted = is_dir($this->source);
            $this->seen->zipExisted = is_file($pathToFile);

            parent::copyToMediaLibrary($pathToFile, $media, $type, $targetFileName);
        }
    });

    $package = hrBuild($this);

    expect($seen->calls)->toBe(1)
        ->and($seen->zipExisted)->toBeTrue()
        ->and($seen->srcExisted)->toBeFalse()
        ->and(hrZip($package))->toHaveKey('C/01-Bản án.pdf');
});

// ---------------------------------------------------------------------------------------------
// Kho sập, tệp mất, md5 lệch
// ---------------------------------------------------------------------------------------------

it('kho sập giữa lúc tải về: storageUnavailable tiếng Việt, thư mục làm việc bị xoá, luật sư bấm sinh lại được', function () {
    hrRemote(hrDocument($this->matter, DocumentGroup::Authority, DocumentStatus::SignedFiled, 'Bản án sơ thẩm', 'BAN-AN'));
    hrRemote(hrDocument($this->matter, DocumentGroup::Issued, DocumentStatus::SignedFiled, 'Đơn khởi kiện', 'DON'));

    // Tệp đầu tải được, tệp thứ hai gặp kho sập: tệp đã tải về src/ phải bị dọn cùng thư mục.
    $calls = 0;
    SpyFilesystem::install(hooks: ['readStream' => function () use (&$calls) {
        if (++$calls === 2) {
            throw DocumentStorageUnavailable::temporarily();
        }
    }]);

    (new GenerateHandoverPackage($this->matter->id, $this->token))
        ->handle(app(BuildHandoverPackage::class), app(RecordHandoverPackageFailure::class));

    $archive = $this->archive->fresh();

    expect($archive->handover_status)->toBe(HandoverPackageStatus::Failed)
        ->and($archive->handover_error)->toBe(__('handover.storage_failures.unavailable'))
        ->and(hrPackages())->toBe(0)
        ->and(hrWorkRootIsEmpty())->toBeTrue();

    // Sinh lại: nút mở, kho đã lên lại, gói dựng được.
    SpyFilesystem::install();
    $requested = app(RequestHandoverPackage::class)->handle($this->matter->id, $this->lawyer);
    $this->token = $requested->handover_requested_at->getTimestamp();

    $package = hrBuild($this);

    expect(array_values(array_diff_key(hrZip($package), [BuildHandoverPackage::INDEX_ENTRY => true])))->toEqualCanonicalizing(['BAN-AN', 'DON']);
});

it('kho sập thật (Google trả 503 hết lượt thử) lúc tải về: storageUnavailable, không tài liệu', function () {
    $document = hrDocument($this->matter, DocumentGroup::Authority, DocumentStatus::SignedFiled, 'Bản án', 'BAN-AN');
    $drive = RemoteDocuments::bindRealDriveAdapter([$document->getFirstMedia('file')]);
    $drive->failNext('GET', 'alt=media', 503, times: 10);

    expect(fn () => hrBuild($this))->toThrow(HandoverPackageFailed::class, __('handover.storage_failures.unavailable'));

    expect(hrPackages())->toBe(0)->and(hrWorkRootIsEmpty())->toBeTrue();
});

it('kho nói không có tệp lúc tải về (chỉ mục còn ghi): missingFile nêu đúng tiêu đề, không tài liệu', function () {
    hrRemote(hrDocument($this->matter, DocumentGroup::Authority, DocumentStatus::SignedFiled, 'Bản án bị mất trên kho', 'BAN-AN'));
    SpyFilesystem::install(hooks: ['readStream' => fn (string $path) => throw UnableToReadFile::fromLocation($path)]);

    expect(fn () => hrBuild($this))->toThrow(HandoverPackageFailed::class, __('handover.exceptions.missing_file', ['title' => 'Bản án bị mất trên kho']));

    expect(hrPackages())->toBe(0)->and(hrWorkRootIsEmpty())->toBeTrue();
});

it('md5 hay cỡ của bản tải về lệch dòng media: lỗi, không zip, không tài liệu', function (string $tamper) {
    $document = hrRemote(hrDocument($this->matter, DocumentGroup::Authority, DocumentStatus::SignedFiled, 'Bản án', 'BAN-AN-GOC'));
    $key = $document->getFirstMedia('file')->getPathRelativeToRoot();

    // Bản trên kho bị đổi ngoài CRM: cùng cỡ khác nội dung (md5 lệch), hay khác cỡ. Ca thứ ba: dòng
    // không có md5 để so (cột rỗng) — chỉ còn cỡ đứng gác.
    DocumentStore::remote()->put($key, $tamper === 'md5' ? 'BAN-AN-SUA' : 'BAN-AN-GOC-DAI-HON');

    if ($tamper === 'cỡ, dòng không có md5') {
        Media::query()->toBase()->where('id', $document->getFirstMedia('file')->id)->update(['checksum_md5' => null]);
    }

    Log::spy();

    expect(fn () => hrBuild($this))->toThrow(HandoverPackageFailed::class, __('handover.storage_failures.unavailable'));

    expect(hrPackages())->toBe(0)
        ->and(Media::query()->where('collection_name', 'file')->count())->toBe(1)
        ->and(hrWorkRootIsEmpty())->toBeTrue();

    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context = []): bool => $message === __('storage.read.log.checksum_mismatch') && ($context['key'] ?? null) === $key)->once();
})->with(['md5', 'size', 'cỡ, dòng không có md5']);

it('dòng không có md5 mà cỡ khớp: gói dựng được từ kho (cặp dương của ca chỉ còn cỡ đứng gác)', function () {
    $document = hrRemote(hrDocument($this->matter, DocumentGroup::Authority, DocumentStatus::SignedFiled, 'Bản án', 'BAN-AN-GOC'));
    Media::query()->toBase()->where('id', $document->getFirstMedia('file')->id)->update(['checksum_md5' => null]);

    expect(hrZip(hrBuild($this))['C/01-Bản án.pdf'])->toBe('BAN-AN-GOC');
});

// ---------------------------------------------------------------------------------------------
// Sinh lại: bản trước trên kho vào thùng rác chỉ khi không ai tham chiếu
// ---------------------------------------------------------------------------------------------

/** Dựng gói v1 rồi đặt nó lên kho Drive THẬT (adapter trên HTTP giả). Trả [v1, Drive giả, mã tệp Drive]. */
function hrFirstPackageOnDrive(object $test): array
{
    $first = hrBuild($test);
    $media = $first->getFirstMedia('file');
    $drive = RemoteDocuments::bindRealDriveAdapter([$media]);
    $fileId = DriveObject::query()->withoutGlobalScopes()->where('object_key', $media->refresh()->getPathRelativeToRoot())->value('file_id');

    expect($media->disk)->toBe(DocumentStore::REMOTE_DISK)
        ->and($fileId)->not->toBeNull()
        ->and($drive->files[$fileId]['trashed'])->toBeFalse();

    return [$first, $drive, $fileId];
}

it('sinh lại: bản trước trên kho KHÔNG ai tham chiếu → bản đó vào thùng rác Drive, dòng chỉ mục rời (trashed)', function () {
    [$first, $drive, $fileId] = hrFirstPackageOnDrive($this);

    hrRequestAgain($this, 5);
    $second = hrBuild($this);

    $row = DriveObject::query()->withoutGlobalScopes()->where('file_id', $fileId)->first();

    expect($second->version)->toBe(2)
        ->and($drive->files[$fileId]['trashed'])->toBeTrue()
        ->and($row->object_key)->toBeNull()
        ->and($row->retired_reason)->toBe(DriveObjectRetirement::Trashed)
        ->and(Media::query()->where('model_id', $first->id)->where('model_type', $first->getMorphClass())->count())->toBe(0);
});

it('sinh lại: bản trước trên kho đã được khách tải, hay là biên lai của khoản thu → bản trên kho còn nguyên', function (string $reference) {
    [$first, $drive, $fileId] = hrFirstPackageOnDrive($this);

    if ($reference === 'khách đã tải') {
        $clientUser = ClientUser::factory()->activated()->create(['client_id' => $this->client->id]);
        DocumentDownload::factory()->create([
            'document_id' => $first->id,
            'downloader_type' => $clientUser->getMorphClass(),
            'downloader_id' => $clientUser->id,
        ]);
    } else {
        $contract = Contract::factory()->for($this->matter)->active()->create(['total_amount' => 10_000_000]);
        $instalment = Instalment::factory()->for($contract)->create(['amount' => 10_000_000, 'status' => InstalmentStatus::Pending]);
        Payment::factory()->for($instalment)->create([
            'amount' => 4_000_000,
            'receipt_document_id' => $first->id,
            'attributed_lawyer_id' => $this->lawyer->id,
        ]);
    }

    hrRequestAgain($this, 5);
    $second = hrBuild($this);

    $row = DriveObject::query()->withoutGlobalScopes()->where('file_id', $fileId)->first();

    expect($second->version)->toBe(2)
        ->and($drive->files[$fileId]['trashed'])->toBeFalse()
        ->and($row->object_key)->not->toBeNull()
        ->and($row->retired_reason)->toBeNull()
        ->and($first->refresh()->getMedia('file'))->toHaveCount(1);
})->with(['khách đã tải', 'biên lai khoản thu']);

it('tên tải gói từ kho: tên gói là mã vụ, entry dùng đuôi của tệp thật', function () {
    hrRemote(hrDocument($this->matter, DocumentGroup::Authority, DocumentStatus::SignedFiled, 'Biên bản hoà giải ngày 05.3.2026', 'BIEN-BAN'));

    $package = hrBuild($this);

    expect(hrZip($package))->toHaveKey('C/01-Biên bản hoà giải ngày 05.3.2026.pdf')
        ->and($package->getFirstMedia('file')->file_name)->toMatch('/^[0-9a-z]{26}\.zip$/');
});

it('cấu hình kho hỏng lộ ra ngay lúc chọn tệp (adapter dựng lười): storageUnavailable, không tài liệu', function () {
    hrRemote(hrDocument($this->matter, DocumentGroup::Authority, DocumentStatus::SignedFiled, 'Bản án', 'BAN-AN'));
    SpyFilesystem::install(hooks: ['fileExists' => fn () => throw DocumentStorageMisconfigured::credentialsMissing()]);

    expect(fn () => hrBuild($this))->toThrow(HandoverPackageFailed::class, __('handover.storage_failures.unavailable'));

    expect(hrPackages())->toBe(0)->and(hrWorkRootIsEmpty())->toBeTrue();
});
