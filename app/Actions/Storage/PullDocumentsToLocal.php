<?php

namespace App\Actions\Storage;

use App\Enums\PreflightLevel;
use App\Exceptions\DocumentStorageMisconfigured;
use App\Exceptions\DocumentStorageUnavailable;
use App\Exceptions\StoredFileChanged;
use App\Exceptions\StoredFileMissing;
use App\Models\Setting;
use App\Support\Audit;
use App\Support\Storage\DocumentStore;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use League\Flysystem\FilesystemException;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Quay lui: kéo mọi media trên kho về vùng đệm `private` — `vkcrm:storage:rollback` (kế hoạch M14,
 * R11; Phụ lục C bước 11 trong `docs/KHO-TAI-LIEU-GOOGLE-DRIVE.md`).
 *
 * # Cổng duy nhất: công tắc đã là `local`
 *
 * Đọc qua `config()` (sau `optimize`), phải là ĐÚNG chuỗi `local`; nếu không → `not_local`, không đổi
 * gì, không request nào. Thứ tự ngược lại (quay lui trước, tắt kho sau) là quay lui tự đảo ngược: tác
 * vụ quét `storage.push-pending` nhặt lại trong 15 phút mọi dòng vừa quay lui, và lượt đẩy thấy chỉ mục
 * có md5 khớp nên đổi chúng về kho ngay. Không cổng nào khác: chia sẻ lệch, chưa có biên nhận, kho
 * không tới được — không cái nào chặn những media còn bản cục bộ.
 *
 * # Thứ tự
 *
 *  1. Xoá mốc `settings.storage.remote_enabled_at` TRƯỚC mọi media: một job đẩy đã xếp từ trước, chạy
 *     sau khi media được kéo về, thấy `pushesNewFiles()` sai và trả `Disabled` (docblock của
 *     {@see PushDocumentFileToRemote}). Bật lại sau này phải chạy `vkcrm:storage:enable`, và media đã
 *     quay lui thành tệp cũ: chỉ `vkcrm:storage:migrate` chuyển chúng.
 *  2. Media ở `documents_remote`, từ id nhỏ tới lớn. Với MỖI media, dưới `DocumentStore::pushLock($id)`
 *     (lấy ngoài mọi transaction, không chờ; không lấy được → đếm `locked`, lượt sau chạy lại), đọc lại
 *     dòng (đã không còn ở kho → bỏ qua):
 *     - bản trong vùng đệm còn và md5 của nó bằng `media.checksum_md5` → đổi đĩa ngay. Không một lệnh
 *       gọi kho nào.
 *     - không còn (đã dọn theo biên nhận), hay lệch md5 → cần Drive. Lần ĐẦU cần, hỏi
 *       {@see StorageReadiness::downloadRows()} (khoá, HTTP client, `drives.get`) MỘT lần cho cả lượt;
 *       có dòng ĐỎ → bỏ qua đúng các media cần tải về (`unreachable`), media có bản cục bộ vẫn quay lui.
 *       Đạt → {@see MaterialiseStoredFile} tải về một tệp TẠM cạnh đích (`<tệp>.pull-<ngẫu nhiên>`, cùng
 *       thư mục nên đổi tên là nguyên tử), kiểm cỡ và md5 với dòng `media`; đạt thì đổi tên vào chỗ
 *       (thay bản cục bộ hỏng, nếu có), rồi mới đổi đĩa. Lệch md5, kho đứt giữa chừng, kho không còn tệp
 *       → không đổi đĩa, tệp tạm bị xoá, mã media vào danh sách lỗi.
 *  3. Đổi đĩa: `UPDATE media SET disk = 'private', conversions_disk = 'private', local_purge_after =
 *     NULL, remote_pushed_at = NULL WHERE id = ? AND disk = 'documents_remote'`. Hai cột mốc về `NULL`
 *     để không luật dọn nào còn nhắm vào dòng này (R10 điều 1 và 2). `checksum_md5`/`checksum_sha256`
 *     giữ nguyên. Câu UPDATE trên query builder: không sự kiện model, `file_name` không đổi, nên thư
 *     viện media không bao giờ gọi `move()` trên kho.
 *
 * Bản trên kho và dòng chỉ mục (kể cả `office_copied_at`) KHÔNG bị chạm: chuyển lại lần sau dùng lại
 * đúng đối tượng đó (md5 khớp, không tải lần hai), và biên nhận cũ vẫn đúng.
 *
 * Cuối lượt: MỘT audit `document_store_rollback_run` — số media đổi bằng bản cục bộ, số tải về, byte
 * tải về, số bỏ qua vì Drive không tới được, số bị khoá, số lỗi, số giây. Không danh sách tệp.
 * Không DB transaction nào: mỗi media là một lượt tải (nếu cần) và một câu UPDATE, dưới khoá của nó.
 */
final class PullDocumentsToLocal
{
    private const PAGE = 200;

    public function __construct(
        private readonly StorageReadiness $readiness,
        private readonly MaterialiseStoredFile $materialise,
    ) {}

    /**
     * @return array{
     *     status: 'not_local'|'done',
     *     local: int, downloaded: int, bytes: int, unreachable: int, locked: int,
     *     failed: array<int, string>,
     *     red_rows: list<array{key: string, level: PreflightLevel, message: string}>,
     *     seconds: int
     * }
     */
    public function handle(): array
    {
        $report = ['status' => 'done', 'local' => 0, 'downloaded' => 0, 'bytes' => 0, 'unreachable' => 0, 'locked' => 0, 'failed' => [], 'red_rows' => [], 'seconds' => 0];

        if (config('vkcrm.storage.driver') !== DocumentStore::DRIVER_LOCAL) {
            return ['status' => 'not_local'] + $report;
        }

        $started = CarbonImmutable::now();

        Setting::query()->where('key', DocumentStore::REMOTE_ENABLED_AT_KEY)->delete();

        /** @var list<array{key: string, level: PreflightLevel, message: string}>|null $downloadGate null = chưa hỏi */
        $downloadGate = null;
        $cursor = 0;

        while (true) {
            $ids = Media::query()->toBase()
                ->where('disk', DocumentStore::REMOTE_DISK)
                ->where('id', '>', $cursor)
                ->orderBy('id')
                ->limit(self::PAGE)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            foreach ($ids as $id) {
                $cursor = (int) $id;
                $lock = DocumentStore::pushLock($cursor);

                if (! $lock->get()) {
                    $report['locked']++;

                    continue;
                }

                try {
                    $this->pullOne($cursor, $downloadGate, $report);
                } finally {
                    $lock->release();
                }
            }
        }

        $report['red_rows'] = $downloadGate ?? [];
        $report['seconds'] = (int) round($started->diffInSeconds(CarbonImmutable::now(), true));

        Audit::record('document_store_rollback_run', null, [
            'local' => $report['local'],
            'downloaded' => $report['downloaded'],
            'bytes' => $report['bytes'],
            'unreachable' => $report['unreachable'],
            'locked' => $report['locked'],
            'failed' => count($report['failed']),
            'seconds' => $report['seconds'],
        ]);

        return $report;
    }

    /**
     * Một media, dưới khoá đẩy của nó.
     *
     * @param  list<array{key: string, level: PreflightLevel, message: string}>|null  $downloadGate
     * @param  array<string, mixed>  $report
     */
    private function pullOne(int $mediaId, ?array &$downloadGate, array &$report): void
    {
        $media = Media::query()->find($mediaId);

        if ($media === null || $media->disk !== DocumentStore::REMOTE_DISK) {
            return;
        }

        $key = $media->getPathRelativeToRoot();

        if ($this->localCopyMatches($media, $key)) {
            if ($this->switchToLocal($mediaId)) {
                $report['local']++;
            }

            return;
        }

        $downloadGate ??= array_values(array_filter(
            $this->readiness->downloadRows(),
            fn (array $row): bool => $row['level'] === PreflightLevel::Red,
        ));

        if ($downloadGate !== []) {
            $report['unreachable']++;

            return;
        }

        /** @var FilesystemAdapter $staging */
        $staging = DocumentStore::staging();
        $target = $staging->path($key);
        $temporary = $target.'.pull-'.Str::lower(Str::random(12));

        try {
            $this->materialise->handle($media, $temporary);

            if (! rename($temporary, $target)) {
                throw new RuntimeException(__('storage.commands.rollback.rename_failed', ['key' => $key]));
            }
        } catch (StoredFileMissing) {
            $report['failed'][$mediaId] = 'missing';

            return;
        } catch (StoredFileChanged $e) {
            // Bản trên kho đã bị đổi: chạy lại lệnh không bao giờ xong (rà soát cuối vòng sửa 1, I7).
            $report['failed'][$mediaId] = 'changed';
            Log::error(__('storage.commands.rollback.log.download_failed'), ['media_id' => $mediaId, 'key' => $key, 'exception' => $e::class]);

            return;
        } catch (DocumentStorageUnavailable|DocumentStorageMisconfigured|FilesystemException $e) {
            $report['failed'][$mediaId] = 'download_failed';
            Log::error(__('storage.commands.rollback.log.download_failed'), ['media_id' => $mediaId, 'key' => $key, 'exception' => $e::class]);

            return;
        } catch (Throwable $e) {
            report($e);
            $report['failed'][$mediaId] = 'error';

            return;
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }

        if ($this->switchToLocal($mediaId)) {
            $report['downloaded']++;
            $report['bytes'] += (int) $media->size;
        }
    }

    /**
     * Bản trong vùng đệm còn và md5 của nó bằng `media.checksum_md5`. Media đã lên kho luôn có cột đó
     * (lượt đẩy ghi nó); thiếu thì coi như không kiểm được, và đi đường tải về.
     */
    private function localCopyMatches(Media $media, string $key): bool
    {
        $expected = $media->getAttribute('checksum_md5');

        if (! is_string($expected) || $expected === '') {
            return false;
        }

        try {
            return DocumentStore::staging()->exists($key)
                && DocumentStore::staging()->checksum($key, ['checksum_algo' => 'md5']) === $expected;
        } catch (Throwable) {
            return false;
        }
    }

    private function switchToLocal(int $mediaId): bool
    {
        return Media::query()
            ->whereKey($mediaId)
            ->where('disk', DocumentStore::REMOTE_DISK)
            ->update([
                'disk' => DocumentStore::STAGING_DISK,
                'conversions_disk' => DocumentStore::STAGING_DISK,
                'local_purge_after' => null,
                'remote_pushed_at' => null,
            ]) === 1;
    }
}
