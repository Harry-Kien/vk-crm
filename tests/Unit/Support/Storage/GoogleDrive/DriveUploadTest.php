<?php

use App\Exceptions\DocumentStorageMisconfigured;
use App\Exceptions\DocumentStorageUnavailable;
use App\Support\Storage\GoogleDrive\DriveApiError;
use App\Support\Storage\GoogleDrive\DriveCircuitBreaker;
use App\Support\Storage\GoogleDrive\DriveClient;
use App\Support\Storage\GoogleDrive\ServiceAccountTokenProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeGoogleDrive;

/*
|--------------------------------------------------------------------------
| M14 Task 2 — tải lên resumable (kế hoạch M14, R9)
|--------------------------------------------------------------------------
|
| Một đường mã cho mọi kích thước: `POST …?uploadType=resumable` lấy URI phiên, rồi `PUT` từng khối
| với `Content-Range`, nhận 308 kèm `Range`, đi tiếp từ byte Google đã nhận. Lỗi giữa chừng thì hỏi
| trạng thái phiên (`bytes * /<tổng>`). md5 tính dần trong lúc đọc, so với `md5Checksum` của Google;
| lệch thì cho tệp vào thùng rác rồi ném lỗi. Khối không cuối luôn là bội của 256 KiB.
*/

const UPLOAD_UNIT = 262144;

beforeEach(function () {
    $this->drive = FakeGoogleDrive::install();
    $this->folder = $this->drive->putFile('2026-10', '', [FakeGoogleDrive::ROOT_FOLDER_ID], 'application/vnd.google-apps.folder');
});

/**
 * PHPUnit giữ mọi đối tượng test tới hết lượt chạy, kể cả thuộc tính gán trên `$this`. Drive giả giữ
 * nội dung mọi tệp đã tải lên, và các ca khối 8 MiB tải hơn 8 MiB mỗi ca: không gỡ thì một tiến trình
 * của `--parallel` mang thêm khoảng 50 MB tới cuối lượt, cộng phần các tệp test trước để lại là chạm
 * trần 512 MB (Task 8, cả bộ sau khi gộp `main` 7632242: WorkerCrashedException ở đúng tệp này).
 */
afterEach(function () {
    unset($this->drive);
});

/** @return resource */
function uploadStream(string $content)
{
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, $content);
    rewind($stream);

    return $stream;
}

/** @return list<string> Content-Range của mọi PUT đã gửi, theo thứ tự */
function sentContentRanges(): array
{
    return Http::recorded()
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $request) => $request->method() === 'PUT')
        ->map(fn (Request $request) => $request->header('Content-Range')[0] ?? '')
        ->values()
        ->all();
}

/** @return list<int> độ dài thân của mọi PUT đã gửi */
function sentChunkLengths(): array
{
    return Http::recorded()
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $request) => $request->method() === 'PUT')
        ->map(fn (Request $request) => strlen($request->body()))
        ->values()
        ->all();
}

/**
 * `vkcrm.storage.google_drive.chunk_mb` nạp lại từ CHÍNH `config/vkcrm.php` với
 * `GOOGLE_DRIVE_CHUNK_MB` đã đặt cho tiến trình (khuôn `StorageConfigTest`), rồi trả mọi thứ về.
 */
function chunkMbUnderEnv(string $value): int
{
    $saved = [getenv('GOOGLE_DRIVE_CHUNK_MB'), $_ENV['GOOGLE_DRIVE_CHUNK_MB'] ?? null, $_SERVER['GOOGLE_DRIVE_CHUNK_MB'] ?? null];

    putenv('GOOGLE_DRIVE_CHUNK_MB='.$value);
    $_ENV['GOOGLE_DRIVE_CHUNK_MB'] = $_SERVER['GOOGLE_DRIVE_CHUNK_MB'] = $value;
    Env::enablePutenv();

    try {
        return (require config_path('vkcrm.php'))['storage']['google_drive']['chunk_mb'];
    } finally {
        $saved[0] === false ? putenv('GOOGLE_DRIVE_CHUNK_MB') : putenv('GOOGLE_DRIVE_CHUNK_MB='.$saved[0]);

        if ($saved[1] === null) {
            unset($_ENV['GOOGLE_DRIVE_CHUNK_MB']);
        } else {
            $_ENV['GOOGLE_DRIVE_CHUNK_MB'] = $saved[1];
        }

        if ($saved[2] === null) {
            unset($_SERVER['GOOGLE_DRIVE_CHUNK_MB']);
        } else {
            $_SERVER['GOOGLE_DRIVE_CHUNK_MB'] = $saved[2];
        }

        Env::enablePutenv();
    }
}

it('mở phiên resumable: POST uploadType=resumable, khai cỡ và loại, thân chỉ có tên, cha và loại', function () {
    $this->drive->client()->upload($this->folder, '18~a.pdf', 'application/pdf', uploadStream('noi dung'), 8);

    Http::assertSent(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'POST'
            && str_starts_with($request->url(), 'https://www.googleapis.com/upload/drive/v3/files?')
            && $query['uploadType'] === 'resumable'
            && $request->header('X-Upload-Content-Length')[0] === '8'
            && $request->header('X-Upload-Content-Type')[0] === 'application/pdf'
            && json_decode($request->body(), true) === [
                'name' => '18~a.pdf',
                'parents' => [$this->folder],
                'mimeType' => 'application/pdf',
            ];
    });
});

it('tải ba khối, Content-Range đúng từng byte, trả id, cỡ và md5 của Google', function () {
    $content = random_bytes(2 * UPLOAD_UNIT + 1000);
    $total = strlen($content);

    $result = $this->drive->client(chunkBytes: UPLOAD_UNIT)->upload($this->folder, '18~a.pdf', 'application/pdf', uploadStream($content), $total);

    expect(sentContentRanges())->toBe([
        'bytes 0-262143/'.$total,
        'bytes 262144-524287/'.$total,
        'bytes 524288-'.($total - 1).'/'.$total,
    ])
        ->and($result['md5Checksum'])->toBe(md5($content))
        ->and((int) $result['size'])->toBe($total)
        ->and($this->drive->files[$result['id']]['content'])->toBe($content)
        ->and($this->drive->files[$result['id']]['parents'])->toBe([$this->folder]);
});

it('308, rồi lỗi kết nối giữa khối, rồi hỏi trạng thái và tải tiếp đúng từ byte Google đã nhận', function () {
    $content = random_bytes(4 * UPLOAD_UNIT + 100);
    $total = strlen($content);

    // Khối một đi qua (308); khối hai đứt sau khi Google đã nhận 256 KiB đầu của nó.
    $this->drive->failNext('PUT', 'upload_id=', 0, keepBytes: UPLOAD_UNIT, after: 1);

    $result = $this->drive->client(chunkBytes: 2 * UPLOAD_UNIT)
        ->upload($this->folder, '18~a.pdf', 'application/pdf', uploadStream($content), $total);

    // Sau khi Google xác nhận tới byte 786431, phần còn lại của khối hai (786432–1048575) đi lại từ
    // bộ nhớ, nối với phần đọc tiếp của luồng thành một khối (ở đây là khối cuối).
    expect(sentContentRanges())->toBe([
        'bytes 0-524287/'.$total,
        'bytes 524288-1048575/'.$total,
        'bytes */'.$total,
        'bytes 786432-'.($total - 1).'/'.$total,
    ])
        ->and($this->drive->files[$result['id']]['content'])->toBe($content)
        ->and($result['md5Checksum'])->toBe(md5($content));
});

it('md5 lệch → files.update trashed=true cho tệp vừa tải, rồi ném lỗi', function () {
    $this->drive->md5Overrides['18~a.pdf'] = str_repeat('0', 32);

    try {
        $this->drive->client()->upload($this->folder, '18~a.pdf', 'application/pdf', uploadStream('noi dung'), 8);
        $this->fail('Phải ném lỗi khi md5 lệch.');
    } catch (DriveApiError $error) {
        expect($error->reason)->toBe(DriveApiError::CHECKSUM_MISMATCH);
    }

    $uploaded = $this->drive->named('18~a.pdf');

    expect($uploaded)->toHaveCount(1)
        ->and($uploaded[0]['trashed'])->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && str_contains($request->url(), '/files/'.$uploaded[0]['id'])
        && json_decode($request->body(), true) === ['trashed' => true]);
});

it('md5 lệch mà cho vào thùng rác cũng hỏng: vẫn ném lỗi lệch md5', function () {
    $this->drive->md5Overrides['18~a.pdf'] = str_repeat('0', 32);
    $this->drive->failNext('PATCH', '/files/', 403, 'insufficientFilePermissions');

    expect(fn () => $this->drive->client()->upload($this->folder, '18~a.pdf', 'application/pdf', uploadStream('noi dung'), 8))
        ->toThrow(DriveApiError::class);
});

it('kích thước Google báo lệch bản gửi đi → thùng rác, rồi ném lỗi', function () {
    $this->drive->sizeOverrides['18~a.pdf'] = 9;

    expect(fn () => $this->drive->client()->upload($this->folder, '18~a.pdf', 'application/pdf', uploadStream('noi dung'), 8))
        ->toThrow(DriveApiError::class);

    expect($this->drive->named('18~a.pdf')[0]['trashed'])->toBeTrue();
});

it('Google xác nhận nhiều byte hơn đã gửi → DocumentStorageUnavailable, không đi tiếp', function () {
    $this->drive->respondNext('PUT', 'upload_id=', Http::response('', 308, ['Range' => 'bytes=0-999999']));

    expect(fn () => $this->drive->client(chunkBytes: UPLOAD_UNIT)->upload($this->folder, '18~a.pdf', 'application/pdf', uploadStream(str_repeat('x', 2 * UPLOAD_UNIT)), 2 * UPLOAD_UNIT))
        ->toThrow(DocumentStorageUnavailable::class);

    expect(sentContentRanges())->toHaveCount(1);
});

it('lỗi khối có tiến triển thì đếm lại: ba lỗi ở khối một, ba lỗi ở khối hai vẫn tải xong trong job', function () {
    $content = random_bytes(UPLOAD_UNIT + 10);
    // Khối một: hỏng, hỏi trạng thái hỏng hai lần (3 lỗi), hỏi trạng thái được (chưa nhận gì), gửi lại
    // được (tiến triển). Khối hai: hỏng, hỏi trạng thái hỏng hai lần (3 lỗi), rồi xong.
    $this->drive->failNext('PUT', 'upload_id=', 503, times: 3);
    $this->drive->failNext('PUT', 'upload_id=', 503, times: 3, after: 2);

    $result = $this->drive->client(DriveCircuitBreaker::JOB, chunkBytes: UPLOAD_UNIT)
        ->upload($this->folder, '18~a.pdf', 'application/pdf', uploadStream($content), strlen($content));

    expect($this->drive->files[$result['id']]['content'])->toBe($content);
});

it('401 giữa phiên tải lên → lấy token mới đúng một lần, hỏi trạng thái, tải tiếp', function () {
    $tokens = new ServiceAccountTokenProvider(FakeGoogleDrive::serviceAccountKeyFile(), Cache::store('array'));
    $content = random_bytes(2 * UPLOAD_UNIT);
    $this->drive->failNext('PUT', 'upload_id=', 401, 'authError', after: 1);

    $result = $this->drive->client(tokens: $tokens, chunkBytes: UPLOAD_UNIT)
        ->upload($this->folder, '18~a.pdf', 'application/pdf', uploadStream($content), strlen($content));

    expect($this->drive->files[$result['id']]['content'])->toBe($content)
        ->and($this->drive->assertions)->toHaveCount(2)
        ->and(sentContentRanges())->toBe([
            'bytes 0-262143/524288',
            'bytes 262144-524287/524288',
            'bytes */524288',
            'bytes 262144-524287/524288',
        ]);
});

it('401 lặp lại giữa phiên tải lên → DocumentStorageMisconfigured sau đúng một lần làm mới token', function () {
    $tokens = new ServiceAccountTokenProvider(FakeGoogleDrive::serviceAccountKeyFile(), Cache::store('array'));
    $this->drive->failNext('PUT', 'upload_id=', 401, 'authError', times: 5);

    expect(fn () => $this->drive->client(tokens: $tokens)->upload($this->folder, '18~a.pdf', 'application/pdf', uploadStream('noi dung'), 8))
        ->toThrow(DocumentStorageMisconfigured::class, __('storage.exceptions.credentials_rejected'));

    expect($this->drive->assertions)->toHaveCount(2)
        ->and(sentContentRanges())->toBe(['bytes 0-7/8', 'bytes */8']);
});

it('Google báo xong mà không trả mã tệp → lỗi, không cho gì vào thùng rác', function () {
    $this->drive->respondNext('PUT', 'upload_id=', Http::response(['size' => '8', 'md5Checksum' => md5('noi dung')]));

    expect(fn () => $this->drive->client()->upload($this->folder, '18~a.pdf', 'application/pdf', uploadStream('noi dung'), 8))
        ->toThrow(DriveApiError::class);

    Http::assertNotSent(fn (Request $request) => $request->method() === 'PATCH');
});

it('URI phiên không ở www.googleapis.com/upload → DocumentStorageUnavailable, không PUT nào', function () {
    $this->drive->respondNext('POST', 'uploadType=resumable', Http::response('', 200, ['Location' => 'https://upload.example.test/phien?upload_id=1']));

    expect(fn () => $this->drive->client()->upload($this->folder, '18~a.pdf', 'application/pdf', uploadStream('noi dung'), 8))
        ->toThrow(DocumentStorageUnavailable::class);

    expect(sentContentRanges())->toBe([]);
});

it('mở phiên tải lên hỏng ba lần không mở mạch', function () {
    $this->drive->failNext('POST', 'uploadType=resumable', 503, times: 12);

    foreach (range(1, 3) as $ignored) {
        expect(fn () => $this->drive->client(DriveCircuitBreaker::JOB)->upload($this->folder, '18~a.pdf', 'application/pdf', uploadStream('x'), 1))
            ->toThrow(DocumentStorageUnavailable::class);
    }

    $before = count(Http::recorded());
    $id = $this->drive->putFile('18~b.pdf', 'x', [FakeGoogleDrive::ROOT_FOLDER_ID]);

    expect($this->drive->client(DriveCircuitBreaker::JOB)->metadata($id)['id'])->toBe($id)
        ->and(count(Http::recorded()))->toBe($before + 1);
});

it('luồng dài hơn cỡ đã khai → lỗi, không hoàn tất phiên', function () {
    expect(fn () => $this->drive->client()->upload($this->folder, '18~a.pdf', 'application/pdf', uploadStream('dai hon'), 3))
        ->toThrow(RuntimeException::class);

    expect($this->drive->named('18~a.pdf'))->toBe([]);
});

it('tệp 0 byte: một PUT rỗng bytes */0, không hỏng', function () {
    $result = $this->drive->client()->upload($this->folder, '18~rong.txt', 'text/plain', uploadStream(''), 0);

    expect(sentContentRanges())->toBe(['bytes */0'])
        ->and(sentChunkLengths())->toBe([0])
        ->and($result['md5Checksum'])->toBe(md5(''))
        ->and($this->drive->files[$result['id']]['content'])->toBe('');
});

it('luồng ngắn hơn cỡ đã khai → lỗi, không hoàn tất phiên', function () {
    expect(fn () => $this->drive->client()->upload($this->folder, '18~a.pdf', 'application/pdf', uploadStream('ngan'), 100))
        ->toThrow(RuntimeException::class);

    expect($this->drive->named('18~a.pdf'))->toBe([]);
});

it('lỗi khối lặp lại hết lượt thử → DocumentStorageUnavailable', function () {
    $this->drive->failNext('PUT', 'upload_id=', 503, times: 20);

    expect(fn () => $this->drive->client(DriveCircuitBreaker::JOB)->upload($this->folder, '18~a.pdf', 'application/pdf', uploadStream('noi dung'), 8))
        ->toThrow(DocumentStorageUnavailable::class);

    expect(sentContentRanges())->toHaveCount(4);
});

it('phiên tải lên đã hết hạn (404/410) → DocumentStorageUnavailable, lượt sau mở phiên mới', function (int $status) {
    $this->drive->failNext('PUT', 'upload_id=', $status, 'notFound');

    expect(fn () => $this->drive->client()->upload($this->folder, '18~a.pdf', 'application/pdf', uploadStream('noi dung'), 8))
        ->toThrow(DocumentStorageUnavailable::class);
})->with([404, 410]);

it('GOOGLE_DRIVE_CHUNK_MB trống, 0, âm hay lớn hơn 64 → khối 8 MiB; mọi khối không cuối là bội của 256 KiB', function (string $value) {
    config(['vkcrm.storage.google_drive.chunk_mb' => chunkMbUnderEnv($value)]);

    $content = str_repeat('a', 8 * 1048576 + 1024);
    $client = DriveClient::fromConfig(FakeGoogleDrive::tokenProvider(), new DriveCircuitBreaker(Cache::store('array'), DriveCircuitBreaker::JOB));

    $client->upload($this->folder, '18~a.pdf', 'application/pdf', uploadStream($content), strlen($content));

    $lengths = sentChunkLengths();

    expect($lengths)->toBe([8388608, 1024]);

    foreach (array_slice($lengths, 0, -1) as $length) {
        expect($length % 262144)->toBe(0);
    }
})->with(['trống' => [''], '0' => ['0'], 'âm' => ['-3'], '65' => ['65'], 'không phải số' => ['abc']]);

it('GOOGLE_DRIVE_CHUNK_MB hợp lệ được dùng đúng cỡ', function () {
    config(['vkcrm.storage.google_drive.chunk_mb' => chunkMbUnderEnv('3')]);

    $content = str_repeat('b', 7 * 1048576);
    $client = DriveClient::fromConfig(FakeGoogleDrive::tokenProvider(), new DriveCircuitBreaker(Cache::store('array'), DriveCircuitBreaker::JOB));

    $client->upload($this->folder, '18~a.pdf', 'application/pdf', uploadStream($content), strlen($content));

    expect(sentChunkLengths())->toBe([3145728, 3145728, 1048576]);
});
