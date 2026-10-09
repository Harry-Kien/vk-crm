<?php

namespace App\Support\Storage\GoogleDrive;

use App\Exceptions\DocumentStorageMisconfigured;
use App\Exceptions\DocumentStorageUnavailable;
use GuzzleHttp\Psr7\StreamWrapper;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Client Google Drive REST v3 của dự án (kế hoạch M14, R1): đúng mười thao tác mà kho cần, trên
 * `Http` của Laravel, nên `Http::fake()` phủ mọi lệnh gọi. Không `google/apiclient`, không gói
 * Flysystem bên thứ ba (R1).
 *
 * # Không link, không quyền chia sẻ (R3, R5)
 *
 * - Mọi request mà client dựng mang `supportsAllDrives=true` (kể cả `drives.get`, theo kế hoạch;
 *   test sống xác nhận Google nhận nó); mọi danh sách mang `corpora=drive&driveId=…&
 *   includeItemsFromAllDrives=true`. Các `PUT` vào phiên tải lên dùng NGUYÊN URI phiên Google trả
 *   về, không thêm tham số.
 * - `fields=` luôn tường minh và không bao giờ xin `webViewLink`, `webContentLink`, `thumbnailLink`,
 *   `exportLinks` hay `permissions` của một tệp: không link Drive nào có cơ hội rời máy chủ.
 * - Chỉ có `permissions.list` (GET) trong {@see self::drivePermissions()}, để kiểm chia sẻ. Không
 *   lệnh tạo, sửa hay xoá quyền nào; có test cấu trúc giữ điều đó.
 * - Không bao giờ tìm tệp hay thư mục theo TÊN (Drive cho trùng tên): mọi thứ đi qua mã tệp.
 *
 * # Thời gian chờ, thử lại, phân loại lỗi (R9)
 *
 * - Kết nối 5 giây; lệnh metadata 30 giây; tải xuống không giới hạn tổng nhưng 60 giây giữa hai khối
 *   (`read_timeout`); mỗi khối tải lên 120 giây.
 * - Thử lại lỗi TẠM THỜI ({@see DriveApiError::isTransient()}) với backoff mũ, thêm ngẫu nhiên tới 1
 *   giây, trần 32 giây ({@see self::backoffMilliseconds()}): tối đa 2 lần (một lần thử lại) trong
 *   request web, 4 lần trong lệnh/job; hết lượt → {@see DocumentStorageUnavailable}.
 * - 401: bỏ token, lấy token mới, gửi lại — đúng MỘT lần; 401 lần nữa →
 *   {@see DocumentStorageMisconfigured}. Lượt làm mới không tính vào số lần thử.
 * - Lỗi không thử lại → {@see DocumentStorageMisconfigured} kèm câu tiếng Việt; riêng 404 trên một
 *   TỆP → ném chính {@see DriveApiError} (`isNotFound()`), để nơi biết khoá đổi nó thành
 *   `StoredFileMissing`. 404 trên Shared Drive, thư mục gốc hay thư mục cha là lỗi cấu hình.
 * - Ngắt mạch ({@see DriveCircuitBreaker}) theo phạm vi `web`/`job`: mọi thao tác hỏi nó trước khi
 *   gửi; chỉ thao tác đọc và metadata hỏng (sau khi hết lượt thử) và lỗi tạm thời của endpoint token
 *   được đếm. Tải lên không bao giờ được đếm.
 * - Log mỗi lần thất bại qua {@see DriveApiError::log()}: thao tác, phương thức, đường MẪU, mã, lý do,
 *   lần thử. Không header, không token, không thân phản hồi, không URL.
 *
 * # Tải lên resumable (R9)
 *
 * Một đường mã cho mọi kích thước, kể cả 0 byte. Xem {@see self::upload()}.
 */
final class DriveClient
{
    public const API = 'https://www.googleapis.com/drive/v3/';

    public const UPLOAD_URL = 'https://www.googleapis.com/upload/drive/v3/files';

    public const FOLDER_MIME = 'application/vnd.google-apps.folder';

    /** Trường của {@see self::metadata()}: đúng tám trường của kế hoạch, cộng `name`. */
    public const FILE_FIELDS = 'id,name,size,md5Checksum,sha256Checksum,trashed,parents,driveId,mimeType';

    public const UPLOADED_FIELDS = 'id,name,size,md5Checksum,mimeType';

    public const DRIVE_FIELDS = 'id,name,restrictions,capabilities';

    public const PERMISSION_FIELDS = 'nextPageToken,permissions(id,type,role,emailAddress,domain,deleted)';

    public const LIST_FIELDS = 'nextPageToken,files(id,name,size,md5Checksum,mimeType,parents)';

    /** Google đòi mọi khối không cuối là bội của 256 KiB. */
    public const CHUNK_UNIT = 262144;

    public const UPLOAD_CHUNK_TIMEOUT = 120;

    public const MAX_ATTEMPTS_WEB = 2;

    public const MAX_ATTEMPTS_JOB = 4;

    public const BACKOFF_CAP_MILLISECONDS = 32000;

    private const METADATA = 'metadata';

    private const DOWNLOAD = 'download';

    private const CHUNK = 'chunk';

    public function __construct(
        private readonly DriveTokenProvider $tokens,
        private readonly DriveCircuitBreaker $breaker,
        private readonly string $driveId,
        private readonly int $chunkBytes,
        private readonly int $connectTimeout = 5,
        private readonly int $timeout = 30,
        private readonly int $readTimeout = 60,
    ) {
        if ($chunkBytes < self::CHUNK_UNIT || $chunkBytes % self::CHUNK_UNIT !== 0) {
            throw new InvalidArgumentException('Khối tải lên phải là bội dương của 256 KiB.');
        }
    }

    /** Client theo `vkcrm.storage.google_drive.*` (Shared Drive, cỡ khối, thời gian chờ). */
    public static function fromConfig(DriveTokenProvider $tokens, DriveCircuitBreaker $breaker): self
    {
        $config = (array) config('vkcrm.storage.google_drive');

        return new self(
            $tokens,
            $breaker,
            (string) ($config['shared_drive_id'] ?? ''),
            (int) ($config['chunk_mb'] ?? 8) * 1048576,
            (int) ($config['connect_timeout'] ?? 5),
            (int) ($config['timeout'] ?? 30),
            (int) ($config['read_timeout'] ?? 60),
        );
    }

    /** Backoff trước lần thử thứ `$attempt + 1`: 2^(n−1) giây + ngẫu nhiên tới 1 giây, trần 32 giây. */
    public static function backoffMilliseconds(int $attempt): int
    {
        return min(self::BACKOFF_CAP_MILLISECONDS, (2 ** max(0, $attempt - 1)) * 1000 + random_int(0, 1000));
    }

    // ---------------------------------------------------------------------------------------------
    // Mười thao tác
    // ---------------------------------------------------------------------------------------------

    /** `drives.get`: tên, `restrictions`, `capabilities` của một Shared Drive. */
    public function drive(string $driveId): array
    {
        return $this->json('drives.get', 'GET', 'drives/'.rawurlencode($driveId), 'drive/v3/drives/{driveId}', [
            'fields' => self::DRIVE_FIELDS,
        ], fileScoped: false);
    }

    /**
     * `permissions.list` của một Shared Drive (mã Shared Drive làm `fileId`), đi hết mọi trang. GET
     * là lệnh DUY NHẤT tới `/permissions` trong mã (R5).
     *
     * @return list<array<string, mixed>>
     */
    public function drivePermissions(string $driveId): array
    {
        $permissions = [];
        $pageToken = null;

        do {
            $page = $this->json('permissions.list', 'GET', 'files/'.rawurlencode($driveId).'/permissions', 'drive/v3/files/{driveId}/permissions', array_filter([
                'fields' => self::PERMISSION_FIELDS,
                'pageSize' => 100,
                'pageToken' => $pageToken,
            ]), fileScoped: false);

            array_push($permissions, ...array_values((array) ($page['permissions'] ?? [])));
            $pageToken = $page['nextPageToken'] ?? null;
        } while (is_string($pageToken) && $pageToken !== '');

        return $permissions;
    }

    /** Tạo một thư mục dưới `$parentId`, trả mã của nó. Không tìm theo tên trước: Drive cho trùng tên. */
    public function folder(string $parentId, string $name): string
    {
        $folder = $this->json('files.create', 'POST', 'files', 'drive/v3/files', ['fields' => 'id'], [
            'name' => $name,
            'mimeType' => self::FOLDER_MIME,
            'parents' => [$parentId],
        ], fileScoped: false);

        return (string) $folder['id'];
    }

    /**
     * Tải một tệp lên `$parentId` bằng phiên resumable, rồi kiểm md5 và kích thước do Google tính.
     *
     * - `POST …/upload/drive/v3/files?uploadType=resumable` (khai loại và cỡ) lấy URI phiên. Thân chỉ
     *   có `name`, `parents`, `mimeType`: không `description`, không `appProperties` (R4).
     * - `PUT` từng khối `Content-Range: bytes a-b/<tổng>`; 308 kèm `Range: bytes=0-N` → đi tiếp từ
     *   N+1 (có thể giữa khối vừa gửi: phần còn lại của khối được gửi lại từ bộ nhớ). Bộ nhớ giữ tối
     *   đa MỘT khối; mọi khối không cuối là bội của 256 KiB.
     * - Lỗi giữa chừng (kết nối, 429, 5xx) → chờ backoff, hỏi trạng thái phiên (`bytes * /<tổng>`, thân
     *   rỗng), đi tiếp từ byte Google đã nhận. Tối đa {@see self::maxAttempts()} lần hỏng liền nhau
     *   KHÔNG có tiến triển; có tiến triển thì đếm lại. 401 → làm mới token một lần rồi hỏi trạng thái.
     *   Phiên hết hạn (404/410) → {@see DocumentStorageUnavailable}: lượt sau mở phiên mới. Lỗi khối
     *   không bao giờ được đếm vào ngắt mạch.
     * - Tệp 0 byte: một `PUT` thân rỗng `bytes * /0`.
     * - md5 tính dần trong lúc đọc luồng. md5 hay kích thước Google báo lệch bản gửi đi → cho tệp vừa
     *   tải vào thùng rác (hỏng cả bước đó thì log, tệp thành mồ côi cho `vkcrm:storage:orphans`), rồi
     *   ném {@see DriveApiError} lý do {@see DriveApiError::CHECKSUM_MISMATCH}. Phản hồi hoàn tất không
     *   có mã tệp → cùng lỗi đó, không gì để cho vào thùng rác.
     * - Luồng ngắn hay dài hơn `$size` → {@see RuntimeException}, phiên không bao giờ hoàn tất.
     *
     * @param  resource  $stream
     * @return array{id: string, name?: string, size: string, md5Checksum: string, mimeType?: string}
     */
    public function upload(string $parentId, string $name, string $mime, $stream, int $size): array
    {
        $this->breaker->assertClosed();

        $session = $this->startUploadSession($parentId, $name, $mime, $size);
        $md5 = hash_init('md5');
        $offset = 0;
        $buffer = '';
        $read = 0;
        $failures = 0;
        $askStatus = false;
        $refreshed = false;

        while (true) {
            if (! $askStatus) {
                while (strlen($buffer) < $this->chunkBytes && $read < $size) {
                    $data = fread($stream, min($this->chunkBytes - strlen($buffer), $size - $read));

                    if ($data === false || $data === '') {
                        throw new RuntimeException(__('storage.drive.stream_size_mismatch', ['declared' => $size, 'read' => $read]));
                    }

                    hash_update($md5, $data);
                    $buffer .= $data;
                    $read += strlen($data);
                }

                if ($read === $size && fread($stream, 1) !== '') {
                    throw new RuntimeException(__('storage.drive.stream_size_mismatch', ['declared' => $size, 'read' => $size + 1]));
                }
            }

            $range = match (true) {
                $askStatus, $size === 0 => 'bytes */'.$size,
                default => 'bytes '.$offset.'-'.($offset + strlen($buffer) - 1).'/'.$size,
            };

            $response = $this->putChunk($session, $askStatus ? '' : $buffer, $mime, $range);

            if ($response !== null && $response->successful()) {
                return $this->verifyUploaded((array) $response->json(), hash_final($md5), $size);
            }

            if ($response !== null && $response->status() === 308) {
                $acknowledged = self::acknowledgedBytes($response);

                if ($acknowledged < $offset || $acknowledged > $offset + strlen($buffer)) {
                    throw DocumentStorageUnavailable::temporarily(new RuntimeException(__('storage.drive.upload_protocol')));
                }

                if ($acknowledged > $offset) {
                    $failures = 0;
                }

                $buffer = substr($buffer, $acknowledged - $offset);
                $offset = $acknowledged;
                $askStatus = false;

                continue;
            }

            $error = $response === null
                ? DriveApiError::connection('files.create', 'PUT', 'upload/drive/v3/files', $failures + 1)
                : DriveApiError::fromResponse('files.create', 'PUT', 'upload/drive/v3/files', $response, $failures + 1);
            $error->log();

            if ($error->status === 401 && ! $refreshed) {
                $this->tokens->forget();
                $refreshed = true;
                $askStatus = true;

                continue;
            }

            if (in_array($error->status, [404, 410], true)) {
                throw DocumentStorageUnavailable::temporarily($error);
            }

            if (! $error->isTransient()) {
                throw $this->failure($error, fileScoped: false, counts: false);
            }

            if (++$failures >= $this->maxAttempts()) {
                throw DocumentStorageUnavailable::temporarily($error);
            }

            Sleep::for(self::backoffMilliseconds($failures))->milliseconds();
            $askStatus = true;
        }
    }

    /**
     * `files.get` (metadata) của một tệp.
     *
     * @return array<string, mixed>
     */
    public function metadata(string $fileId): array
    {
        return $this->json('files.get', 'GET', 'files/'.rawurlencode($fileId), 'drive/v3/files/{fileId}', [
            'fields' => self::FILE_FIELDS,
        ]);
    }

    /**
     * `files.get?alt=media`: mở luồng đọc nội dung. Trả khi Google đã trả header, CHƯA đọc nội dung:
     * nơi gọi mở luồng trước, ghi nhật ký sau, rồi mới stream (R3).
     *
     * @return resource
     */
    public function open(string $fileId)
    {
        $response = $this->send('files.get', 'GET', self::API.'files/'.rawurlencode($fileId), 'drive/v3/files/{fileId}', [
            'alt' => 'media',
        ], kind: self::DOWNLOAD);

        return StreamWrapper::getResource($response->toPsrResponse()->getBody());
    }

    /** `files.update {"trashed": true}`. Không bao giờ `files.delete` (R8). */
    public function trash(string $fileId): void
    {
        $this->json('files.update', 'PATCH', 'files/'.rawurlencode($fileId), 'drive/v3/files/{fileId}', ['fields' => 'id,trashed'], [
            'trashed' => true,
        ]);
    }

    public function rename(string $fileId, string $name): void
    {
        $this->json('files.update', 'PATCH', 'files/'.rawurlencode($fileId), 'drive/v3/files/{fileId}', ['fields' => 'id,name'], [
            'name' => $name,
        ]);
    }

    /**
     * `files.copy` (phía máy chủ, không tải về) vào `$parentId` với tên `$name`.
     *
     * @return array{id: string, name?: string, size: string, md5Checksum: string, mimeType?: string}
     */
    public function copy(string $fileId, string $parentId, string $name): array
    {
        return $this->json('files.copy', 'POST', 'files/'.rawurlencode($fileId).'/copy', 'drive/v3/files/{fileId}/copy', [
            'fields' => self::UPLOADED_FIELDS,
        ], [
            'name' => $name,
            'parents' => [$parentId],
        ]);
    }

    /**
     * Một trang con trực tiếp (chưa vào thùng rác) của một thư mục, trong Shared Drive đang cấu hình.
     *
     * @return array{files: list<array<string, mixed>>, nextPageToken: ?string}
     */
    public function children(string $folderId, ?string $pageToken = null): array
    {
        $page = $this->json('files.list', 'GET', 'files', 'drive/v3/files', array_filter([
            'q' => "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $folderId)."' in parents and trashed = false",
            'corpora' => 'drive',
            'driveId' => $this->driveId,
            'includeItemsFromAllDrives' => 'true',
            'fields' => self::LIST_FIELDS,
            'pageSize' => 1000,
            'pageToken' => $pageToken,
        ]), fileScoped: false);

        $next = $page['nextPageToken'] ?? null;

        return [
            'files' => array_values((array) ($page['files'] ?? [])),
            'nextPageToken' => is_string($next) && $next !== '' ? $next : null,
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // Gửi, thử lại, phân loại
    // ---------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function json(string $operation, string $method, string $path, string $endpoint, array $query, ?array $body = null, bool $fileScoped = true): array
    {
        return (array) $this->send($operation, $method, self::API.$path, $endpoint, $query, $body, $fileScoped)->json();
    }

    /**
     * Gửi một request tới Drive theo luật thử lại và phân loại của R9 (docblock lớp).
     *
     * @param  array<string, scalar>  $query
     * @param  array<string, string>  $headers
     */
    private function send(
        string $operation,
        string $method,
        string $url,
        string $endpoint,
        array $query,
        ?array $body = null,
        bool $fileScoped = true,
        string $kind = self::METADATA,
        array $headers = [],
        bool $counts = true,
    ): Response {
        $this->breaker->assertClosed();

        $query = ['supportsAllDrives' => 'true'] + $query;
        $refreshed = false;
        $attempt = 0;

        while (true) {
            $attempt++;
            $token = $this->token($counts);

            try {
                $request = $this->pending($kind)->withToken($token)->withHeaders($headers)->withQueryParameters($query);
                $response = $body === null ? $request->send($method, $url) : $request->send($method, $url, ['json' => $body]);
            } catch (ConnectionException) {
                $response = null;
            }

            if ($response !== null && $response->successful()) {
                return $response;
            }

            $error = $response === null
                ? DriveApiError::connection($operation, $method, $endpoint, $attempt)
                : DriveApiError::fromResponse($operation, $method, $endpoint, $response, $attempt);
            $error->log();

            if ($error->status === 401 && ! $refreshed) {
                $this->tokens->forget();
                $refreshed = true;
                $attempt--;

                continue;
            }

            if ($error->isTransient() && $attempt < $this->maxAttempts()) {
                Sleep::for(self::backoffMilliseconds($attempt))->milliseconds();

                continue;
            }

            throw $this->failure($error, $fileScoped, $counts);
        }
    }

    /** Ngoại lệ công khai cho một lỗi đã hết đường thử lại (bảng phân loại ở docblock lớp). */
    private function failure(DriveApiError $error, bool $fileScoped, bool $counts): Throwable
    {
        if ($error->isTransient()) {
            if ($counts) {
                $this->breaker->recordFailure();
            }

            return DocumentStorageUnavailable::temporarily($error);
        }

        if ($error->status === 401) {
            return DocumentStorageMisconfigured::credentialsRejected($error);
        }

        if ($error->isNotFound()) {
            return $fileScoped ? $error : DocumentStorageMisconfigured::containerNotFound($error);
        }

        return DocumentStorageMisconfigured::fromDrive($error);
    }

    /** Token cho một lệnh gọi; lỗi tạm thời của endpoint token được đếm vào ngắt mạch khi `$counts`. */
    private function token(bool $counts): string
    {
        try {
            return $this->tokens->token();
        } catch (DocumentStorageUnavailable $e) {
            if ($counts) {
                $this->breaker->recordFailure();
            }

            throw $e;
        }
    }

    private function pending(string $kind): PendingRequest
    {
        $request = Http::connectTimeout($this->connectTimeout);

        return match ($kind) {
            self::DOWNLOAD => $request->timeout(0)->withOptions(['stream' => true, 'read_timeout' => $this->readTimeout]),
            self::CHUNK => $request->timeout(self::UPLOAD_CHUNK_TIMEOUT),
            default => $request->timeout($this->timeout)->acceptJson(),
        };
    }

    private function maxAttempts(): int
    {
        return $this->breaker->scope() === DriveCircuitBreaker::WEB ? self::MAX_ATTEMPTS_WEB : self::MAX_ATTEMPTS_JOB;
    }

    // ---------------------------------------------------------------------------------------------
    // Tải lên
    // ---------------------------------------------------------------------------------------------

    /** Mở phiên resumable, trả URI phiên. URI phải ở `https://www.googleapis.com/`: token đi theo nó. */
    private function startUploadSession(string $parentId, string $name, string $mime, int $size): string
    {
        $response = $this->send('files.create', 'POST', self::UPLOAD_URL, 'upload/drive/v3/files', [
            'uploadType' => 'resumable',
            'fields' => self::UPLOADED_FIELDS,
        ], [
            'name' => $name,
            'parents' => [$parentId],
            'mimeType' => $mime,
        ], fileScoped: false, headers: [
            'X-Upload-Content-Type' => $mime,
            'X-Upload-Content-Length' => (string) $size,
        ], counts: false);

        $session = $response->header('Location');

        if (! str_starts_with($session, 'https://www.googleapis.com/upload/')) {
            throw DocumentStorageUnavailable::temporarily(new RuntimeException(__('storage.drive.upload_protocol')));
        }

        return $session;
    }

    /** Một `PUT` vào phiên; `null` khi lỗi kết nối. URI phiên giữ nguyên (không thêm tham số). */
    private function putChunk(string $session, string $body, string $mime, string $range): ?Response
    {
        try {
            return $this->pending(self::CHUNK)
                ->withToken($this->token(counts: false))
                ->withHeaders(['Content-Range' => $range])
                ->withBody($body, $mime)
                ->send('PUT', $session);
        } catch (ConnectionException) {
            return null;
        }
    }

    /** Số byte Google đã nhận, từ `Range: bytes=0-N` của một 308 (vắng = 0). */
    private static function acknowledgedBytes(Response $response): int
    {
        return preg_match('/^bytes=0-(\d+)$/', $response->header('Range'), $match) === 1 ? (int) $match[1] + 1 : 0;
    }

    /** @param  array<string, mixed>  $file */
    private function verifyUploaded(array $file, string $md5, int $size): array
    {
        $id = $file['id'] ?? null;

        // Không có mã tệp thì không có gì để kiểm hay cho vào thùng rác.
        if (! is_string($id)) {
            throw DriveApiError::checksumMismatch('files.create', 'PUT', 'upload/drive/v3/files');
        }

        if (($file['md5Checksum'] ?? null) === $md5 && (string) ($file['size'] ?? '') === (string) $size) {
            return $file;
        }

        try {
            $this->trash($id);
        } catch (Throwable $e) {
            Log::error(__('storage.drive.log.trash_after_failure'), ['operation' => 'files.create', 'exception' => $e::class]);
        }

        throw DriveApiError::checksumMismatch('files.create', 'PUT', 'upload/drive/v3/files');
    }
}
