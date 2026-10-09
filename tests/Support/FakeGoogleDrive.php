<?php

namespace Tests\Support;

use App\Models\DriveObject;
use App\Support\Storage\DocumentStore;
use App\Support\Storage\GoogleDrive\DriveAdapter;
use App\Support\Storage\GoogleDrive\DriveCircuitBreaker;
use App\Support\Storage\GoogleDrive\DriveClient;
use App\Support\Storage\GoogleDrive\DriveObjectIndex;
use App\Support\Storage\GoogleDrive\DriveObjectName;
use App\Support\Storage\GoogleDrive\DriveTokenProvider;
use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * M14 Task 2 — một máy chủ Google Drive GIẢ, có trạng thái, đứng sau `Http::fake()` +
 * `Http::preventStrayRequests()` (kế hoạch M14, R7; phán quyết C2: không test nào gọi Google thật).
 *
 * Adapter và client THẬT nói chuyện với nó qua `Http` của Laravel, đúng như với Google: endpoint
 * token, `files.create` (thư mục), phiên tải lên resumable (308 + `Range`, hỏi trạng thái bằng
 * `bytes * /<tổng>`), `files.get` (metadata và `alt=media`), `files.update` (thùng rác, đổi tên),
 * `files.copy`, `files.list`, `drives.get`, `permissions.list`. Nhờ vậy test đo được ĐÚNG các
 * request đi ra (`Http::recorded()`), không phải một lớp giả của chính client.
 *
 * - Lỗi gài trước bằng {@see self::failNext()}: mã trạng thái + `reason` theo khuôn lỗi của Google,
 *   hoặc lỗi kết nối; với phiên tải lên có thể giữ lại một phần byte của khối hỏng
 *   (`keepBytes`), như Google đã nhận một phần trước khi đứt.
 * - md5 do "Google" tính có thể bị đổi ({@see self::$md5Overrides}) để dựng bản tải lên lệch.
 * - Request tới `www.googleapis.com` không mang access token đã cấp → 401, như Google.
 * - Request lạ → ngoại lệ ngay (test đỏ rõ lý do), không bao giờ một phản hồi đoán mò.
 *
 * `install()` cũng bật `Sleep::fake()` (backoff của client không làm chậm bộ test) và đặt hai store
 * cache của kho (token, ngắt mạch) về `array`: store `file` của production không được dùng trong test.
 */
final class FakeGoogleDrive
{
    public const DRIVE_ID = '0AFakeSharedDriveKho';

    public const ROOT_FOLDER_ID = '1FakeRootFolderGoc';

    public const SERVICE_ACCOUNT = 'vkcrm-kho@vk-crm-test.iam.gserviceaccount.com';

    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** Access token mà {@see self::tokenProvider()} trả, được máy chủ giả chấp nhận từ đầu. */
    public const STATIC_TOKEN = 'ya29.static-test-token';

    private const API = 'https://www.googleapis.com/drive/v3/';

    private const UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';

    private static ?array $keyPair = null;

    /** @var array<string, array{id: string, name: string, mimeType: string, parents: list<string>, driveId: string, content: string, trashed: bool}> */
    public array $files = [];

    /** @var array<string, string> tên tệp → md5 mà "Google" báo thay cho md5 thật */
    public array $md5Overrides = [];

    /** @var array<string, int> tên tệp → kích thước mà "Google" báo thay cho kích thước thật */
    public array $sizeOverrides = [];

    /** @var list<string> các assertion JWT đã gửi tới endpoint token, theo thứ tự */
    public array $assertions = [];

    /** @var list<array{url: string, method: string, options: array<string, mixed>}> */
    public array $options = [];

    /** @var array<string, mixed> phản hồi của `drives.get` */
    public array $drive;

    /** @var list<array<string, mixed>> các trang của `permissions.list` */
    public array $permissionPages;

    /** @var array<string, array{name: string, parents: list<string>, mimeType: string, total: int, received: string, done: ?string}> */
    private array $sessions = [];

    /** @var list<array{method: string, contains: string, respond: Closure, keepBytes: int, skip: int}> */
    private array $failures = [];

    /** @var array<string, true> */
    private array $validTokens = [self::STATIC_TOKEN => true];

    private int $nextId = 1;

    private int $issued = 0;

    private function __construct()
    {
        $this->drive = [
            'id' => self::DRIVE_ID,
            'name' => 'VK-CRM Kho',
            'restrictions' => [
                'driveMembersOnly' => true,
                'domainUsersOnly' => false,
                'sharingFoldersRequiresOrganizerPermission' => true,
                'copyRequiresWriterPermission' => false,
                'adminManagedRestrictions' => true,
            ],
            'capabilities' => ['canAddChildren' => true, 'canTrashChildren' => true],
        ];

        $this->permissionPages = [[
            'permissions' => [
                ['id' => 'p1', 'type' => 'user', 'role' => 'fileOrganizer', 'emailAddress' => self::SERVICE_ACCOUNT],
            ],
        ]];

        $this->files[self::ROOT_FOLDER_ID] = [
            'id' => self::ROOT_FOLDER_ID,
            'name' => 'vk-crm-test',
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents' => [self::DRIVE_ID],
            'driveId' => self::DRIVE_ID,
            'content' => '',
            'trashed' => false,
        ];
    }

    /**
     * Cài máy chủ giả: `Http::preventStrayRequests()` + `Http::fake()`, `Sleep::fake()`, cấu hình kho
     * trỏ vào Shared Drive và thư mục gốc giả, khoá tài khoản dịch vụ sinh lúc chạy, store `array`.
     */
    public static function install(): self
    {
        $fake = new self;

        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake(fn (Request $request, array $options) => $fake->handle($request, $options));

        config([
            'vkcrm.storage.google_drive.credentials_path' => self::serviceAccountKeyFile(),
            'vkcrm.storage.google_drive.shared_drive_id' => self::DRIVE_ID,
            'vkcrm.storage.google_drive.root_folder_id' => self::ROOT_FOLDER_ID,
            'vkcrm.storage.google_drive.token_cache_store' => 'array',
            'vkcrm.storage.google_drive.breaker_store' => 'array',
            'vkcrm.storage.google_drive.chunk_mb' => 1,
        ]);

        return $fake;
    }

    // ---------------------------------------------------------------------------------------------
    // Dựng client / adapter / đĩa thật trên máy chủ giả
    // ---------------------------------------------------------------------------------------------

    /** Token cố định, không gọi HTTP — cho các test không đo endpoint token. */
    public static function tokenProvider(): DriveTokenProvider
    {
        return new class implements DriveTokenProvider
        {
            public int $forgotten = 0;

            public function token(): string
            {
                return FakeGoogleDrive::STATIC_TOKEN;
            }

            public function forget(): void
            {
                $this->forgotten++;
            }
        };
    }

    public function breaker(string $scope = DriveCircuitBreaker::JOB): DriveCircuitBreaker
    {
        return new DriveCircuitBreaker(Cache::store('array'), $scope);
    }

    public function client(string $scope = DriveCircuitBreaker::JOB, ?DriveTokenProvider $tokens = null, ?int $chunkBytes = null): DriveClient
    {
        return new DriveClient(
            $tokens ?? self::tokenProvider(),
            $this->breaker($scope),
            self::DRIVE_ID,
            $chunkBytes ?? DriveClient::CHUNK_UNIT * 4,
        );
    }

    public function adapter(string $scope = DriveCircuitBreaker::JOB, ?DriveClient $client = null): DriveAdapter
    {
        return new DriveAdapter(
            $client ?? $this->client($scope),
            new DriveObjectIndex,
            self::DRIVE_ID,
            self::ROOT_FOLDER_ID,
        );
    }

    /**
     * Đĩa `documents_remote` THẬT (qua `DocumentStorageServiceProvider`), thay cho đĩa giả mà
     * `tests/Pest.php` đặt cho mọi test. Token cố định, không gọi endpoint token.
     */
    public function disk(?DriveTokenProvider $tokens = null): Filesystem
    {
        app()->instance(DriveTokenProvider::class, $tokens ?? self::tokenProvider());
        Storage::forgetDisk(DocumentStore::REMOTE_DISK);

        return Storage::disk(DocumentStore::REMOTE_DISK);
    }

    // ---------------------------------------------------------------------------------------------
    // Gieo dữ liệu
    // ---------------------------------------------------------------------------------------------

    /** Một tệp đã nằm trên Drive giả, cộng dòng chỉ mục sống của nó. */
    public function seed(string $key, string $content = 'noi dung', int $generation = 1, ?string $mime = 'application/pdf'): DriveObject
    {
        $id = $this->putFile(DriveObjectName::fromKey($key, $generation), $content, [self::ROOT_FOLDER_ID], $mime ?? 'application/octet-stream');

        return DriveObject::query()->create([
            'drive_id' => self::DRIVE_ID,
            'object_key' => $key,
            'generation' => $generation,
            'file_id' => $id,
            'parent_id' => self::ROOT_FOLDER_ID,
            'size' => strlen($content),
            'md5' => md5($content),
            'mime_type' => $mime,
        ]);
    }

    public function putFile(string $name, string $content, array $parents, string $mime = 'application/octet-stream'): string
    {
        $id = 'f'.str_pad((string) $this->nextId++, 6, '0', STR_PAD_LEFT).'AbC';

        $this->files[$id] = [
            'id' => $id,
            'name' => $name,
            'mimeType' => $mime,
            'parents' => $parents,
            'driveId' => self::DRIVE_ID,
            'content' => $content,
            'trashed' => false,
        ];

        return $id;
    }

    /** @return list<array<string, mixed>> các tệp (không phải thư mục) có tên đã cho */
    public function named(string $name): array
    {
        return array_values(array_filter($this->files, fn (array $file): bool => $file['name'] === $name));
    }

    /** @return list<array<string, mixed>> */
    public function folders(): array
    {
        return array_values(array_filter(
            $this->files,
            fn (array $file): bool => $file['mimeType'] === 'application/vnd.google-apps.folder' && $file['id'] !== self::ROOT_FOLDER_ID,
        ));
    }

    /** Mọi access token đã cấp hay cố định thôi có hiệu lực: request sau đó nhận 401. */
    public function revokeTokens(): void
    {
        $this->validTokens = [];
    }

    // ---------------------------------------------------------------------------------------------
    // Gài lỗi
    // ---------------------------------------------------------------------------------------------

    /**
     * Gài một lỗi cho request khớp kế tiếp (`$times` lần). `$status` 0 = lỗi kết nối. `$contains`
     * so với URL đầy đủ (kể cả query). `keepBytes`: với một khối tải lên, máy chủ giả giữ lại chừng
     * đó byte đầu của khối trước khi trả lỗi.
     */
    public function failNext(string $method, string $contains, int $status, ?string $reason = null, int $times = 1, int $keepBytes = 0, int $after = 0): self
    {
        return $this->respondNext(
            $method,
            $contains,
            fn (Request $request): PromiseInterface => $status === 0
                ? Create::rejectionFor(new ConnectException('cURL error 28: Operation timed out for '.$request->url(), $request->toPsrRequest()))
                : self::error($status, $reason),
            $times,
            $keepBytes,
            $after,
        );
    }

    /**
     * Gài một phản hồi tuỳ ý cho request khớp kế tiếp (`$times` lần): một promise
     * (`Http::response(...)`) hay closure nhận request (`Http::failedConnection()`). Gài ở đây chứ
     * không bằng một `Http::fake([...])` thứ hai: Laravel hỏi các stub theo thứ tự đăng ký, nên stub
     * đăng ký sau máy chủ giả không bao giờ được hỏi. `$after`: để chừng đó request khớp đi qua bình
     * thường trước lượt gài đầu tiên.
     */
    public function respondNext(string $method, string $contains, Closure|PromiseInterface $response, int $times = 1, int $keepBytes = 0, int $after = 0): self
    {
        for ($i = 0; $i < $times; $i++) {
            $this->failures[] = [
                'method' => strtoupper($method),
                'contains' => $contains,
                'respond' => $response instanceof Closure ? $response : fn (): PromiseInterface => $response,
                'keepBytes' => $keepBytes,
                'skip' => $i === 0 ? $after : 0,
            ];
        }

        return $this;
    }

    /** Phản hồi lỗi theo đúng khuôn JSON của Google Drive v3. */
    public static function error(int $status, ?string $reason = null): PromiseInterface
    {
        return Factory::response([
            'error' => [
                'code' => $status,
                'message' => 'Fake Google error',
                'errors' => [['domain' => 'global', 'reason' => $reason ?? 'backendError', 'message' => 'Fake Google error']],
            ],
        ], $status);
    }

    // ---------------------------------------------------------------------------------------------
    // Khoá tài khoản dịch vụ sinh lúc chạy (không khoá thật nào trong repo, phán quyết C2)
    // ---------------------------------------------------------------------------------------------

    /** @return array{private: string, public: string} */
    public static function keyPair(): array
    {
        if (self::$keyPair === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $private);

            self::$keyPair = ['private' => $private, 'public' => openssl_pkey_get_details($key)['key']];
        }

        return self::$keyPair;
    }

    /** Tệp khoá JSON của một tài khoản dịch vụ giả, trong thư mục tạm của hệ thống. */
    public static function serviceAccountKeyFile(array $overrides = []): string
    {
        $json = array_merge([
            'type' => 'service_account',
            'project_id' => 'vk-crm-test',
            'private_key_id' => 'test-key-id',
            'private_key' => self::keyPair()['private'],
            'client_email' => self::SERVICE_ACCOUNT,
            'client_id' => '100000000000000000001',
            'token_uri' => self::TOKEN_URL,
        ], $overrides);

        $path = sys_get_temp_dir().'/vkcrm-drive-key-'.getmypid().'-'.md5(json_encode($json)).'.json';
        file_put_contents($path, json_encode($json));

        return $path;
    }

    // ---------------------------------------------------------------------------------------------
    // Máy chủ
    // ---------------------------------------------------------------------------------------------

    public function handle(Request $request, array $options): PromiseInterface
    {
        $this->options[] = ['url' => $request->url(), 'method' => $request->method(), 'options' => $options];

        foreach ($this->failures as $index => $failure) {
            if ($failure['method'] !== $request->method() || ! str_contains($request->url(), $failure['contains'])) {
                continue;
            }

            if ($failure['skip'] > 0) {
                $this->failures[$index]['skip']--;

                break;
            }

            unset($this->failures[$index]);
            $this->failures = array_values($this->failures);

            if ($failure['keepBytes'] > 0) {
                $this->receiveChunk($request, $failure['keepBytes']);
            }

            return ($failure['respond'])($request);
        }

        if ($request->url() === self::TOKEN_URL) {
            return $this->token($request);
        }

        if (! str_starts_with($request->url(), 'https://www.googleapis.com/')) {
            throw new RuntimeException('FakeGoogleDrive: request lạ '.$request->method().' '.$request->url());
        }

        $authorization = $request->header('Authorization')[0] ?? '';

        if (! isset($this->validTokens[substr($authorization, strlen('Bearer '))])) {
            return self::error(401, 'authError');
        }

        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        if (str_starts_with($request->url(), self::UPLOAD)) {
            return $request->method() === 'POST' ? $this->startSession($request) : $this->chunk($request, $query);
        }

        $route = substr($path, strlen('/drive/v3/'));

        return match (true) {
            $request->method() === 'GET' && preg_match('#^drives/([^/]+)$#', $route, $m) === 1 => $this->getDrive($m[1]),
            $request->method() === 'GET' && preg_match('#^files/([^/]+)/permissions$#', $route, $m) === 1 => $this->listPermissions($m[1], $query),
            $request->method() === 'POST' && preg_match('#^files/([^/]+)/copy$#', $route, $m) === 1 => $this->copyFile($m[1], $request),
            $request->method() === 'GET' && preg_match('#^files/([^/]+)$#', $route, $m) === 1 => $this->getFile($m[1], $query),
            $request->method() === 'PATCH' && preg_match('#^files/([^/]+)$#', $route, $m) === 1 => $this->updateFile($m[1], $request),
            $request->method() === 'POST' && $route === 'files' => $this->createFolder($request),
            $request->method() === 'GET' && $route === 'files' => $this->listFiles($query),
            default => throw new RuntimeException('FakeGoogleDrive: request lạ '.$request->method().' '.$request->url()),
        };
    }

    private function token(Request $request): PromiseInterface
    {
        $this->assertions[] = (string) ($request->data()['assertion'] ?? '');
        $token = 'ya29.issued-token-'.(++$this->issued);
        $this->validTokens[$token] = true;

        return Factory::response(['access_token' => $token, 'expires_in' => 3599, 'token_type' => 'Bearer']);
    }

    private function getDrive(string $id): PromiseInterface
    {
        return $id === self::DRIVE_ID ? Factory::response($this->drive) : self::error(404, 'notFound');
    }

    private function listPermissions(string $id, array $query): PromiseInterface
    {
        if ($id !== self::DRIVE_ID) {
            return self::error(404, 'notFound');
        }

        $page = (int) ($query['pageToken'] ?? 0);
        $body = $this->permissionPages[$page] ?? ['permissions' => []];

        if (isset($this->permissionPages[$page + 1])) {
            $body['nextPageToken'] = (string) ($page + 1);
        }

        return Factory::response($body);
    }

    private function getFile(string $id, array $query): PromiseInterface
    {
        if (! isset($this->files[$id])) {
            return self::error(404, 'notFound');
        }

        $file = $this->files[$id];

        if (($query['alt'] ?? null) === 'media') {
            return Factory::response($file['content'], 200, ['Content-Type' => $file['mimeType']]);
        }

        return Factory::response($this->metadataOf($file));
    }

    private function updateFile(string $id, Request $request): PromiseInterface
    {
        if (! isset($this->files[$id])) {
            return self::error(404, 'notFound');
        }

        $body = json_decode($request->body(), true) ?: [];

        if (array_key_exists('trashed', $body)) {
            $this->files[$id]['trashed'] = (bool) $body['trashed'];
        }

        if (array_key_exists('name', $body)) {
            $this->files[$id]['name'] = (string) $body['name'];
        }

        return Factory::response(['id' => $id, 'name' => $this->files[$id]['name'], 'trashed' => $this->files[$id]['trashed']]);
    }

    private function copyFile(string $id, Request $request): PromiseInterface
    {
        if (! isset($this->files[$id])) {
            return self::error(404, 'notFound');
        }

        $body = json_decode($request->body(), true) ?: [];
        $source = $this->files[$id];
        $copy = $this->putFile((string) $body['name'], $source['content'], $body['parents'] ?? $source['parents'], $source['mimeType']);

        return Factory::response($this->metadataOf($this->files[$copy]));
    }

    private function createFolder(Request $request): PromiseInterface
    {
        $body = json_decode($request->body(), true) ?: [];

        foreach ($body['parents'] ?? [] as $parent) {
            if (! isset($this->files[$parent])) {
                return self::error(404, 'notFound');
            }
        }

        $id = $this->putFile((string) $body['name'], '', $body['parents'] ?? [], (string) ($body['mimeType'] ?? 'application/octet-stream'));

        return Factory::response(['id' => $id]);
    }

    private function listFiles(array $query): PromiseInterface
    {
        preg_match("#'([^']+)' in parents#", (string) ($query['q'] ?? ''), $m);
        $parent = $m[1] ?? null;

        $files = array_values(array_map(
            fn (array $file): array => $this->metadataOf($file),
            array_filter($this->files, fn (array $file): bool => in_array($parent, $file['parents'], true) && ! $file['trashed']),
        ));

        return Factory::response(['files' => $files]);
    }

    private function startSession(Request $request): PromiseInterface
    {
        $body = json_decode($request->body(), true) ?: [];

        foreach ($body['parents'] ?? [] as $parent) {
            if (! isset($this->files[$parent])) {
                return self::error(404, 'notFound');
            }
        }

        $id = 'sess'.count($this->sessions);
        $this->sessions[$id] = [
            'name' => (string) $body['name'],
            'parents' => $body['parents'] ?? [],
            'mimeType' => (string) ($body['mimeType'] ?? 'application/octet-stream'),
            'total' => (int) ($request->header('X-Upload-Content-Length')[0] ?? -1),
            'received' => '',
            'done' => null,
        ];

        return Factory::response('', 200, ['Location' => $request->url().'&upload_id='.$id]);
    }

    private function chunk(Request $request, array $query): PromiseInterface
    {
        $id = (string) ($query['upload_id'] ?? '');

        if (! isset($this->sessions[$id])) {
            return self::error(404, 'notFound');
        }

        $range = $request->header('Content-Range')[0] ?? '';

        if (preg_match('#^bytes \*/(\d+)$#', $range, $m) === 1) {
            if ((int) $m[1] === 0 && $this->sessions[$id]['total'] === 0) {
                return $this->finish($id);
            }

            return $this->sessions[$id]['done'] !== null ? $this->finish($id) : $this->incomplete($id);
        }

        if (preg_match('#^bytes (\d+)-(\d+)/(\d+)$#', $range, $m) !== 1) {
            throw new RuntimeException('FakeGoogleDrive: Content-Range sai khuôn: '.$range);
        }

        $session = $this->sessions[$id];

        if ((int) $m[1] !== strlen($session['received']) || (int) $m[3] !== $session['total']
            || strlen($request->body()) !== (int) $m[2] - (int) $m[1] + 1) {
            throw new RuntimeException('FakeGoogleDrive: khối không nối tiếp đúng byte đã nhận: '.$range);
        }

        $this->receiveChunk($request, PHP_INT_MAX);

        return strlen($this->sessions[$id]['received']) === $session['total'] ? $this->finish($id) : $this->incomplete($id);
    }

    private function receiveChunk(Request $request, int $limit): void
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $id = (string) ($query['upload_id'] ?? '');

        if (isset($this->sessions[$id]) && $request->body() !== '') {
            $this->sessions[$id]['received'] .= substr($request->body(), 0, $limit);
        }
    }

    private function incomplete(string $id): PromiseInterface
    {
        $received = strlen($this->sessions[$id]['received']);

        return Factory::response('', 308, $received > 0 ? ['Range' => 'bytes=0-'.($received - 1)] : []);
    }

    private function finish(string $id): PromiseInterface
    {
        $session = $this->sessions[$id];

        if ($session['done'] === null) {
            $this->sessions[$id]['done'] = $this->putFile($session['name'], $session['received'], $session['parents'], $session['mimeType']);
        }

        return Factory::response($this->metadataOf($this->files[$this->sessions[$id]['done']]), 200);
    }

    /** @param  array<string, mixed>  $file */
    private function metadataOf(array $file): array
    {
        return [
            'id' => $file['id'],
            'name' => $file['name'],
            'mimeType' => $file['mimeType'],
            'size' => (string) ($this->sizeOverrides[$file['name']] ?? strlen($file['content'])),
            'md5Checksum' => $this->md5Overrides[$file['name']] ?? md5($file['content']),
            'sha256Checksum' => hash('sha256', $file['content']),
            'trashed' => $file['trashed'],
            'parents' => $file['parents'],
            'driveId' => $file['driveId'],
        ];
    }
}
