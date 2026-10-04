<?php

use App\Enums\DriveObjectRetirement;
use App\Exceptions\DocumentStorageUnavailable;
use App\Exceptions\StoredFileMissing;
use App\Models\DriveFolder;
use App\Models\DriveObject;
use App\Support\Storage\GoogleDrive\DriveAdapter;
use App\Support\Storage\GoogleDrive\DriveCircuitBreaker;
use App\Support\Storage\GoogleDrive\DriveObjectName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use League\Flysystem\Config;
use League\Flysystem\CorruptedPathDetected;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToProvideChecksum;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Support\FakeGoogleDrive;
use Tests\Support\NonSeekableTestStream;

/*
|--------------------------------------------------------------------------
| M14 Task 2 — DriveAdapter: adapter Flysystem cho Google Drive (kế hoạch M14, R3, R4, R8)
|--------------------------------------------------------------------------
|
| Khoá mờ có số thế hệ, chỉ mục `drive_objects` trả lời mọi câu hỏi không cần byte (tồn tại, cỡ,
| loại, liệt kê) mà không gọi mạng, thư mục là TIỀN TỐ CÓ `/`, ghi vào khoá đã có bị từ chối, xoá
| là cho vào thùng rác. Adapter thật trên máy chủ Drive giả (`Http::fake()` +
| `Http::preventStrayRequests()`), nên "không request nào" là phép đo đỏ được.
*/

uses(RefreshDatabase::class);

const ADAPTER_ULID = '01k6xq0f9m2y7c4w8r3t5v6n1b';
const ADAPTER_ULID_2 = '01k6xq0f9m2y7c4w8r3t5v6n2c';

beforeEach(function () {
    $this->drive = FakeGoogleDrive::install();
    $this->travelTo(now()->setDate(2026, 10, 4)->setTime(9, 0));
});

/** @return list<string> mã tệp của mọi files.update trashed=true đã gửi */
function trashedFileIds(): array
{
    return Http::recorded()
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $request) => $request->method() === 'PATCH' && json_decode($request->body(), true) === ['trashed' => true])
        ->map(fn (Request $request) => basename((string) parse_url($request->url(), PHP_URL_PATH)))
        ->values()
        ->all();
}

// -------------------------------------------------------------------------------------------------
// Ghi
// -------------------------------------------------------------------------------------------------

it('ghi: tải lên thư mục tháng dưới thư mục gốc, tên mờ theo khoá, rồi ghi dòng chỉ mục', function () {
    $key = '1834/'.ADAPTER_ULID.'.pdf';

    $this->drive->disk()->put($key, 'noi dung ho so');

    $row = DriveObject::query()->where('object_key', $key)->sole();
    $folder = DriveFolder::query()->sole();
    $file = $this->drive->files[$row->file_id];

    expect($file['name'])->toBe('1834~'.ADAPTER_ULID.'.pdf')
        ->and($file['content'])->toBe('noi dung ho so')
        ->and($file['parents'])->toBe([$folder->folder_id])
        ->and($folder->name)->toBe('2026-10')
        ->and($folder->root_folder_id)->toBe(FakeGoogleDrive::ROOT_FOLDER_ID)
        ->and($folder->drive_id)->toBe(FakeGoogleDrive::DRIVE_ID)
        ->and($this->drive->files[$folder->folder_id]['parents'])->toBe([FakeGoogleDrive::ROOT_FOLDER_ID])
        ->and($row->drive_id)->toBe(FakeGoogleDrive::DRIVE_ID)
        ->and($row->generation)->toBe(1)
        ->and($row->parent_id)->toBe($folder->folder_id)
        ->and($row->size)->toBe(14)
        ->and($row->md5)->toBe(md5('noi dung ho so'))
        ->and($row->mime_type)->toBe('application/pdf');
});

it('ghi bằng luồng: cỡ đo từ luồng, mime lấy từ tuỳ chọn mimetype nếu có', function () {
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, 'PK zip');
    rewind($stream);

    // Đuôi `.bin` không cho đoán ra `application/zip`: loại phải đến từ tuỳ chọn.
    $this->drive->adapter()->writeStream('7/'.ADAPTER_ULID.'.bin', $stream, new Config(['mimetype' => 'application/zip']));

    $row = DriveObject::query()->sole();

    expect($row->size)->toBe(6)
        ->and($row->mime_type)->toBe('application/zip')
        ->and($this->drive->files[$row->file_id]['mimeType'])->toBe('application/zip');
});

it('ghi khi không có tuỳ chọn mimetype và đuôi lạ → application/octet-stream', function () {
    $this->drive->adapter()->write('7/'.ADAPTER_ULID.'.khongladuoi', 'x', new Config);

    expect(DriveObject::query()->sole()->mime_type)->toBe('application/octet-stream');
});

it('ghi vào một khoá đã có → UnableToWriteFile, không request nào (tệp hồ sơ bất biến, R8)', function () {
    $key = '18/'.ADAPTER_ULID.'.pdf';
    $this->drive->seed($key, 'ban goc');

    expect(fn () => $this->drive->disk()->put($key, 'ban moi'))->toThrow(UnableToWriteFile::class);

    Http::assertNothingSent();
    expect(DriveObject::query()->count())->toBe(1);
});

it('ghi lại một khoá mà bản trước đã vào thùng rác → thế hệ 2, tên có ~g2, đọc ngược đúng', function () {
    $key = '18/'.ADAPTER_ULID.'.pdf';
    $disk = $this->drive->disk();
    $disk->put($key, 'ban mot');
    $disk->delete($key);

    $disk->put($key, 'ban hai');

    $row = DriveObject::query()->where('object_key', $key)->sole();
    $name = $this->drive->files[$row->file_id]['name'];

    expect($row->generation)->toBe(2)
        ->and($name)->toBe('18~'.ADAPTER_ULID.'~g2.pdf')
        ->and(DriveObjectName::parse($name))->toBe(['key' => $key, 'generation' => 2]);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'uploadType=resumable')
        && $request->method() === 'POST'
        && json_decode($request->body(), true)['name'] === '18~'.ADAPTER_ULID.'~g2.pdf');
});

it('thế hệ kế tiếp lớn hơn mọi thế hệ đã có của khoá, kể cả khi các thế hệ không liền nhau', function () {
    $key = '18/'.ADAPTER_ULID.'.pdf';
    $this->drive->seed($key, 'ban cu', 5);
    $this->drive->disk()->delete($key);

    $this->drive->disk()->put($key, 'ban moi');

    expect(DriveObject::query()->where('object_key', $key)->sole()->generation)->toBe(6);
});

it('hai lượt ghi cùng tháng chỉ tạo một thư mục tháng; tháng sau thì thư mục mới', function () {
    $disk = $this->drive->disk();

    $disk->put('18/'.ADAPTER_ULID.'.pdf', 'a');
    $disk->put('19/'.ADAPTER_ULID.'.pdf', 'b');

    expect($this->drive->folders())->toHaveCount(1)
        ->and(DriveFolder::query()->count())->toBe(1);

    $this->travelTo(now()->setDate(2026, 11, 2));
    $disk->put('20/'.ADAPTER_ULID.'.pdf', 'c');

    expect($this->drive->folders())->toHaveCount(2)
        ->and(DriveFolder::query()->orderBy('name')->pluck('name')->all())->toBe(['2026-10', '2026-11']);
});

it('thư mục tháng đã có trong drive_folders thì không tạo lại, không tìm theo tên', function () {
    $existing = $this->drive->putFile('2026-10', '', [FakeGoogleDrive::ROOT_FOLDER_ID], 'application/vnd.google-apps.folder');
    DriveFolder::query()->create([
        'drive_id' => FakeGoogleDrive::DRIVE_ID,
        'root_folder_id' => FakeGoogleDrive::ROOT_FOLDER_ID,
        'name' => '2026-10',
        'folder_id' => $existing,
    ]);

    $this->drive->disk()->put('18/'.ADAPTER_ULID.'.pdf', 'a');

    expect(DriveObject::query()->sole()->parent_id)->toBe($existing);
    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST' && parse_url($request->url(), PHP_URL_PATH) === '/drive/v3/files');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'q='));
});

it('ghi chỉ mục hỏng sau khi đã tải lên → cho bản vừa tải vào thùng rác, ném UnableToWriteFile', function () {
    DriveObject::creating(fn () => throw new RuntimeException('CSDL hỏng'));

    try {
        expect(fn () => $this->drive->disk()->put('18/'.ADAPTER_ULID.'.pdf', 'a'))->toThrow(UnableToWriteFile::class);
    } finally {
        DriveObject::flushEventListeners();
    }

    $uploaded = $this->drive->named('18~'.ADAPTER_ULID.'.pdf');

    expect($uploaded)->toHaveCount(1)
        ->and($uploaded[0]['trashed'])->toBeTrue()
        ->and(DriveObject::query()->count())->toBe(0);
});

it('khoá không an toàn bị từ chối trước mọi request', function (string $key) {
    expect(fn () => $this->drive->adapter()->write($key, 'x', new Config))->toThrow(CorruptedPathDetected::class);

    Http::assertNothingSent();
    expect(DriveObject::query()->count())->toBe(0);
})->with([
    'có ~' => ['18/a~b.pdf'],
    'có ..' => ['18/../19/a.pdf'],
    '.. ở đầu' => ['../a.pdf'],
    'bắt đầu bằng /' => ['/18/a.pdf'],
    'ký tự điều khiển' => ["18/a\x01.pdf"],
    'xuống dòng' => ["18/a\n.pdf"],
    'khoảng trắng' => ['18/a b.pdf'],
    'tab' => ["18/a\tb.pdf"],
    'rỗng' => [''],
    'đoạn rỗng' => ['18//a.pdf'],
    'kết thúc bằng /' => ['18/'],
    'dài hơn cột object_key' => [str_repeat('a', 252).'.pdf'],
]);

it('thế hệ kế tiếp vượt 999 → UnableToWriteFile, không tải lên', function () {
    $key = '18/'.ADAPTER_ULID.'.pdf';
    $this->drive->seed($key, 'cu', 999);
    $this->drive->disk()->delete($key);
    $before = count(Http::recorded());

    expect(fn () => $this->drive->disk()->put($key, 'moi'))->toThrow(UnableToWriteFile::class);
    expect(count(Http::recorded()))->toBe($before);
});

it('ghi luồng không đo được cỡ (fstat không có): chép sang bộ nhớ tạm, cỡ và nội dung đúng', function () {
    stream_wrapper_register('vkcrm-khong-tua', NonSeekableTestStream::class);

    try {
        NonSeekableTestStream::$content = 'noi dung luong mot chieu';
        $stream = fopen('vkcrm-khong-tua://tep', 'rb');

        expect(fstat($stream))->toBeFalse();

        $this->drive->adapter()->writeStream('18/'.ADAPTER_ULID.'.pdf', $stream, new Config);
    } finally {
        stream_wrapper_unregister('vkcrm-khong-tua');
    }

    $row = DriveObject::query()->sole();

    expect($row->size)->toBe(24)
        ->and($this->drive->files[$row->file_id]['content'])->toBe('noi dung luong mot chieu');
});

it('chờ khoá thư mục tháng quá lâu → DocumentStorageUnavailable, không tải lên', function () {
    Sleep::fake(syncWithCarbon: true);
    $held = Cache::store('database')->lock('drive-folder:'.FakeGoogleDrive::ROOT_FOLDER_ID.':2026-10', 60);
    expect($held->get())->toBeTrue();

    expect(fn () => $this->drive->disk()->put('18/'.ADAPTER_ULID.'.pdf', 'a'))->toThrow(DocumentStorageUnavailable::class);

    Http::assertNothingSent();
    $held->release();
});

it('khoá có ~ qua đĩa (Flysystem không chặn ~) cũng bị từ chối', function () {
    expect(fn () => $this->drive->disk()->put('18/a~b.pdf', 'x'))->toThrow(CorruptedPathDetected::class);

    Http::assertNothingSent();
});

it('khoá dài đúng 255 ký tự vẫn ghi được', function () {
    $key = str_repeat('a', 251).'.pdf';

    $this->drive->adapter()->write($key, 'x', new Config);

    expect(DriveObject::query()->sole()->object_key)->toBe($key);
});

// -------------------------------------------------------------------------------------------------
// Đọc
// -------------------------------------------------------------------------------------------------

it('đọc: chỉ mục → đúng một GET alt=media', function () {
    $key = '18/'.ADAPTER_ULID.'.pdf';
    $row = $this->drive->seed($key, 'noi dung');

    expect($this->drive->disk()->get($key))->toBe('noi dung')
        ->and(stream_get_contents($this->drive->disk()->readStream($key)))->toBe('noi dung');

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && str_contains($request->url(), '/files/'.$row->file_id.'?')
        && str_contains($request->url(), 'alt=media'));
});

it('đọc khoá không có trong chỉ mục → UnableToReadFile, không request nào', function () {
    expect(fn () => $this->drive->disk()->readStream('18/'.ADAPTER_ULID.'.pdf'))->toThrow(UnableToReadFile::class);

    Http::assertNothingSent();
});

it('chỉ mục nói có mà Drive trả 404 → UnableToReadFile bọc StoredFileMissing mang khoá', function () {
    $key = '18/'.ADAPTER_ULID.'.pdf';
    $row = $this->drive->seed($key);
    unset($this->drive->files[$row->file_id]);

    try {
        $this->drive->disk()->readStream($key);
        $this->fail('Phải ném UnableToReadFile.');
    } catch (UnableToReadFile $e) {
        expect($e->getPrevious())->toBeInstanceOf(StoredFileMissing::class)
            ->and($e->getPrevious()->key)->toBe($key);
    }

    Http::assertSentCount(1);
});

it('lỗi tạm thời khi đọc → DocumentStorageUnavailable', function () {
    $key = '18/'.ADAPTER_ULID.'.pdf';
    $this->drive->seed($key);
    $this->drive->failNext('GET', 'alt=media', 503, times: 10);

    expect(fn () => $this->drive->adapter(DriveCircuitBreaker::WEB)->readStream($key))
        ->toThrow(DocumentStorageUnavailable::class);
});

// -------------------------------------------------------------------------------------------------
// Trả lời từ chỉ mục, không gọi mạng
// -------------------------------------------------------------------------------------------------

it('fileExists, fileSize, mimeType, lastModified, listContents, directoryExists không gửi request nào', function () {
    $key = '18/'.ADAPTER_ULID.'.pdf';
    $this->drive->seed($key, 'noi dung', mime: 'application/pdf');
    $disk = $this->drive->disk();

    expect($disk->exists($key))->toBeTrue()
        ->and($disk->fileExists($key))->toBeTrue()
        ->and($disk->missing('18/'.ADAPTER_ULID_2.'.pdf'))->toBeTrue()
        ->and($disk->size($key))->toBe(8)
        ->and($disk->mimeType($key))->toBe('application/pdf')
        ->and($disk->lastModified($key))->toBe(now()->getTimestamp())
        ->and($disk->allFiles('18'))->toBe([$key])
        ->and($disk->files('18'))->toBe([$key])
        ->and($disk->directoryExists('18'))->toBeTrue()
        ->and($disk->directoryExists('19'))->toBeFalse()
        ->and($disk->getVisibility($key))->toBe('private');

    $disk->setVisibility($key, 'public');
    $disk->makeDirectory('19');

    expect($disk->directoryExists('19'))->toBeFalse()
        ->and($disk->getVisibility($key))->toBe('private');

    Http::assertNothingSent();
});

// -------------------------------------------------------------------------------------------------
// Xoá = thùng rác
// -------------------------------------------------------------------------------------------------

it('delete gửi files.update trashed=true, không DELETE; dòng chỉ mục rời chỉ mục sống', function () {
    $key = '18/'.ADAPTER_ULID.'.pdf';
    $row = $this->drive->seed($key);

    $this->drive->disk()->delete($key);

    expect(trashedFileIds())->toBe([$row->file_id])
        ->and($this->drive->files[$row->file_id]['trashed'])->toBeTrue();
    Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE');

    $row->refresh();

    expect($row->object_key)->toBeNull()
        ->and($row->former_key)->toBe($key)
        ->and($row->retired_reason)->toBe(DriveObjectRetirement::Trashed)
        ->and($row->retired_at?->getTimestamp())->toBe(now()->getTimestamp())
        ->and($this->drive->disk()->exists($key))->toBeFalse();
});

it('delete khoá không có → không làm gì, không request nào', function () {
    $this->drive->disk()->delete('18/'.ADAPTER_ULID.'.pdf');

    Http::assertNothingSent();
});

it('delete mà Drive đã không còn tệp (404) → vẫn rời chỉ mục sống', function () {
    $key = '18/'.ADAPTER_ULID.'.pdf';
    $row = $this->drive->seed($key);
    unset($this->drive->files[$row->file_id]);

    $this->drive->disk()->delete($key);

    expect($row->fresh()->object_key)->toBeNull()
        ->and($row->fresh()->retired_reason)->toBe(DriveObjectRetirement::Trashed);
});

it('delete gặp kho sập → ném lỗi, dòng chỉ mục vẫn sống', function () {
    $key = '18/'.ADAPTER_ULID.'.pdf';
    $row = $this->drive->seed($key);
    $this->drive->failNext('PATCH', '/files/', 503, times: 10);

    expect(fn () => $this->drive->disk()->delete($key))->toThrow(DocumentStorageUnavailable::class);
    expect($row->fresh()->object_key)->toBe($key);
});

// -------------------------------------------------------------------------------------------------
// Thư mục là tiền tố có `/` (R4)
// -------------------------------------------------------------------------------------------------

/** @return array<string, DriveObject> media 18 (bản chính + một bản chuyển đổi), 180, 1800 */
function seedPrefixNeighbours(FakeGoogleDrive $drive): array
{
    return [
        '18' => $drive->seed('18/'.ADAPTER_ULID.'.pdf'),
        '18c' => $drive->seed('18/conversions/x.jpg', 'anh', mime: 'image/jpeg'),
        '180' => $drive->seed('180/'.ADAPTER_ULID.'.pdf'),
        '1800' => $drive->seed('1800/'.ADAPTER_ULID.'.pdf'),
    ];
}

it('deleteDirectory(18/) chỉ cho vào thùng rác tệp của 18, không đụng 180 và 1800', function (string $directory) {
    $rows = seedPrefixNeighbours($this->drive);
    $disk = $this->drive->disk();

    $disk->deleteDirectory($directory);

    expect(trashedFileIds())->toEqualCanonicalizing([$rows['18']->file_id, $rows['18c']->file_id])
        ->and($disk->fileExists('180/'.ADAPTER_ULID.'.pdf'))->toBeTrue()
        ->and($disk->fileExists('1800/'.ADAPTER_ULID.'.pdf'))->toBeTrue()
        ->and($disk->allFiles('18'))->toBe([]);
})->with([
    'có / cuối (DefaultFileRemover)' => ['18/'],
    'không / cuối (BuildHandoverPackage::discardStoredFile)' => ['18'],
]);

it('deleteDirectory(18/conversions/) gửi đúng một files.update trashed=true', function () {
    $rows = seedPrefixNeighbours($this->drive);

    $this->drive->disk()->deleteDirectory('18/conversions/');

    expect(trashedFileIds())->toBe([$rows['18c']->file_id]);
});

it('allFiles(18/) chỉ trả khoá của 18; files nông và directories đúng tầng', function () {
    seedPrefixNeighbours($this->drive);
    $disk = $this->drive->disk();

    expect($disk->allFiles('18/'))->toBe(['18/'.ADAPTER_ULID.'.pdf', '18/conversions/x.jpg'])
        ->and($disk->files('18/'))->toBe(['18/'.ADAPTER_ULID.'.pdf'])
        ->and($disk->directories('18/'))->toBe(['18/conversions'])
        ->and($disk->allDirectories('18'))->toBe(['18/conversions'])
        ->and($disk->directories(''))->toBe(['18', '180', '1800'])
        ->and(count($disk->allFiles()))->toBe(4);

    Http::assertNothingSent();
});

it('xoá media 18 qua thư viện media (đúng chuỗi DefaultFileRemover) chỉ cho đúng tệp của 18 vào thùng rác', function () {
    $rows = seedPrefixNeighbours($this->drive);
    $this->drive->disk();

    foreach (['18', '180', '1800'] as $id) {
        DB::table('media')->insert([
            'id' => (int) $id,
            'model_type' => 'document',
            'model_id' => 1,
            'uuid' => (string) Str::uuid(),
            'collection_name' => 'file',
            'name' => 'ho-so',
            'file_name' => ADAPTER_ULID.'.pdf',
            'mime_type' => 'application/pdf',
            'disk' => 'documents_remote',
            'conversions_disk' => 'documents_remote',
            'size' => 8,
            'manipulations' => '[]',
            'custom_properties' => '[]',
            'generated_conversions' => '[]',
            'responsive_images' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    Media::query()->findOrFail(18)->delete();

    expect(trashedFileIds())->toBe([$rows['18']->file_id])
        ->and($this->drive->disk()->fileExists('180/'.ADAPTER_ULID.'.pdf'))->toBeTrue()
        ->and($this->drive->disk()->fileExists('1800/'.ADAPTER_ULID.'.pdf'))->toBeTrue()
        ->and($this->drive->disk()->fileExists('18/conversions/x.jpg'))->toBeTrue();
});

it('% và _ trong tiền tố không khớp nhầm', function () {
    $this->drive->seed('a%/x.txt');
    $this->drive->seed('ab/y.txt');
    $this->drive->seed('a_/z.txt');
    $this->drive->seed('a!/w.txt');
    $disk = $this->drive->disk();

    expect($disk->allFiles('a%'))->toBe(['a%/x.txt'])
        ->and($disk->allFiles('a_'))->toBe(['a_/z.txt'])
        ->and($disk->allFiles('a!'))->toBe(['a!/w.txt'])
        ->and($disk->directoryExists('a%'))->toBeTrue()
        ->and($disk->directoryExists('a'))->toBeFalse();

    $disk->deleteDirectory('a%');

    expect($disk->allFiles('ab'))->toBe(['ab/y.txt'])
        ->and($disk->allFiles('a_'))->toBe(['a_/z.txt']);
});

it('gọi thẳng adapter (không qua Flysystem chuẩn hoá đường): d và d/ như nhau', function () {
    $rows = seedPrefixNeighbours($this->drive);
    $adapter = $this->drive->adapter();

    $listed = array_map(
        fn (StorageAttributes $item) => ($item instanceof DirectoryAttributes ? 'd:' : 'f:').$item->path(),
        iterator_to_array($adapter->listContents('18/', true), false),
    );

    expect($listed)->toBe(['f:18/'.ADAPTER_ULID.'.pdf', 'd:18/conversions', 'f:18/conversions/x.jpg'])
        ->and($adapter->directoryExists('18/'))->toBeTrue();

    $adapter->deleteDirectory('18/');

    expect(trashedFileIds())->toEqualCanonicalizing([$rows['18']->file_id, $rows['18c']->file_id]);
});

it('mimeType của dòng không ghi loại → UnableToRetrieveMetadata, không đoán, không gọi mạng', function () {
    $key = '18/'.ADAPTER_ULID.'.bin';
    $this->drive->seed($key, mime: null);

    // Gọi thẳng adapter: Flysystem cũng tự kiểm loại rỗng, nên qua đĩa thì điều kiện của adapter bị che.
    expect(fn () => $this->drive->adapter()->mimeType($key))->toThrow(UnableToRetrieveMetadata::class);
    expect(fn () => $this->drive->disk()->mimeType($key))->toThrow(UnableToRetrieveMetadata::class);
    expect(fn () => $this->drive->disk()->size('19/'.ADAPTER_ULID.'.bin'))->toThrow(UnableToRetrieveMetadata::class);

    Http::assertNothingSent();
});

it('listContents trả FileAttributes đủ cỡ, loại và thời điểm từ chỉ mục', function () {
    $key = '18/'.ADAPTER_ULID.'.pdf';
    $this->drive->seed($key, 'noi dung');

    $file = iterator_to_array($this->drive->adapter()->listContents('18', false), false)[0];

    expect($file)->toBeInstanceOf(FileAttributes::class)
        ->and($file->path())->toBe($key)
        ->and($file->fileSize())->toBe(8)
        ->and($file->mimeType())->toBe('application/pdf')
        ->and($file->lastModified())->toBe(now()->getTimestamp());
});

it('deleteDirectory gốc bị từ chối, không request nào', function () {
    seedPrefixNeighbours($this->drive);

    expect(fn () => $this->drive->adapter()->deleteDirectory(''))->toThrow(UnableToDeleteDirectory::class);
    expect(fn () => $this->drive->disk()->deleteDirectory('/'))->toThrow(UnableToDeleteDirectory::class);

    Http::assertNothingSent();
    expect(DriveObject::query()->whereNotNull('object_key')->count())->toBe(4);
});

it('thư mục gốc luôn tồn tại', function () {
    expect($this->drive->disk()->directoryExists(''))->toBeTrue();
});

/*
 * Trên MariaDB các cột khoá dùng collation nhị phân (`utf8mb4_bin`): hai khoá chỉ khác hoa thường là
 * hai khoá, và `LIKE '<d>/%'` so đúng từng byte. SQLite so `LIKE` không phân biệt hoa thường với chữ
 * ASCII, nên luật này chỉ đo được trên `test:mariadb` (rà soát Task 1, m4). Khoá của thư viện media
 * vốn viết thường (`StoresDocumentFile::storedFileName()`), nên khác biệt đó không chạm dữ liệu thật.
 */
it('MariaDB: hai khoá chỉ khác hoa thường cùng chèn được, và tiền tố so đúng từng byte', function () {
    $this->drive->seed('ab/x.txt');
    $this->drive->seed('AB/x.txt');
    $disk = $this->drive->disk();

    expect(DriveObject::query()->whereNotNull('object_key')->count())->toBe(2)
        ->and($disk->allFiles('ab'))->toBe(['ab/x.txt'])
        ->and($disk->allFiles('AB'))->toBe(['AB/x.txt']);
})->skip(fn () => DB::connection()->getDriverName() === 'sqlite', 'SQLite LIKE không phân biệt hoa thường ASCII; luật đo trên test:mariadb.');

// -------------------------------------------------------------------------------------------------
// Đổi tên, chép
// -------------------------------------------------------------------------------------------------

it('move: đổi tên trên Drive giữ thế hệ, chỉ mục trỏ khoá mới', function () {
    $source = '18/'.ADAPTER_ULID.'.pdf';
    $destination = '18/'.ADAPTER_ULID_2.'.pdf';
    $row = $this->drive->seed($source, 'noi dung', 2);
    $disk = $this->drive->disk();

    $disk->move($source, $destination);

    expect($this->drive->files[$row->file_id]['name'])->toBe('18~'.ADAPTER_ULID_2.'~g2.pdf')
        ->and($row->fresh()->object_key)->toBe($destination)
        ->and($row->fresh()->generation)->toBe(2)
        ->and($disk->exists($source))->toBeFalse()
        ->and($disk->get($destination))->toBe('noi dung');
});

it('move: khoá đích đã từng có thế hệ đó → lên thế hệ kế tiếp của khoá đích, không dùng lại tên cũ', function () {
    $source = '18/'.ADAPTER_ULID.'.pdf';
    $destination = '18/'.ADAPTER_ULID_2.'.pdf';
    $disk = $this->drive->disk();
    $this->drive->seed($destination, 'cu');
    $disk->delete($destination);
    $row = $this->drive->seed($source, 'moi');

    $disk->move($source, $destination);

    expect($this->drive->files[$row->file_id]['name'])->toBe('18~'.ADAPTER_ULID_2.'~g2.pdf')
        ->and($row->fresh()->generation)->toBe(2);
});

it('move vào khoá đích đã có, hay từ khoá không có → UnableToMoveFile, không request nào', function () {
    $this->drive->seed('18/'.ADAPTER_ULID.'.pdf');
    $this->drive->seed('18/'.ADAPTER_ULID_2.'.pdf');
    $disk = $this->drive->disk();

    expect(fn () => $disk->move('18/'.ADAPTER_ULID.'.pdf', '18/'.ADAPTER_ULID_2.'.pdf'))->toThrow(UnableToMoveFile::class)
        ->and(fn () => $disk->move('19/'.ADAPTER_ULID.'.pdf', '19/'.ADAPTER_ULID_2.'.pdf'))->toThrow(UnableToMoveFile::class);

    Http::assertNothingSent();
});

it('copy: files.copy vào thư mục tháng, dòng chỉ mục mới với thế hệ của khoá đích', function () {
    $source = '18/'.ADAPTER_ULID.'.pdf';
    $destination = '19/'.ADAPTER_ULID_2.'.pdf';
    $this->drive->seed($source, 'noi dung');
    $disk = $this->drive->disk();

    $disk->copy($source, $destination);

    $copy = DriveObject::query()->where('object_key', $destination)->sole();

    expect($this->drive->files[$copy->file_id]['name'])->toBe('19~'.ADAPTER_ULID_2.'.pdf')
        ->and($copy->generation)->toBe(1)
        ->and($copy->md5)->toBe(md5('noi dung'))
        ->and($copy->parent_id)->toBe(DriveFolder::query()->sole()->folder_id)
        ->and($disk->get($source))->toBe('noi dung')
        ->and($disk->get($destination))->toBe('noi dung');
});

it('copy mà md5 hay cỡ của bản chép lệch bản gốc → bản chép vào thùng rác, UnableToCopyFile', function (string $override) {
    $this->drive->seed('18/'.ADAPTER_ULID.'.pdf', 'noi dung');

    if ($override === 'md5') {
        $this->drive->md5Overrides['19~'.ADAPTER_ULID_2.'.pdf'] = str_repeat('0', 32);
    } else {
        $this->drive->sizeOverrides['19~'.ADAPTER_ULID_2.'.pdf'] = 9;
    }

    expect(fn () => $this->drive->disk()->copy('18/'.ADAPTER_ULID.'.pdf', '19/'.ADAPTER_ULID_2.'.pdf'))
        ->toThrow(UnableToCopyFile::class);

    expect($this->drive->named('19~'.ADAPTER_ULID_2.'.pdf')[0]['trashed'])->toBeTrue()
        ->and(DriveObject::query()->where('object_key', '19/'.ADAPTER_ULID_2.'.pdf')->exists())->toBeFalse();
})->with(['md5', 'cỡ']);

it('copy vào khoá đã có → UnableToCopyFile, không request nào', function () {
    $this->drive->seed('18/'.ADAPTER_ULID.'.pdf');
    $this->drive->seed('19/'.ADAPTER_ULID_2.'.pdf');

    expect(fn () => $this->drive->disk()->copy('18/'.ADAPTER_ULID.'.pdf', '19/'.ADAPTER_ULID_2.'.pdf'))
        ->toThrow(UnableToCopyFile::class);

    Http::assertNothingSent();
});

// -------------------------------------------------------------------------------------------------
// Checksum hỏi Google
// -------------------------------------------------------------------------------------------------

it('checksum md5 hỏi Google (md5Checksum), không đọc chỉ mục', function () {
    $key = '18/'.ADAPTER_ULID.'.pdf';
    $row = $this->drive->seed($key, 'noi dung');
    $this->drive->md5Overrides['18~'.ADAPTER_ULID.'.pdf'] = 'ffffffffffffffffffffffffffffffff';

    expect($this->drive->disk()->checksum($key))->toBe('ffffffffffffffffffffffffffffffff')
        ->and($row->md5)->toBe(md5('noi dung'));

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'GET' && ! str_contains($request->url(), 'alt=media'));
});

it('checksum thuật toán khác md5 → UnableToProvideChecksum, không tải tệp về để tự tính', function () {
    $key = '18/'.ADAPTER_ULID.'.pdf';
    $this->drive->seed($key);

    expect(fn () => $this->drive->disk()->checksum($key, ['checksum_algo' => 'sha256']))
        ->toThrow(UnableToProvideChecksum::class);

    Http::assertNothingSent();
});

it('checksum khoá không có trong chỉ mục, hay tệp đã ở thùng rác → UnableToProvideChecksum', function () {
    $key = '18/'.ADAPTER_ULID.'.pdf';
    $row = $this->drive->seed($key);

    expect(fn () => $this->drive->disk()->checksum('19/'.ADAPTER_ULID.'.pdf'))->toThrow(UnableToProvideChecksum::class);
    Http::assertNothingSent();

    $this->drive->files[$row->file_id]['trashed'] = true;

    expect(fn () => $this->drive->disk()->checksum($key))->toThrow(UnableToProvideChecksum::class);
});

it('adapter là DriveAdapter khi đĩa documents_remote đủ cấu hình', function () {
    expect($this->drive->disk()->getAdapter())->toBeInstanceOf(DriveAdapter::class);
});
