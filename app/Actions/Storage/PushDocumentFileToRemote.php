<?php

namespace App\Actions\Storage;

use App\Enums\PushOutcome;
use App\Exceptions\DocumentStorageMisconfigured;
use App\Exceptions\DocumentStorageUnavailable;
use App\Exceptions\StoredFileMissing;
use App\Exceptions\StoredFileTrashed;
use App\Models\Setting;
use App\Support\Storage\DocumentStore;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use League\Flysystem\UnableToProvideChecksum;
use League\Flysystem\UnableToWriteFile;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Đẩy MỘT tệp từ vùng đệm (`private`) lên kho (`documents_remote`), theo sáu bước của kế hoạch M14,
 * R2. Là đường DUY NHẤT đổi một media sang kho: job `PushDocumentFile` (tệp mới, sau commit), tác vụ
 * quét `storage.push-pending` (qua job) và lệnh chuyển tệp cũ (Task 6) đều gọi đúng lớp này — không có
 * bản thứ hai của luật kiểm checksum (R11).
 *
 * 1. **Khoá** `DocumentStore::pushLock($mediaId)` (không chờ): không lấy được → {@see PushOutcome::Locked}.
 *    Lượt dọn vùng đệm và lệnh quay lui giữ cùng khoá cho từng media. Khoá lấy NGOÀI mọi transaction
 *    (dòng `cache_locks` phải thấy được ngay với tiến trình khác).
 *    Dưới khoá, hỏi {@see DocumentStore::pushesNewFiles()}: lệnh quay lui xoá mốc bật kho TRƯỚC
 *    khi khoá từng media, nên một job đã xếp từ trước không đẩy lại media vừa được kéo về
 *    ({@see PushOutcome::Disabled}).
 *    Đọc lại dòng `media`: không còn → {@see PushOutcome::Gone}; đã ở kho →
 *    {@see PushOutcome::AlreadyRemote}, không chạm kho.
 * 2. **Khoá đường dẫn** phải đúng `<media_id>/<ULID viết thường>[.<đuôi a-z0-9, 1–8>]` (R4) và media
 *    phải ở vùng đệm; nếu không → log `critical` (chỉ mã media và đĩa: một tên tệp lệch khuôn có thể là
 *    tên người nộp đặt) và {@see PushOutcome::Rejected}. Tệp ở lại vùng đệm, được đếm vào tồn đọng.
 *    Tính md5, sha256 và cỡ của bản trong vùng đệm (đọc một lượt, từng khối 1 MiB). Vùng đệm không còn
 *    tệp → log `critical`, {@see StoredFileMissing}, không đổi đĩa.
 * 3. **Tải lên** đúng khoá đó. Kho đã có khoá (một lượt trước tải xong mà chưa kịp đổi đĩa): md5 do kho
 *    tính và cỡ khớp thì KHÔNG tải lần hai; lệch thì cho bản đó vào thùng rác rồi tải lại — adapter
 *    Drive đặt thế hệ kế tiếp (R4, R11).
 * 4. **Kiểm** md5 do kho tính (`checksum()`: với Drive là `md5Checksum` hỏi GOOGLE, không đọc chỉ mục)
 *    và cỡ. Lệch → cho bản trên kho vào thùng rác (dòng chỉ mục `trashed`) rồi ném
 *    {@see UnableToWriteFile}: job để hàng đợi thử lại theo backoff, lượt sau mang thế hệ mới.
 * 5. **Đổi đĩa** bằng một câu UPDATE có điều kiện `WHERE id = ? AND disk = 'private'` (không sự kiện
 *    model: `MediaObserver::updating()` không chạy). `local_purge_after` = `$keepLocalUntil` hoặc
 *    `now + staging_grace_hours`; bản trong vùng đệm KHÔNG bị xoá ở đây (R2, R10): một lượt tải đã nạp
 *    `disk = private` ngay trước câu UPDATE vẫn đọc được nó. 0 dòng: media đã bị xoá → cho bản trên kho
 *    vào thùng rác, {@see PushOutcome::Gone}; còn dòng (đĩa không còn `private`) →
 *    {@see PushOutcome::AlreadyRemote}.
 * 6. **Lần chuyển đầu tiên** (R13): trên production, lượt `Pushed` đầu tiên ghi
 *    `settings.storage.first_transfer_at` MỘT lần (`insertOrIgnore` trên khoá unique, rồi UPDATE
 *    `WHERE value IS NULL`), trong CÙNG transaction với câu đổi đĩa ở bước 5: một tiến trình chết giữa
 *    hai câu thì mọi lượt sau là `AlreadyRemote` và đồng hồ 60 ngày không bao giờ bắt đầu. Transaction
 *    đó chỉ có hai câu SQL — không I/O kho, không khoá đẩy (R2).
 *
 * Lỗi của kho đi thẳng ra cho người gọi phân loại: {@see DocumentStorageUnavailable} (tạm thời),
 * {@see DocumentStorageMisconfigured} (cấu hình). Không DB transaction nào bao quanh I/O kho.
 *
 * Không `final`: test thay lớp này trong container bằng một bản ghi lại lời gọi để đo thời điểm job chạy
 * (sau commit), như `BuildHandoverPackage`.
 */
class PushDocumentFileToRemote
{
    public const FIRST_TRANSFER_AT_KEY = 'storage.first_transfer_at';

    /** Tên tệp mà kho nhận: ULID viết thường (26 ký tự) cộng đuôi tuỳ chọn — khuôn của R4. */
    public const FILE_NAME_PATTERN = '/^[0-9a-z]{26}(\.[0-9a-z]{1,8})?$/D';

    private const HASH_CHUNK_BYTES = 1048576;

    public function handle(int $mediaId, ?CarbonInterface $keepLocalUntil = null): PushOutcome
    {
        $lock = DocumentStore::pushLock($mediaId);

        if (! $lock->get()) {
            return PushOutcome::Locked;
        }

        try {
            return $this->push($mediaId, $keepLocalUntil);
        } finally {
            $lock->release();
        }
    }

    private function push(int $mediaId, ?CarbonInterface $keepLocalUntil): PushOutcome
    {
        if (! DocumentStore::pushesNewFiles()) {
            return PushOutcome::Disabled;
        }

        $media = Media::query()->find($mediaId);

        if ($media === null) {
            return PushOutcome::Gone;
        }

        if ($media->disk === DocumentStore::REMOTE_DISK) {
            return PushOutcome::AlreadyRemote;
        }

        $key = $media->getPathRelativeToRoot();

        if ($media->disk !== DocumentStore::STAGING_DISK || ! $this->keyIsPushable($media, $key)) {
            Log::critical(__('storage.push.log.key_rejected'), ['media_id' => $mediaId, 'disk' => $media->disk]);

            return PushOutcome::Rejected;
        }

        /** @var FilesystemAdapter $staging */
        $staging = DocumentStore::staging();

        if (! $staging->exists($key)) {
            Log::critical(__('storage.push.log.staged_missing'), ['media_id' => $mediaId, 'key' => $key]);

            throw StoredFileMissing::staged($key);
        }

        [$md5, $sha256, $size] = $this->fingerprint($staging, $key);

        /** @var FilesystemAdapter $remote */
        $remote = DocumentStore::remote();

        if (! $this->reusableRemoteCopy($remote, $key, $md5, $size)) {
            $this->upload($staging, $remote, $key, $media);

            if (! $this->remoteMatches($remote, $key, $md5, $size)) {
                $remote->delete($key);
                Log::error(__('storage.push.log.checksum_mismatch'), ['media_id' => $mediaId, 'key' => $key]);

                throw UnableToWriteFile::atLocation($key, __('storage.push.checksum_mismatch', ['key' => $key]));
            }
        }

        if ($this->switchToRemote($mediaId, $md5, $sha256, $keepLocalUntil)) {
            return PushOutcome::Pushed;
        }

        // 0 dòng: dòng `media` đã bị xoá, hoặc đĩa của nó không còn là `private`.
        if (Media::query()->whereKey($mediaId)->doesntExist()) {
            $this->discardOrphan($remote, $key, $mediaId);

            return PushOutcome::Gone;
        }

        return PushOutcome::AlreadyRemote;
    }

    /** Khoá đúng `<media_id>/<tên tệp do CRM sinh>`: không tiền tố, không thư mục con, tên đúng khuôn. */
    private function keyIsPushable(Media $media, string $key): bool
    {
        return $key === $media->getKey().'/'.$media->file_name
            && preg_match(self::FILE_NAME_PATTERN, (string) $media->file_name) === 1;
    }

    /**
     * md5, sha256 và cỡ của bản trong vùng đệm, đọc MỘT lượt. Đọc luồng chứ không `get()`: gói bàn
     * giao tới 2 GB.
     *
     * @return array{0: string, 1: string, 2: int}
     */
    private function fingerprint(FilesystemAdapter $staging, string $key): array
    {
        $stream = $staging->readStream($key) ?? throw StoredFileMissing::staged($key);
        $md5 = hash_init('md5');
        $sha256 = hash_init('sha256');
        $size = 0;

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, self::HASH_CHUNK_BYTES);

                if ($chunk === false) {
                    throw new RuntimeException(__('storage.push.staged_file_missing', ['key' => $key]));
                }

                hash_update($md5, $chunk);
                hash_update($sha256, $chunk);
                $size += strlen($chunk);
            }
        } finally {
            fclose($stream);
        }

        return [hash_final($md5), hash_final($sha256), $size];
    }

    /**
     * Kho đã có bản của khoá này (một lượt trước tải xong mà chưa kịp đổi đĩa)? Khớp md5 và cỡ thì dùng
     * lại, không tải lần hai (R11). Lệch thì cho nó vào thùng rác ngay — kho bất biến, không ghi đè
     * được một khoá đang sống (R8) — và trả `false` để tải lại; adapter Drive đặt thế hệ kế tiếp.
     *
     * Dòng chỉ mục còn SỐNG mà tệp Drive của nó đã vào thùng rác hay đã mất (404) — quay lui giữ chỉ
     * mục, rồi có người dọn Shared Drive — cũng là "lệch" (rà soát cuối vòng sửa 1, I5): adapter Drive
     * báo `UnableToProvideChecksum` với gốc {@see StoredFileTrashed}/{@see StoredFileMissing}; `delete()`
     * rút dòng đó (`trashed`; 404 khi cho vào thùng rác được bỏ qua) và lượt này tải thế hệ kế tiếp. Không
     * vậy thì mọi lượt đều ném cùng lỗi, media báo "kho từ chối" mãi, và `reindex` không sửa được (nó chỉ
     * đi qua tên có trên Drive). Mọi lỗi khác khi hỏi md5 (kho không trả lời, cấu hình sai) ném ra như cũ.
     */
    private function reusableRemoteCopy(FilesystemAdapter $remote, string $key, string $md5, int $size): bool
    {
        if (! $remote->fileExists($key)) {
            return false;
        }

        try {
            if ($this->remoteMatches($remote, $key, $md5, $size)) {
                return true;
            }
        } catch (UnableToProvideChecksum $e) {
            if (! $e->getPrevious() instanceof StoredFileTrashed && ! $e->getPrevious() instanceof StoredFileMissing) {
                throw $e;
            }
        }

        $remote->delete($key);

        return false;
    }

    /** md5 do KHO tính và cỡ trên kho khớp bản trong vùng đệm. Cả hai là điều kiện (R4). */
    private function remoteMatches(FilesystemAdapter $remote, string $key, string $md5, int $size): bool
    {
        return $remote->checksum($key, ['checksum_algo' => 'md5']) === $md5
            && $remote->size($key) === $size;
    }

    private function upload(FilesystemAdapter $staging, FilesystemAdapter $remote, string $key, Media $media): void
    {
        $stream = $staging->readStream($key) ?? throw StoredFileMissing::staged($key);

        try {
            if ($remote->writeStream($key, $stream, ['mimetype' => $media->mime_type]) === false) {
                throw UnableToWriteFile::atLocation($key, __('storage.push.write_failed', ['key' => $key]));
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Bước 5 và 6 trong một transaction chỉ có SQL. Trả `true` khi đúng một dòng đổi đĩa.
     *
     * Mốc giữ bản cục bộ ghi theo giờ của ứng dụng (cột `timestamp` không mang múi giờ, và mọi so sánh
     * khác trên cột này dùng `now()` của ứng dụng), dù người gọi truyền mốc ở múi nào.
     */
    private function switchToRemote(int $mediaId, string $md5, string $sha256, ?CarbonInterface $keepLocalUntil): bool
    {
        $now = CarbonImmutable::now();
        $purgeAfter = CarbonImmutable::instance($keepLocalUntil ?? $now->addHours((int) config('vkcrm.storage.staging_grace_hours')))
            ->setTimezone(config('app.timezone'));

        return DB::transaction(function () use ($mediaId, $md5, $sha256, $now, $purgeAfter): bool {
            $updated = Media::query()
                ->whereKey($mediaId)
                ->where('disk', DocumentStore::STAGING_DISK)
                ->update([
                    'disk' => DocumentStore::REMOTE_DISK,
                    'conversions_disk' => DocumentStore::REMOTE_DISK,
                    'remote_pushed_at' => $now,
                    'local_purge_after' => $purgeAfter,
                    'checksum_md5' => $md5,
                    'checksum_sha256' => $sha256,
                ]);

            if ($updated === 1 && app()->isProduction()) {
                $this->recordFirstTransfer($now);
            }

            return $updated === 1;
        });
    }

    /** Ghi một lần, không bao giờ ghi đè (R13). */
    private function recordFirstTransfer(CarbonImmutable $now): void
    {
        Setting::query()->insertOrIgnore([
            'key' => self::FIRST_TRANSFER_AT_KEY,
            'value' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Setting::query()
            ->where('key', self::FIRST_TRANSFER_AT_KEY)
            ->whereNull('value')
            ->update(['value' => $now->toIso8601String()]);
    }

    /**
     * Media biến mất giữa lúc tải lên và lúc đổi đĩa: thư viện media đã xoá trên `private` (đĩa của nó
     * lúc đó), nên bản vừa tải lên kho không ai trỏ tới. Cho nó vào thùng rác; hỏng thì log —
     * `vkcrm:storage:orphans` là lưới, và kết quả vẫn là `Gone`.
     */
    private function discardOrphan(FilesystemAdapter $remote, string $key, int $mediaId): void
    {
        try {
            $remote->delete($key);
        } catch (Throwable $e) {
            Log::error(__('storage.push.log.gone_trash_failed'), ['media_id' => $mediaId, 'key' => $key, 'exception' => $e::class]);
        }
    }
}
