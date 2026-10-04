<?php

namespace App\Support\Storage\GoogleDrive;

use App\Enums\DriveObjectRetirement;
use App\Exceptions\DocumentStorageUnavailable;
use App\Exceptions\StoredFileMissing;
use App\Models\DriveFolder;
use App\Models\DriveObject;
use App\Support\Scopes\ClientPortalScope;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use League\Flysystem\ChecksumProvider;
use League\Flysystem\Config;
use League\Flysystem\CorruptedPathDetected;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToProvideChecksum;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use League\MimeTypeDetection\ExtensionMimeTypeDetector;
use Throwable;

/**
 * Adapter Flysystem của đĩa `documents_remote`: Google Drive (Shared Drive của văn phòng) làm kho
 * tài liệu (kế hoạch M14, R3, R4, R8). Khoá là đường dẫn thư viện media sinh sẵn
 * (`<media_id>/<file_name>`); tên trên Drive là khoá mờ có số thế hệ ({@see DriveObjectName}); chỉ
 * mục `drive_objects` ({@see DriveObjectIndex}) giữ khoá → mã tệp Drive.
 *
 * | Thao tác | Hành vi |
 * |---|---|
 * | `write`/`writeStream` | Khoá đã có dòng sống → {@see UnableToWriteFile} (tệp hồ sơ bất biến, R8). Nếu không: thế hệ kế tiếp → thư mục tháng → tải lên resumable kiểm md5 → ghi dòng chỉ mục. Ghi chỉ mục hỏng → bản vừa tải vào thùng rác |
 * | `read`/`readStream` | Chỉ mục → {@see DriveClient::open()} (một `GET alt=media`). Drive 404 → {@see UnableToReadFile} bọc {@see StoredFileMissing}; lỗi tạm thời → {@see DocumentStorageUnavailable} |
 * | `fileExists`, `fileSize`, `mimeType`, `lastModified`, `listContents`, `directoryExists`, `visibility` | Trả lời từ chỉ mục, KHÔNG gọi mạng |
 * | `delete` | Thùng rác + dòng rời chỉ mục (`retired_reason = trashed`). Khoá không có → không làm gì; Drive đã không còn tệp (404) → vẫn rời chỉ mục |
 * | `deleteDirectory` | Như `delete` cho đúng các khoá sống dưới tiền tố `<d>/`; `d` và `d/` như nhau; gốc bị từ chối |
 * | `createDirectory`, `setVisibility` | Không làm gì (thư mục là ảo, mọi tệp là riêng tư) |
 * | `move` | Đổi tên trên Drive + chỉ mục trỏ khoá mới. Giữ thế hệ, trừ khi khoá đích đã từng có thế hệ đó |
 * | `copy` | `files.copy` vào thư mục tháng + dòng chỉ mục mới; md5 bản chép lệch → thùng rác |
 * | `checksum` | Chỉ md5, HỎI GOOGLE (`md5Checksum`), không đọc chỉ mục; tệp ở thùng rác → không cung cấp |
 *
 * - **Không URL**: không cài `PublicUrlGenerator`/`TemporaryUrlGenerator`, không `getUrl()`: mọi lượt
 *   đọc đi qua route tải ký của CRM (R3).
 * - **Thư mục là tiền tố có `/`** (R4, docblock {@see DriveObjectIndex}).
 * - **Khoá an toàn**: không rỗng, không bắt đầu bằng `/`, không đoạn rỗng, không `..`, không `~`, không
 *   ký tự điều khiển hay khoảng trắng, dài tối đa 255 (độ dài cột `object_key`, kiểm TRƯỚC khi tải lên).
 *   Sai → {@see CorruptedPathDetected}, không request nào.
 * - **Thư mục tháng** `<thư mục gốc>/<YYYY-MM>/` theo tháng lúc ghi (múi giờ ứng dụng). Mã của nó ở
 *   bảng `drive_folders`; đọc bảng và tạo khi chưa có, cả hai dưới
 *   `Cache::lock('drive-folder:{root}:{name}', 60)` trên store khoá chung mọi tiến trình: mỗi tháng
 *   đúng một thư mục.
 * - **Không phụ thuộc guard nào đang mở**: chỉ mục và `drive_folders` được đọc KHÔNG qua
 *   `ClientPortalScope` (docblock {@see DriveObjectIndex}); route tải của cổng khách gọi adapter
 *   trong phiên khách, sau khi chính route đã kiểm quyền (R3).
 * - Không DB transaction nào ở đây: không I/O mạng tới kho trong transaction (R2).
 */
final class DriveAdapter implements ChecksumProvider, FilesystemAdapter
{
    public const MAX_KEY_LENGTH = 255;

    private const FOLDER_LOCK_SECONDS = 60;

    private const FOLDER_LOCK_WAIT_SECONDS = 30;

    public function __construct(
        private readonly DriveClient $client,
        private readonly DriveObjectIndex $index,
        private readonly string $driveId,
        private readonly string $rootFolderId,
        private readonly string $lockStore = 'database',
    ) {}

    // ---------------------------------------------------------------------------------------------
    // Ghi
    // ---------------------------------------------------------------------------------------------

    public function write(string $path, string $contents, Config $config): void
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $contents);
        rewind($stream);

        try {
            $this->writeStream($path, $stream, $config);
        } finally {
            fclose($stream);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->assertSafeKey($path);

        if ($this->index->live($path) !== null) {
            throw UnableToWriteFile::atLocation($path, __('storage.drive.immutable_key'));
        }

        $generation = $this->index->nextGeneration($path);

        try {
            $name = DriveObjectName::fromKey($path, $generation);
        } catch (InvalidArgumentException $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }

        [$stream, $size, $temporary] = $this->measured($contents);
        $mimeType = $this->mimeTypeFor($path, $config);
        $folder = $this->monthFolder();

        try {
            $file = $this->client->upload($folder, $name, $mimeType, $stream, $size);
        } catch (DriveApiError $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        } finally {
            if ($temporary) {
                fclose($stream);
            }
        }

        $this->recordOrDiscard($path, $generation, $folder, $file, $mimeType, fn (string $reason, Throwable $e) => UnableToWriteFile::atLocation($path, $reason, $e));
    }

    // ---------------------------------------------------------------------------------------------
    // Đọc
    // ---------------------------------------------------------------------------------------------

    public function read(string $path): string
    {
        $stream = $this->readStream($path);

        try {
            return (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    public function readStream(string $path)
    {
        $this->assertSafeKey($path);
        $object = $this->index->live($path) ?? throw UnableToReadFile::fromLocation($path, __('storage.drive.not_indexed'));

        try {
            return $this->client->open($object->file_id);
        } catch (DriveApiError $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e->isNotFound() ? StoredFileMissing::forKey($path) : $e);
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Trả lời từ chỉ mục — không gọi mạng
    // ---------------------------------------------------------------------------------------------

    public function fileExists(string $path): bool
    {
        $this->assertSafeKey($path);

        return $this->index->live($path) !== null;
    }

    public function directoryExists(string $path): bool
    {
        $directory = $this->directory($path);

        return $directory === '' || $this->index->hasLiveUnder($directory);
    }

    public function fileSize(string $path): FileAttributes
    {
        $object = $this->liveOrFail($path, 'fileSize');

        return new FileAttributes($path, $object->size);
    }

    public function mimeType(string $path): FileAttributes
    {
        $object = $this->liveOrFail($path, 'mimeType');

        if ($object->mime_type === null) {
            throw UnableToRetrieveMetadata::mimeType($path);
        }

        return new FileAttributes($path, null, null, null, $object->mime_type);
    }

    public function lastModified(string $path): FileAttributes
    {
        $object = $this->liveOrFail($path, 'lastModified');

        return new FileAttributes($path, null, null, $object->created_at?->getTimestamp());
    }

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, null, Visibility::PRIVATE);
    }

    /**
     * Nông: tệp ngay trong thư mục và các thư mục con trực tiếp (mỗi thư mục một lần). Sâu: mọi tệp
     * dưới tiền tố, cộng mọi thư mục ngầm định giữa chừng.
     */
    public function listContents(string $path, bool $deep): iterable
    {
        $directory = $this->directory($path);
        $prefixLength = $directory === '' ? 0 : strlen($directory) + 1;
        $seen = [];

        foreach ($this->index->liveUnder($directory) as $object) {
            $relative = substr($object->object_key, $prefixLength);
            $segments = explode('/', $relative);

            for ($depth = 1; $depth < count($segments); $depth++) {
                $subdirectory = substr($object->object_key, 0, $prefixLength).implode('/', array_slice($segments, 0, $depth));

                if (! isset($seen[$subdirectory])) {
                    $seen[$subdirectory] = true;

                    yield new DirectoryAttributes($subdirectory);
                }

                if (! $deep) {
                    break;
                }
            }

            if ($deep || count($segments) === 1) {
                yield new FileAttributes($object->object_key, $object->size, null, $object->created_at?->getTimestamp(), $object->mime_type);
            }
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        // Thư mục là ảo (tiền tố của khoá, R4): không có gì để tạo.
    }

    public function setVisibility(string $path, string $visibility): void
    {
        // Mọi tệp hồ sơ là riêng tư; Drive không có khái niệm này cho tệp trong Shared Drive.
    }

    // ---------------------------------------------------------------------------------------------
    // Xoá = thùng rác (R8)
    // ---------------------------------------------------------------------------------------------

    public function delete(string $path): void
    {
        $this->assertSafeKey($path);
        $object = $this->index->live($path);

        if ($object !== null) {
            $this->trash($object);
        }
    }

    public function deleteDirectory(string $path): void
    {
        $directory = $this->directory($path);

        if ($directory === '') {
            throw UnableToDeleteDirectory::atLocation($path, __('storage.drive.root_directory'));
        }

        foreach ($this->index->liveUnder($directory)->all() as $object) {
            $this->trash($object);
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Đổi tên, chép
    // ---------------------------------------------------------------------------------------------

    /**
     * Thư viện media gọi khi `file_name` của một media đổi (`MediaObserver::updating()`). Thế hệ của
     * tên mới: giữ thế hệ của tệp (kế hoạch), nhưng không thấp hơn thế hệ kế tiếp của khoá đích — một
     * tên đã từng thuộc khoá đích (bản cũ đã vào thùng rác, có thể đã ở máy văn phòng) không bao giờ
     * được dùng lại (R4).
     */
    public function move(string $source, string $destination, Config $config): void
    {
        $this->assertSafeKey($source);
        $this->assertSafeKey($destination);

        $object = $this->index->live($source) ?? throw UnableToMoveFile::because(__('storage.drive.not_indexed'), $source, $destination);

        if ($this->index->live($destination) !== null) {
            throw UnableToMoveFile::because(__('storage.drive.immutable_key'), $source, $destination);
        }

        $generation = max($object->generation, $this->index->nextGeneration($destination));

        try {
            $this->client->rename($object->file_id, DriveObjectName::fromKey($destination, $generation));
        } catch (DriveApiError|InvalidArgumentException $e) {
            throw UnableToMoveFile::because($e->getMessage(), $source, $destination);
        }

        $this->index->rekey($object, $destination, $generation);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $this->assertSafeKey($source);
        $this->assertSafeKey($destination);

        $object = $this->index->live($source) ?? throw UnableToCopyFile::because(__('storage.drive.not_indexed'), $source, $destination);

        if ($this->index->live($destination) !== null) {
            throw UnableToCopyFile::because(__('storage.drive.immutable_key'), $source, $destination);
        }

        $generation = $this->index->nextGeneration($destination);
        $folder = $this->monthFolder();

        try {
            $copy = $this->client->copy($object->file_id, $folder, DriveObjectName::fromKey($destination, $generation));
        } catch (DriveApiError|InvalidArgumentException $e) {
            throw UnableToCopyFile::because($e->getMessage(), $source, $destination);
        }

        if (($copy['md5Checksum'] ?? null) !== $object->md5 || (int) ($copy['size'] ?? -1) !== $object->size) {
            $this->discard((string) $copy['id']);

            throw UnableToCopyFile::because(__('storage.drive.checksum_mismatch', ['operation' => 'files.copy']), $source, $destination);
        }

        $this->recordOrDiscard($destination, $generation, $folder, $copy, $object->mime_type, fn (string $reason) => UnableToCopyFile::because($reason, $source, $destination));
    }

    // ---------------------------------------------------------------------------------------------
    // Checksum hỏi Google (R4)
    // ---------------------------------------------------------------------------------------------

    /**
     * md5 do GOOGLE tính (`md5Checksum`), không phải md5 trong chỉ mục: lần kiểm sau khi đẩy (R2) là
     * kiểm thật. Thuật toán khác → {@see UnableToProvideChecksum}, không phải
     * `ChecksumAlgoIsNotSupported`: Flysystem bắt lỗi đó và tự TẢI CẢ TỆP VỀ để tính.
     */
    public function checksum(string $path, Config $config): string
    {
        $this->assertSafeKey($path);

        if ($config->get('checksum_algo', 'md5') !== 'md5') {
            throw new UnableToProvideChecksum(__('storage.drive.checksum_algo'), $path);
        }

        $object = $this->index->live($path) ?? throw new UnableToProvideChecksum(__('storage.drive.not_indexed'), $path);

        try {
            $metadata = $this->client->metadata($object->file_id);
        } catch (DriveApiError $e) {
            throw new UnableToProvideChecksum($e->getMessage(), $path, $e->isNotFound() ? StoredFileMissing::forKey($path) : $e);
        }

        if (($metadata['trashed'] ?? false) === true) {
            throw new UnableToProvideChecksum(__('storage.drive.trashed'), $path);
        }

        $md5 = $metadata['md5Checksum'] ?? null;

        return is_string($md5) && $md5 !== '' ? $md5 : throw new UnableToProvideChecksum(__('storage.drive.checksum_algo'), $path);
    }

    // ---------------------------------------------------------------------------------------------
    // Nội bộ
    // ---------------------------------------------------------------------------------------------

    private function assertSafeKey(string $key): void
    {
        if ($key === ''
            || strlen($key) > self::MAX_KEY_LENGTH
            || str_starts_with($key, '/')
            || str_ends_with($key, '/')
            || str_contains($key, '//')
            || str_contains($key, '..')
            || str_contains($key, '~')
            || preg_match('/[\p{C}\s]/u', $key) !== 0) {
            throw CorruptedPathDetected::forPath($key);
        }
    }

    /** Đường thư mục bỏ `/` cuối (`d` và `d/` như nhau); `''` là gốc. */
    private function directory(string $path): string
    {
        $directory = rtrim($path, '/');

        if ($directory !== '') {
            $this->assertSafeKey($directory);
        }

        return $directory;
    }

    private function liveOrFail(string $path, string $type): DriveObject
    {
        $this->assertSafeKey($path);

        return $this->index->live($path) ?? throw UnableToRetrieveMetadata::create($path, $type, __('storage.drive.not_indexed'));
    }

    /** Thùng rác rồi rời chỉ mục; tệp đã không còn trên Drive (404) thì chỉ rời chỉ mục. */
    private function trash(DriveObject $object): void
    {
        try {
            $this->client->trash($object->file_id);
        } catch (DriveApiError $e) {
            if (! $e->isNotFound()) {
                throw $e;
            }
        }

        $this->index->retire($object, DriveObjectRetirement::Trashed);
    }

    /**
     * Ghi dòng chỉ mục cho một tệp VỪA có trên Drive. Ghi hỏng (khoá trùng vì một lượt ghi song song,
     * CSDL lỗi) thì cho bản vừa tạo vào thùng rác — không để một tệp không chỉ mục nào trỏ tới — rồi ném.
     *
     * @param  array<string, mixed>  $file
     * @param  Closure(string, Throwable): Throwable  $failure
     */
    private function recordOrDiscard(string $key, int $generation, string $folder, array $file, ?string $mimeType, Closure $failure): void
    {
        try {
            $this->index->record($this->driveId, $key, $generation, $folder, $file, $mimeType);
        } catch (Throwable $e) {
            $this->discard((string) $file['id']);

            throw $failure(__('storage.drive.index_write_failed'), $e);
        }
    }

    /** Cho một tệp mồ côi vừa tạo vào thùng rác; hỏng thì log (vkcrm:storage:orphans là lưới). */
    private function discard(string $fileId): void
    {
        try {
            $this->client->trash($fileId);
        } catch (Throwable $e) {
            Log::error(__('storage.drive.log.trash_after_failure'), ['exception' => $e::class]);
        }
    }

    /**
     * Luồng đọc được từ đầu kèm kích thước. Luồng có kích thước biết trước (tệp, `php://temp`) dùng
     * thẳng; luồng không đo được thì chép sang `php://temp` trước (người gọi đóng bản chép).
     *
     * @param  resource  $contents
     * @return array{0: resource, 1: int, 2: bool}
     */
    private function measured($contents): array
    {
        $meta = stream_get_meta_data($contents);
        $stat = fstat($contents);
        $position = ftell($contents);

        if ($meta['seekable'] && is_array($stat) && isset($stat['size']) && $position !== false) {
            return [$contents, $stat['size'] - $position, false];
        }

        $copy = fopen('php://temp', 'w+b');
        $size = (int) stream_copy_to_stream($contents, $copy);
        rewind($copy);

        return [$copy, $size, true];
    }

    private function mimeTypeFor(string $path, Config $config): string
    {
        $mimeType = $config->get('mimetype');

        if (is_string($mimeType) && $mimeType !== '') {
            return $mimeType;
        }

        return (new ExtensionMimeTypeDetector)->detectMimeTypeFromPath($path) ?? 'application/octet-stream';
    }

    /**
     * Mã thư mục tháng `<gốc>/<YYYY-MM>` (docblock lớp). Đọc bảng `drive_folders` DƯỚI khoá, mỗi lần
     * ghi: tạo thư mục là việc hiếm, khoá thì rẻ, và một lần đọc trước khoá chỉ thêm một đường để hai
     * tiến trình cùng tạo. Chờ khoá quá 30 giây → {@see DocumentStorageUnavailable} (thử lại sau).
     */
    private function monthFolder(): string
    {
        $name = now()->format('Y-m');

        try {
            return Cache::store($this->lockStore)
                ->lock('drive-folder:'.$this->rootFolderId.':'.$name, self::FOLDER_LOCK_SECONDS)
                ->block(self::FOLDER_LOCK_WAIT_SECONDS, function () use ($name): string {
                    $existing = $this->folders()
                        ->where('root_folder_id', $this->rootFolderId)
                        ->where('name', $name)
                        ->value('folder_id');

                    if (is_string($existing)) {
                        return $existing;
                    }

                    $id = $this->client->folder($this->rootFolderId, $name);

                    $this->folders()->create([
                        'drive_id' => $this->driveId,
                        'root_folder_id' => $this->rootFolderId,
                        'name' => $name,
                        'folder_id' => $id,
                    ]);

                    return $id;
                });
        } catch (LockTimeoutException $e) {
            throw DocumentStorageUnavailable::temporarily($e);
        }
    }

    /**
     * `drive_folders` BỎ `ClientPortalScope`, cùng lý do và cùng giới hạn với chỉ mục
     * ({@see DriveObjectIndex}, hàm `query()`): `DriveFolder` mang scope cổng khách (`1 = 0`), nên một
     * lượt ghi chạy trong phiên khách không thấy thư mục tháng đã có, tạo thêm một thư mục cùng tên
     * trên Drive rồi vấp unique `(root_folder_id, name)` — lượt ghi hỏng, thư mục thừa ở lại Drive.
     *
     * @return Builder<DriveFolder>
     */
    private function folders(): Builder
    {
        return DriveFolder::query()->withoutGlobalScope(ClientPortalScope::class);
    }
}
