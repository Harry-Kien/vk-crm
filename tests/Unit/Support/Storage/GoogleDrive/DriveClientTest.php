<?php

use App\Exceptions\DocumentStorageMisconfigured;
use App\Exceptions\DocumentStorageUnavailable;
use App\Support\Storage\GoogleDrive\DriveApiError;
use App\Support\Storage\GoogleDrive\DriveCircuitBreaker;
use App\Support\Storage\GoogleDrive\DriveClient;
use App\Support\Storage\GoogleDrive\ServiceAccountTokenProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\FakeGoogleDrive;

/*
|--------------------------------------------------------------------------
| M14 Task 2 — DriveClient trên Drive REST v3 (kế hoạch M14, R1, R3, R5, R9)
|--------------------------------------------------------------------------
|
| Client THẬT trên máy chủ Drive giả (`Http::fake()` + `Http::preventStrayRequests()`): mọi request
| mang `supportsAllDrives=true`, `fields=` tường minh không bao giờ xin link hay quyền chia sẻ,
| không lệnh ghi nào tới `/permissions`; thời gian chờ, thử lại, phân loại lỗi và ngắt mạch theo R9.
*/

beforeEach(function () {
    $this->drive = FakeGoogleDrive::install();
});

/** Mọi request tới Drive (không tính endpoint token) đã ghi, dạng [Request, url đã tách]. */
function driveRequests(): array
{
    return Http::recorded()
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $request) => str_starts_with($request->url(), 'https://www.googleapis.com/'))
        ->values()
        ->all();
}

/** @return array<string, string> */
function queryOf(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return $query;
}

/** Gọi đủ mười phương thức công khai của client một lần. */
function exerciseEveryClientMethod(DriveClient $client, FakeGoogleDrive $drive): void
{
    $drive->permissionPages[] = ['permissions' => [['id' => 'p2', 'type' => 'user', 'role' => 'reader', 'emailAddress' => 'van-phong-kho@vk.test']]];

    $client->drive(FakeGoogleDrive::DRIVE_ID);
    $client->drivePermissions(FakeGoogleDrive::DRIVE_ID);
    $folder = $client->folder(FakeGoogleDrive::ROOT_FOLDER_ID, '2026-10');

    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, 'noi dung');
    rewind($stream);
    $file = $client->upload($folder, '18~a.pdf', 'application/pdf', $stream, 8);

    $client->metadata($file['id']);
    stream_get_contents($client->open($file['id']));
    $client->rename($file['id'], '18~b.pdf');
    $client->copy($file['id'], $folder, '18~c.pdf');
    $client->children($folder);
    $client->trash($file['id']);
}

it('mọi request tới Drive mang supportsAllDrives=true', function () {
    exerciseEveryClientMethod($this->drive->client(), $this->drive);

    $requests = driveRequests();

    expect($requests)->toHaveCount(12);

    foreach ($requests as $request) {
        expect(queryOf($request)['supportsAllDrives'] ?? null)->toBe('true', $request->method().' '.$request->url());
    }
});

it('không request nào có fields chứa Link; chỉ permissions.list nhắc tới permissions', function () {
    exerciseEveryClientMethod($this->drive->client(), $this->drive);

    $withFields = 0;

    foreach (driveRequests() as $request) {
        $fields = queryOf($request)['fields'] ?? null;

        if ($fields === null) {
            continue;
        }

        $withFields++;
        $isPermissionsList = $request->method() === 'GET'
            && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/files/'.FakeGoogleDrive::DRIVE_ID.'/permissions');

        expect($fields)->not->toContain('Link')
            ->and($fields)->not->toContain('exportLinks')
            ->and($fields)->not->toContain('*');

        if (! $isPermissionsList) {
            expect($fields)->not->toContain('permission');
        }
    }

    expect($withFields)->toBeGreaterThanOrEqual(10);
});

it('không request POST, PATCH, PUT hay DELETE nào tới /permissions', function () {
    exerciseEveryClientMethod($this->drive->client(), $this->drive);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/permissions')
        && $request->method() !== 'GET');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/permissions') && $request->method() === 'GET');
});

it('drive() đọc drives.get với restrictions và capabilities', function () {
    $drive = $this->drive->client()->drive(FakeGoogleDrive::DRIVE_ID);

    expect($drive['restrictions']['driveMembersOnly'])->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/drive/v3/drives/'.FakeGoogleDrive::DRIVE_ID)
        && str_contains(queryOf($request)['fields'], 'restrictions')
        && str_contains(queryOf($request)['fields'], 'capabilities'));
});

it('drivePermissions() đi hết mọi trang', function () {
    $this->drive->permissionPages[] = ['permissions' => [['id' => 'p2', 'type' => 'user', 'role' => 'reader', 'emailAddress' => 'van-phong-kho@vk.test']]];
    $this->drive->permissionPages[] = ['permissions' => [['id' => 'p3', 'type' => 'anyone', 'role' => 'reader']]];

    $permissions = $this->drive->client()->drivePermissions(FakeGoogleDrive::DRIVE_ID);

    expect(array_column($permissions, 'id'))->toBe(['p1', 'p2', 'p3']);
    Http::assertSentCount(3);
});

it('folder() tạo thư mục dưới cha đã cho, không tìm theo tên', function () {
    $id = $this->drive->client()->folder(FakeGoogleDrive::ROOT_FOLDER_ID, '2026-10');

    expect($this->drive->files[$id]['name'])->toBe('2026-10')
        ->and($this->drive->files[$id]['parents'])->toBe([FakeGoogleDrive::ROOT_FOLDER_ID])
        ->and($this->drive->files[$id]['mimeType'])->toBe('application/vnd.google-apps.folder');

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request) => isset(queryOf($request)['q']));
});

it('metadata() trả id, size, md5, sha256, trashed, parents, driveId, mimeType', function () {
    $id = $this->drive->putFile('18~a.pdf', 'noi dung', [FakeGoogleDrive::ROOT_FOLDER_ID], 'application/pdf');

    $metadata = $this->drive->client()->metadata($id);

    expect($metadata)->toMatchArray([
        'id' => $id,
        'size' => '8',
        'md5Checksum' => md5('noi dung'),
        'sha256Checksum' => hash('sha256', 'noi dung'),
        'trashed' => false,
        'driveId' => FakeGoogleDrive::DRIVE_ID,
        'mimeType' => 'application/pdf',
    ]);

    Http::assertSent(fn (Request $request) => explode(',', queryOf($request)['fields'])
        === ['id', 'name', 'size', 'md5Checksum', 'sha256Checksum', 'trashed', 'parents', 'driveId', 'mimeType']);
});

it('open() gửi một GET alt=media và trả luồng đọc được', function () {
    $id = $this->drive->putFile('18~a.pdf', 'noi dung tep', [FakeGoogleDrive::ROOT_FOLDER_ID]);

    $stream = $this->drive->client()->open($id);

    expect(is_resource($stream))->toBeTrue()
        ->and(stream_get_contents($stream))->toBe('noi dung tep');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'GET' && queryOf($request)['alt'] === 'media');
});

it('trash() gửi files.update trashed=true, không bao giờ DELETE', function () {
    $id = $this->drive->putFile('18~a.pdf', 'x', [FakeGoogleDrive::ROOT_FOLDER_ID]);

    $this->drive->client()->trash($id);

    expect($this->drive->files[$id]['trashed'])->toBeTrue();
    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && json_decode($request->body(), true) === ['trashed' => true]);
    Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE');
});

it('rename() và copy() đi qua files.update và files.copy', function () {
    $id = $this->drive->putFile('18~a.pdf', 'noi dung', [FakeGoogleDrive::ROOT_FOLDER_ID]);
    $client = $this->drive->client();

    $client->rename($id, '19~a.pdf');
    $copy = $client->copy($id, FakeGoogleDrive::ROOT_FOLDER_ID, '20~a.pdf');

    expect($this->drive->files[$id]['name'])->toBe('19~a.pdf')
        ->and($this->drive->files[$copy['id']]['name'])->toBe('20~a.pdf')
        ->and($copy['md5Checksum'])->toBe(md5('noi dung'))
        ->and($copy['size'])->toBe('8');
});

it('children() liệt kê trong đúng Shared Drive: corpora=drive, driveId, includeItemsFromAllDrives, bỏ tệp đã vào thùng rác', function () {
    $this->drive->putFile('18~a.pdf', 'x', [FakeGoogleDrive::ROOT_FOLDER_ID]);

    $page = $this->drive->client()->children(FakeGoogleDrive::ROOT_FOLDER_ID, 'trang-2');

    expect(array_column($page['files'], 'name'))->toBe(['18~a.pdf'])
        ->and($page)->toHaveKey('nextPageToken');

    Http::assertSent(function (Request $request) {
        $query = queryOf($request);

        return $request->method() === 'GET'
            && $query['corpora'] === 'drive'
            && $query['driveId'] === FakeGoogleDrive::DRIVE_ID
            && $query['includeItemsFromAllDrives'] === 'true'
            && $query['pageToken'] === 'trang-2'
            && $query['q'] === "'".FakeGoogleDrive::ROOT_FOLDER_ID."' in parents and trashed = false";
    });
});

it('thời gian chờ theo R9: kết nối 5 giây, metadata 30 giây, tải xuống không giới hạn tổng nhưng 60 giây giữa hai khối, khối tải lên 120 giây', function () {
    exerciseEveryClientMethod($this->drive->client(), $this->drive);

    $byKind = collect($this->drive->options)->filter(fn (array $sent) => str_starts_with($sent['url'], 'https://www.googleapis.com/'));

    foreach ($byKind as $sent) {
        expect($sent['options']['connect_timeout'])->toBe(5, $sent['url']);

        $query = [];
        parse_str((string) parse_url($sent['url'], PHP_URL_QUERY), $query);

        if (($query['alt'] ?? null) === 'media') {
            expect($sent['options']['timeout'])->toBe(0)
                ->and($sent['options']['read_timeout'])->toBe(60)
                ->and($sent['options']['stream'])->toBeTrue();
        } elseif ($sent['method'] === 'PUT') {
            expect($sent['options']['timeout'])->toBe(120);
        } else {
            expect($sent['options']['timeout'])->toBe(30, $sent['method'].' '.$sent['url']);
        }
    }

    expect($byKind->where('method', 'PUT'))->not->toBeEmpty();
});

it('lỗi tạm thời được thử lại rồi thành công', function (int $status, ?string $reason) {
    $id = $this->drive->putFile('18~a.pdf', 'x', [FakeGoogleDrive::ROOT_FOLDER_ID]);
    $this->drive->failNext('GET', '/files/'.$id, $status, $reason);

    expect($this->drive->client()->metadata($id)['id'])->toBe($id);

    Http::assertSentCount(2);
})->with([
    '429' => [429, 'rateLimitExceeded'],
    '500' => [500, 'backendError'],
    '502' => [502, null],
    '503' => [503, 'backendError'],
    '403 rateLimitExceeded' => [403, 'rateLimitExceeded'],
    '403 userRateLimitExceeded' => [403, 'userRateLimitExceeded'],
    'lỗi kết nối' => [0, null],
]);

it('lỗi không thử lại → DocumentStorageMisconfigured kèm lý do tiếng Việt, đúng một request', function (int $status, string $reason, string $message) {
    $id = $this->drive->putFile('18~a.pdf', 'x', [FakeGoogleDrive::ROOT_FOLDER_ID]);
    $this->drive->failNext('GET', '/files/'.$id, $status, $reason);

    expect(fn () => $this->drive->client()->metadata($id))
        ->toThrow(DocumentStorageMisconfigured::class, __($message));

    Http::assertSentCount(1);
})->with([
    '403 storageQuotaExceeded' => [403, 'storageQuotaExceeded', 'storage.exceptions.drive_reasons.storageQuotaExceeded'],
    '403 teamDriveFileLimitExceeded' => [403, 'teamDriveFileLimitExceeded', 'storage.exceptions.drive_reasons.teamDriveFileLimitExceeded'],
    '403 numChildrenInNonRootLimitExceeded' => [403, 'numChildrenInNonRootLimitExceeded', 'storage.exceptions.drive_reasons.numChildrenInNonRootLimitExceeded'],
    '403 insufficientFilePermissions' => [403, 'insufficientFilePermissions', 'storage.exceptions.drive_reasons.insufficientFilePermissions'],
    '403 teamDriveMembershipRequired' => [403, 'teamDriveMembershipRequired', 'storage.exceptions.drive_reasons.teamDriveMembershipRequired'],
]);

it('lỗi 4xx khác không thử lại → DocumentStorageMisconfigured chung', function () {
    $id = $this->drive->putFile('18~a.pdf', 'x', [FakeGoogleDrive::ROOT_FOLDER_ID]);
    $this->drive->failNext('GET', '/files/'.$id, 400, 'invalid');

    expect(fn () => $this->drive->client()->metadata($id))
        ->toThrow(DocumentStorageMisconfigured::class, __('storage.exceptions.drive_rejected', ['status' => 400, 'reason' => 'invalid']));

    Http::assertSentCount(1);
});

it('404 trên một tệp → DriveApiError 404 (người gọi biết khoá thì đổi thành StoredFileMissing), không thử lại', function () {
    try {
        $this->drive->client()->metadata('khong-co');
        $this->fail('Phải ném DriveApiError.');
    } catch (DriveApiError $error) {
        expect($error->isNotFound())->toBeTrue()
            ->and($error->status)->toBe(404);
    }

    Http::assertSentCount(1);
});

it('404 trên Shared Drive hay thư mục cha → DocumentStorageMisconfigured', function (Closure $call) {
    expect(fn () => $call($this->drive->client()))
        ->toThrow(DocumentStorageMisconfigured::class, __('storage.exceptions.container_not_found'));
})->with([
    'drives.get' => [fn (DriveClient $client) => $client->drive('drive-khac')],
    'permissions.list' => [fn (DriveClient $client) => $client->drivePermissions('drive-khac')],
    'tạo thư mục dưới cha không có' => [fn (DriveClient $client) => $client->folder('cha-khong-co', '2026-10')],
    'tải lên vào cha không có' => [function (DriveClient $client) {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, 'x');
        rewind($stream);

        return $client->upload('cha-khong-co', '18~a.pdf', 'application/pdf', $stream, 1);
    }],
]);

it('trong job: tối đa 4 lần rồi DocumentStorageUnavailable; backoff mũ, thêm ngẫu nhiên tới 1 giây, trần 32 giây', function () {
    $id = $this->drive->putFile('18~a.pdf', 'x', [FakeGoogleDrive::ROOT_FOLDER_ID]);
    $this->drive->failNext('GET', '/files/'.$id, 503, times: 10);

    expect(fn () => $this->drive->client(DriveCircuitBreaker::JOB)->metadata($id))
        ->toThrow(DocumentStorageUnavailable::class);

    Http::assertSentCount(4);
    Sleep::assertSleptTimes(3);

    expect(DriveClient::backoffMilliseconds(1))->toBeGreaterThanOrEqual(1000)->toBeLessThanOrEqual(2000)
        ->and(DriveClient::backoffMilliseconds(2))->toBeGreaterThanOrEqual(2000)->toBeLessThanOrEqual(3000)
        ->and(DriveClient::backoffMilliseconds(3))->toBeGreaterThanOrEqual(4000)->toBeLessThanOrEqual(5000)
        ->and(DriveClient::backoffMilliseconds(6))->toBe(32000)
        ->and(DriveClient::backoffMilliseconds(20))->toBe(32000);
});

it('trong request web: tối đa một lần thử lại rồi DocumentStorageUnavailable', function () {
    $id = $this->drive->putFile('18~a.pdf', 'x', [FakeGoogleDrive::ROOT_FOLDER_ID]);
    $this->drive->failNext('GET', '/files/'.$id, 500, times: 10);

    expect(fn () => $this->drive->client(DriveCircuitBreaker::WEB)->open($id))
        ->toThrow(DocumentStorageUnavailable::class);

    Http::assertSentCount(2);
});

it('401 làm mới token đúng một lần rồi gửi lại', function () {
    $tokens = new ServiceAccountTokenProvider(FakeGoogleDrive::serviceAccountKeyFile(), Cache::store('array'));
    $client = $this->drive->client(tokens: $tokens);
    $id = $this->drive->putFile('18~a.pdf', 'x', [FakeGoogleDrive::ROOT_FOLDER_ID]);

    $client->metadata($id);
    $this->drive->revokeTokens();

    expect($client->metadata($id)['id'])->toBe($id)
        ->and($this->drive->assertions)->toHaveCount(2);

    $drive = driveRequests();
    expect($drive)->toHaveCount(3)
        ->and($drive[1]->header('Authorization')[0])->toBe('Bearer ya29.issued-token-1')
        ->and($drive[2]->header('Authorization')[0])->toBe('Bearer ya29.issued-token-2');
});

it('401 lần nữa sau khi đã làm mới → DocumentStorageMisconfigured, không làm mới lần hai', function () {
    $tokens = new ServiceAccountTokenProvider(FakeGoogleDrive::serviceAccountKeyFile(), Cache::store('array'));
    $id = $this->drive->putFile('18~a.pdf', 'x', [FakeGoogleDrive::ROOT_FOLDER_ID]);
    $this->drive->failNext('GET', '/files/'.$id, 401, 'authError', times: 5);

    expect(fn () => $this->drive->client(tokens: $tokens)->metadata($id))
        ->toThrow(DocumentStorageMisconfigured::class, __('storage.exceptions.credentials_rejected'));

    expect($this->drive->assertions)->toHaveCount(2)
        ->and(driveRequests())->toHaveCount(2);
});

it('log của lỗi 401/500 ghi phương thức, đường, mã, lý do, lần thử; không header, token hay khoá', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
        $logged[] = $event;
    });

    $tokens = new ServiceAccountTokenProvider(FakeGoogleDrive::serviceAccountKeyFile(), Cache::store('array'));
    $id = $this->drive->putFile('18~a.pdf', 'x', [FakeGoogleDrive::ROOT_FOLDER_ID]);
    $this->drive->failNext('GET', '/files/'.$id, 401, 'authError');
    $this->drive->failNext('GET', '/files/'.$id, 500, 'backendError', times: 10);

    try {
        $this->drive->client(tokens: $tokens)->metadata($id);
    } catch (DocumentStorageUnavailable $e) {
        $chain = [];
        for ($error = $e; $error !== null; $error = $error->getPrevious()) {
            $chain[] = get_class($error).': '.$error->getMessage();
        }
    }

    $text = implode("\n", [
        ...array_map(fn (MessageLogged $event) => $event->message.' '.json_encode($event->context), $logged),
        ...$chain,
    ]);

    expect($logged)->not->toBeEmpty()
        ->and($text)->not->toContain('Bearer')
        ->and($text)->not->toContain('ya29.')
        ->and($text)->not->toContain('access_token')
        ->and($text)->not->toContain('private_key')
        ->and($text)->not->toContain('-----BEGIN')
        ->and($text)->not->toContain($id);

    $context = collect($logged)->map->context->first(fn (array $context) => ($context['status'] ?? null) === 500);

    expect($context)->toMatchArray([
        'method' => 'GET',
        'endpoint' => 'drive/v3/files/{fileId}',
        'status' => 500,
        'reason' => 'backendError',
    ])->and($context)->toHaveKey('attempt');
});

it('lỗi kết nối không lọt URL (mã tệp, phiên tải lên) vào log hay ngoại lệ', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
        $logged[] = $event->message.' '.json_encode($event->context);
    });

    $id = $this->drive->putFile('18~a.pdf', 'x', [FakeGoogleDrive::ROOT_FOLDER_ID]);
    $this->drive->failNext('GET', '/files/'.$id, 0, times: 10);

    try {
        $this->drive->client()->metadata($id);
    } catch (DocumentStorageUnavailable $e) {
        $chain = [];
        for ($error = $e; $error !== null; $error = $error->getPrevious()) {
            $chain[] = $error->getMessage();
        }
    }

    expect(implode("\n", [...$logged, ...$chain]))->not->toContain($id)
        ->not->toContain('googleapis.com');
});

// -------------------------------------------------------------------------------------------------
// Ngắt mạch (R9): hai phạm vi riêng, chỉ lỗi đọc và metadata được đếm
// -------------------------------------------------------------------------------------------------

it('ba lỗi đọc → lời gọi thứ tư ném ngay, không request nào; sau 60 giây thì thử lại', function () {
    $id = $this->drive->putFile('18~a.pdf', 'x', [FakeGoogleDrive::ROOT_FOLDER_ID]);
    $this->drive->failNext('GET', '/files/'.$id, 503, times: 6);
    $client = $this->drive->client(DriveCircuitBreaker::WEB);

    foreach (range(1, 3) as $ignored) {
        expect(fn () => $client->open($id))->toThrow(DocumentStorageUnavailable::class);
    }

    Http::assertSentCount(6);

    expect(fn () => $client->open($id))->toThrow(DocumentStorageUnavailable::class);
    expect(fn () => $client->metadata($id))->toThrow(DocumentStorageUnavailable::class);
    Http::assertSentCount(6);

    $this->travel(61)->seconds();

    expect(stream_get_contents($client->open($id)))->toBe('x');
    Http::assertSentCount(7);
});

it('hai lỗi không mở mạch; lỗi cũ hơn 60 giây không được đếm', function () {
    $id = $this->drive->putFile('18~a.pdf', 'x', [FakeGoogleDrive::ROOT_FOLDER_ID]);
    $this->drive->failNext('GET', '/files/'.$id, 503, times: 6);
    $client = $this->drive->client(DriveCircuitBreaker::WEB);

    expect(fn () => $client->metadata($id))->toThrow(DocumentStorageUnavailable::class);
    expect(fn () => $client->metadata($id))->toThrow(DocumentStorageUnavailable::class);

    $this->travel(61)->seconds();

    expect(fn () => $client->metadata($id))->toThrow(DocumentStorageUnavailable::class);
    Http::assertSentCount(6);

    expect($client->metadata($id)['id'])->toBe($id);
    Http::assertSentCount(7);
});

it('ba lỗi khối tải lên trong phạm vi job không mở mạch nào', function () {
    $folder = $this->drive->client()->folder(FakeGoogleDrive::ROOT_FOLDER_ID, '2026-10');
    $id = $this->drive->putFile('18~a.pdf', 'x', [FakeGoogleDrive::ROOT_FOLDER_ID]);

    foreach (range(1, 3) as $ignored) {
        $this->drive->failNext('PUT', 'upload_id=', 503, times: 8);
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, 'noi dung');
        rewind($stream);

        expect(fn () => $this->drive->client(DriveCircuitBreaker::JOB)->upload($folder, '18~b.pdf', 'application/pdf', $stream, 8))
            ->toThrow(DocumentStorageUnavailable::class);
    }

    $before = count(Http::recorded());

    expect($this->drive->client(DriveCircuitBreaker::JOB)->metadata($id)['id'])->toBe($id)
        ->and($this->drive->client(DriveCircuitBreaker::WEB)->metadata($id)['id'])->toBe($id);

    expect(count(Http::recorded()))->toBe($before + 2);
});

it('ba lỗi đọc trong phạm vi job không mở mạch web', function () {
    $id = $this->drive->putFile('18~a.pdf', 'x', [FakeGoogleDrive::ROOT_FOLDER_ID]);
    $this->drive->failNext('GET', '/files/'.$id, 503, times: 12);
    $job = $this->drive->client(DriveCircuitBreaker::JOB);

    foreach (range(1, 3) as $ignored) {
        expect(fn () => $job->metadata($id))->toThrow(DocumentStorageUnavailable::class);
    }

    expect(fn () => $job->metadata($id))->toThrow(DocumentStorageUnavailable::class);
    Http::assertSentCount(12);

    expect($this->drive->client(DriveCircuitBreaker::WEB)->metadata($id)['id'])->toBe($id);
    Http::assertSentCount(13);
});

it('lỗi tạm thời của endpoint token cũng được đếm', function () {
    $tokens = new ServiceAccountTokenProvider(FakeGoogleDrive::serviceAccountKeyFile(), Cache::store('array'));
    $client = $this->drive->client(DriveCircuitBreaker::WEB, $tokens);
    $this->drive->respondNext('POST', FakeGoogleDrive::TOKEN_URL, Http::response('', 503), times: 3);

    foreach (range(1, 3) as $ignored) {
        expect(fn () => $client->metadata('bat-ky'))->toThrow(DocumentStorageUnavailable::class);
    }

    Http::assertSentCount(3);

    expect(fn () => $client->metadata('bat-ky'))->toThrow(DocumentStorageUnavailable::class);
    Http::assertSentCount(3);
});

it('phạm vi tự chọn theo app()->runningInConsole(): job khi chạy lệnh, web khi phục vụ request', function () {
    $breaker = new DriveCircuitBreaker(Cache::store('array'));

    expect($breaker->scope())->toBe(DriveCircuitBreaker::JOB);

    $console = new ReflectionProperty(app(), 'isRunningInConsole');
    $saved = $console->getValue(app());
    $console->setValue(app(), false);

    try {
        expect($breaker->scope())->toBe(DriveCircuitBreaker::WEB);
    } finally {
        $console->setValue(app(), $saved);
    }
});

it('khoá trạng thái ngắt mạch là drive-breaker:web và drive-breaker:job', function () {
    $store = Cache::store('array');

    foreach ([DriveCircuitBreaker::WEB, DriveCircuitBreaker::JOB] as $scope) {
        $breaker = new DriveCircuitBreaker($store, $scope);
        $breaker->recordFailure();

        expect($store->get('drive-breaker:'.$scope))->not->toBeNull();
    }
});

it('kích thước khối phải là bội của 256 KiB', function (int $bytes) {
    expect(fn () => new DriveClient(FakeGoogleDrive::tokenProvider(), $this->drive->breaker(), FakeGoogleDrive::DRIVE_ID, $bytes))
        ->toThrow(InvalidArgumentException::class);
})->with([0, -262144, 1000, 262145]);
