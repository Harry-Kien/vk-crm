<?php

namespace App\Actions\Storage;

use App\Enums\PreflightLevel;
use App\Enums\PushOutcome;
use App\Exceptions\DocumentStorageMisconfigured;
use App\Exceptions\DocumentStorageUnavailable;
use App\Exceptions\StoredFileMissing;
use App\Support\Audit;
use App\Support\Files\FreeSpace;
use App\Support\Storage\DocumentStore;
use App\Support\Storage\GoogleDrive\DriveDiagnosticClient;
use App\Support\Storage\GoogleDrive\DriveObjectName;
use App\Support\Storage\TransferDossier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use League\Flysystem\FilesystemException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Chuyển tệp CŨ (media còn ở vùng đệm `private`) lên kho — `vkcrm:storage:migrate` (kế hoạch M14,
 * R11; sổ tay Phụ lục C bước 5 và 7 trong `docs/KHO-TAI-LIEU-GOOGLE-DRIVE.md`).
 *
 * # Một luật đẩy duy nhất
 *
 * Mỗi media đi qua ĐÚNG {@see PushDocumentFileToRemote} — cùng Action với job của tệp mới, cùng khoá
 * đẩy, cùng kiểm md5 do kho tính, cùng câu UPDATE có điều kiện. Không có bản thứ hai của luật kiểm.
 * Vì thế lệnh chạy lại và chạy tiếp được tự nhiên: trạng thái nằm ở `media.disk`. Một tệp đã lên kho từ
 * lượt trước mà chưa kịp đổi đĩa (chỉ mục có khoá, md5 khớp) không bị tải lần hai; md5 lệch thì bản cũ
 * vào thùng rác và tải lại với thế hệ kế tiếp (R4).
 *
 * # Chạy thật
 *
 * - Từ chối (`not_enabled`) khi kho chưa BẬT (`DocumentStore::pushesNewFiles()` sai: công tắc chưa là
 *   `google_drive`, hay `vkcrm:storage:enable` chưa chạy); từ chối (`not_ready`) khi
 *   {@see StorageReadiness::rows()} có dòng ĐỎ; từ chối (`dossier_missing`) trên production khi cổng
 *   pháp lý R13 đóng sau lúc bật ({@see TransferDossier::allowsTransfer()} sai — dòng hồ sơ thuộc
 *   `stateRows()`, không thuộc `rows()`; rà soát cuối vòng sửa 1, I6). Không ghi gì.
 * - Media ở `private`, từ id nhỏ tới lớn (đọc theo trang 200 id, cursor là id cuối đã qua: một media
 *   vừa rời `private` không làm lệch trang).
 * - Trước MỖI media: đã đủ `--limit` media thì dừng (`limit`); đã quá `--max-minutes` kể từ lúc bắt đầu
 *   thì dừng (`time`). Một media đang tải dở không bị cắt ngang.
 * - Bản cục bộ được giữ ít nhất `--keep-local-days` ngày (mặc định
 *   {@see self::DEFAULT_KEEP_LOCAL_DAYS}) tính từ lúc đẩy (`local_purge_after`): quay lui trong khoảng
 *   đó không phải tải về. Sau đó nó còn phải chờ biên nhận văn phòng (R10).
 * - Kết quả từng media: `Pushed` → đếm tệp và byte (`media.size`); `AlreadyRemote`/`Gone` → bỏ qua;
 *   `Locked` (job của chính media đó đang đẩy) → đếm riêng, lượt sau thấy nó đã ở kho; `Rejected`, tệp
 *   vùng đệm mất ({@see StoredFileMissing}), bản trên kho lệch md5 hay lỗi Flysystem khác → ghi vào
 *   danh sách lỗi (mã media → mã lý do) rồi đi tiếp media sau.
 * - Kho không tới được hoặc cấu hình hỏng ({@see DocumentStorageUnavailable},
 *   {@see DocumentStorageMisconfigured}) → DỪNG cả lượt (`unavailable`): mọi media sau cũng sẽ hỏng, và
 *   chạy tiếp chỉ đốt thời gian của cửa sổ ngoài giờ. Kho bị tắt giữa chừng (`Disabled`) → dừng
 *   (`disabled`).
 * - Cuối lượt: MỘT audit `document_store_migration_run` — số tệp, byte, bỏ qua, bị khoá, số lỗi, số
 *   giây, lý do dừng. Không danh sách tệp.
 *
 * # Chạy thử (`--dry-run`)
 *
 * Không cần kho đã bật (Phụ lục C chạy nó TRƯỚC `enable`). Không ghi gì vào CSDL, không đĩa kho, không
 * dòng chỉ mục, không audit. Trả số media ở `private` và tổng byte, chỗ trống ở gốc vùng đệm
 * (`FreeSpace`, `null` khi không đo được), hạn mức tải lên
 * {@see self::DAILY_UPLOAD_QUOTA_BYTES} mỗi ngày của Google (tài khoản dịch vụ là một người dùng), và
 * tốc độ đo bằng MỘT tệp thăm dò {@see self::PROBE_BYTES} byte ngẫu nhiên: tải thẳng bằng client chẩn
 * đoán vào thư mục gốc dưới tên `preflight~<26 ký tự>.txt` (cùng tiền tố với tệp thăm dò của kiểm tra
 * sẵn sàng, để `orphans`/`reindex` nhận ra nó), rồi cho vào thùng rác. Không đi qua adapter, nên không
 * dòng chỉ mục nào. Kho chưa cấu hình, hay lần đo hỏng → tốc độ `null` (lệnh in "không đo được").
 *
 * Không DB transaction nào ở đây; mọi I/O kho nằm trong Action đẩy (R2).
 */
final class MigrateDocumentsToRemote
{
    public const DEFAULT_KEEP_LOCAL_DAYS = 30;

    /** Hạn mức tải lên mỗi ngày cho mỗi người dùng của Google Drive (Drive API, "Usage limits"). */
    public const DAILY_UPLOAD_QUOTA_BYTES = 750_000_000_000;

    public const PROBE_BYTES = 1048576;

    private const PAGE = 200;

    public function __construct(
        private readonly StorageReadiness $readiness,
        private readonly PushDocumentFileToRemote $push,
        private readonly FreeSpace $freeSpace,
    ) {}

    /**
     * @return array{files: int, bytes: int, free_bytes: ?int, bytes_per_second: ?float, estimated_seconds: ?int, quota_bytes: int}
     */
    public function dryRun(): array
    {
        $pending = Media::query()->toBase()
            ->where('disk', DocumentStore::STAGING_DISK)
            ->selectRaw('count(*) as files, coalesce(sum(size), 0) as bytes')
            ->first();

        $bytes = (int) $pending->bytes;
        $speed = $this->measureSpeed();

        return [
            'files' => (int) $pending->files,
            'bytes' => $bytes,
            'free_bytes' => $this->freeSpace->bytes((string) config('filesystems.disks.private.root')),
            'bytes_per_second' => $speed,
            'estimated_seconds' => $speed === null ? null : (int) ceil($bytes / $speed),
            'quota_bytes' => self::DAILY_UPLOAD_QUOTA_BYTES,
        ];
    }

    /**
     * @return array{
     *     status: 'not_enabled'|'not_ready'|'dossier_missing'|'done'|'limit'|'time'|'unavailable'|'disabled',
     *     red_rows: list<array{key: string, level: PreflightLevel, message: string}>,
     *     pushed: int, bytes: int, skipped: int, locked: int, failed: array<int, string>,
     *     remaining: int, seconds: int
     * }
     */
    public function handle(?int $limit = null, ?int $maxMinutes = null, int $keepLocalDays = self::DEFAULT_KEEP_LOCAL_DAYS): array
    {
        $report = ['status' => 'done', 'red_rows' => [], 'pushed' => 0, 'bytes' => 0, 'skipped' => 0, 'locked' => 0, 'failed' => [], 'remaining' => 0, 'seconds' => 0];

        if (! DocumentStore::pushesNewFiles()) {
            return ['status' => 'not_enabled'] + $report;
        }

        $red = array_values(array_filter($this->readiness->rows(), fn (array $row): bool => $row['level'] === PreflightLevel::Red));

        if ($red !== []) {
            return ['status' => 'not_ready', 'red_rows' => $red] + $report;
        }

        if (TransferDossier::appliesHere() && ! TransferDossier::current()->allowsTransfer()) {
            return ['status' => 'dossier_missing'] + $report;
        }

        $started = CarbonImmutable::now();
        $deadline = $maxMinutes === null ? null : $started->addMinutes($maxMinutes);
        $attempted = 0;
        $cursor = 0;

        while (true) {
            $page = Media::query()->toBase()
                ->where('disk', DocumentStore::STAGING_DISK)
                ->where('id', '>', $cursor)
                ->orderBy('id')
                ->limit(self::PAGE)
                ->get(['id', 'size']);

            if ($page->isEmpty()) {
                break;
            }

            foreach ($page as $media) {
                $cursor = (int) $media->id;

                if ($limit !== null && $attempted >= $limit) {
                    $report['status'] = 'limit';

                    break 2;
                }

                if ($deadline !== null && CarbonImmutable::now()->greaterThanOrEqualTo($deadline)) {
                    $report['status'] = 'time';

                    break 2;
                }

                $attempted++;
                $stop = $this->pushOne($cursor, (int) $media->size, $keepLocalDays, $report);

                if ($stop !== null) {
                    $report['status'] = $stop;

                    break 2;
                }
            }
        }

        $report['remaining'] = Media::query()->toBase()->where('disk', DocumentStore::STAGING_DISK)->count();
        $report['seconds'] = (int) round($started->diffInSeconds(CarbonImmutable::now(), true));

        Audit::record('document_store_migration_run', null, [
            'pushed' => $report['pushed'],
            'bytes' => $report['bytes'],
            'skipped' => $report['skipped'],
            'locked' => $report['locked'],
            'failed' => count($report['failed']),
            'seconds' => $report['seconds'],
            'stopped' => $report['status'],
        ]);

        return $report;
    }

    /**
     * Đẩy MỘT media và cộng vào báo cáo. Trả lý do dừng cả lượt, hoặc `null` để đi tiếp.
     *
     * @param  array<string, mixed>  $report
     */
    private function pushOne(int $mediaId, int $size, int $keepLocalDays, array &$report): ?string
    {
        try {
            $outcome = $this->push->handle($mediaId, CarbonImmutable::now()->addDays($keepLocalDays));
        } catch (DocumentStorageUnavailable|DocumentStorageMisconfigured $e) {
            $report['failed'][$mediaId] = $e instanceof DocumentStorageMisconfigured ? 'misconfigured' : 'unavailable';
            Log::error(__('storage.commands.migrate.log.store_failed'), ['media_id' => $mediaId, 'exception' => $e::class]);

            return 'unavailable';
        } catch (StoredFileMissing) {
            $report['failed'][$mediaId] = 'staged_missing';

            return null;
        } catch (FilesystemException $e) {
            $report['failed'][$mediaId] = 'store_rejected';
            Log::error(__('storage.commands.migrate.log.push_failed'), ['media_id' => $mediaId, 'exception' => $e::class]);

            return null;
        } catch (Throwable $e) {
            report($e);
            $report['failed'][$mediaId] = 'error';

            return null;
        }

        switch ($outcome) {
            case PushOutcome::Pushed:
                $report['pushed']++;
                $report['bytes'] += $size;

                return null;
            case PushOutcome::AlreadyRemote:
            case PushOutcome::Gone:
                $report['skipped']++;

                return null;
            case PushOutcome::Locked:
                $report['locked']++;

                return null;
            case PushOutcome::Rejected:
                $report['failed'][$mediaId] = 'rejected';

                return null;
            case PushOutcome::Disabled:
                return 'disabled';
        }

        return null;
    }

    /** Byte mỗi giây của một lượt tải tệp thăm dò lên kho, hoặc `null` khi không đo được. */
    private function measureSpeed(): ?float
    {
        $root = (string) config('vkcrm.storage.google_drive.root_folder_id');

        if ($root === '' || blank(config('vkcrm.storage.google_drive.credentials_path')) || blank(config('vkcrm.storage.google_drive.shared_drive_id'))) {
            return null;
        }

        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, random_bytes(self::PROBE_BYTES));
        rewind($stream);

        try {
            $client = DriveDiagnosticClient::make();
            $started = hrtime(true);
            $file = $client->upload($root, DriveObjectName::PROBE_NAME_PREFIX.Str::lower(Str::random(26)).'.txt', 'text/plain', $stream, self::PROBE_BYTES);
            $seconds = (hrtime(true) - $started) / 1e9;
        } catch (Throwable $e) {
            Log::warning(__('storage.commands.migrate.log.probe_failed'), ['exception' => $e::class]);

            return null;
        } finally {
            fclose($stream);
        }

        try {
            $client->trash((string) $file['id']);
        } catch (Throwable $e) {
            Log::warning(__('storage.commands.migrate.log.probe_trash_failed'), ['exception' => $e::class]);
        }

        return self::PROBE_BYTES / max($seconds, 0.001);
    }
}
