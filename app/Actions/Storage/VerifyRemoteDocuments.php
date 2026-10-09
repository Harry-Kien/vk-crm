<?php

namespace App\Actions\Storage;

use App\Enums\DriveObjectRetirement;
use App\Exceptions\DocumentStorageMisconfigured;
use App\Exceptions\DocumentStorageUnavailable;
use App\Exceptions\StoredFileMissing;
use App\Exceptions\StoredFileTrashed;
use App\Models\DriveObject;
use App\Support\Scopes\ClientPortalScope;
use App\Support\Storage\DestroyedMatterMedia;
use App\Support\Storage\DocumentStore;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Collection;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToProvideChecksum;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Kiểm bản trên kho của media đã lên kho — `vkcrm:storage:verify [--all|--sample=N]` (kế hoạch M14,
 * R11, R15; Phụ lục C bước 8). CHỈ ĐỌC: không ghi, không thùng rác, không đổi dòng nào.
 *
 * Với mỗi media ở `documents_remote` (mọi media, hay N media chọn ngẫu nhiên):
 *
 * | Nhóm | Khi |
 * |---|---|
 * | `ok` | md5 do KHO tính (Drive: `md5Checksum` hỏi Google, không đọc chỉ mục) bằng `media.checksum_md5`, và cỡ trên kho bằng `media.size` |
 * | `changed` | lệch md5 hay cỡ: bản trên kho đã bị đổi (phiên bản mới ngoài CRM) |
 * | `trashed` | Google báo tệp đang ở thùng rác ({@see StoredFileTrashed}); hoặc chỉ mục không còn dòng sống của khoá mà có dòng đã rời vì `trashed` — CRM đã cho nó vào thùng rác trong khi dòng `media` còn (lượt xoá media rollback sau khi thư viện media gọi thùng rác) |
 * | `missing` | Google trả 404 ({@see StoredFileMissing}), hay chỉ mục không có khoá và không có dấu vết thùng rác |
 * | `failed` | không kiểm được: kho không tới được, cấu hình hỏng, dòng `media` thiếu `checksum_md5` |
 * | `destroyed` | media của một vụ đã ghi quyết định huỷ ({@see DestroyedMatterMedia}) mà KHÔNG `ok`: nhóm riêng, không tính là lỗi (R15) |
 *
 * Tệp thăm dò `preflight/<…>.txt` của kiểm tra sẵn sàng không là media, nên không bao giờ vào đây; dòng
 * chỉ mục đã rời của nó mang `former_key` bắt đầu bằng `preflight/`, không trùng khoá media nào.
 *
 * Kết quả chỉ mang mã media (không khoá, không tên, không mã tệp Drive).
 */
final class VerifyRemoteDocuments
{
    public const DEFAULT_SAMPLE = 100;

    private const CHUNK = 200;

    /**
     * @param  int|null  $sample  null = mọi media trên kho
     * @return array{total: int, checked: int, ok: int, changed: list<int>, trashed: list<int>, missing: list<int>, failed: list<int>, destroyed: list<int>}
     */
    public function handle(?int $sample = self::DEFAULT_SAMPLE): array
    {
        $report = ['total' => 0, 'checked' => 0, 'ok' => 0, 'changed' => [], 'trashed' => [], 'missing' => [], 'failed' => [], 'destroyed' => []];
        $report['total'] = Media::query()->where('disk', DocumentStore::REMOTE_DISK)->count();

        $query = Media::query()->where('disk', DocumentStore::REMOTE_DISK);

        if ($sample === null) {
            $query->chunkById(self::CHUNK, function (Collection $chunk) use (&$report): void {
                $this->verifyChunk($chunk, $report);
            });
        } else {
            $this->verifyChunk($query->inRandomOrder()->limit(max(0, $sample))->get(), $report);
        }

        return $report;
    }

    /**
     * @param  Collection<int, Media>  $chunk
     * @param  array<string, mixed>  $report
     */
    private function verifyChunk(Collection $chunk, array &$report): void
    {
        $destroyed = DestroyedMatterMedia::among($chunk->map(fn (Media $media): int => (int) $media->getKey()));

        foreach ($chunk as $media) {
            $report['checked']++;
            $group = $this->classify($media);
            $id = (int) $media->getKey();

            if ($group === 'ok') {
                $report['ok']++;
            } elseif (isset($destroyed[$id])) {
                $report['destroyed'][] = $id;
            } else {
                $report[$group][] = $id;
            }
        }
    }

    /** @return 'ok'|'changed'|'trashed'|'missing'|'failed' */
    private function classify(Media $media): string
    {
        /** @var FilesystemAdapter $remote */
        $remote = DocumentStore::remote();
        $key = $media->getPathRelativeToRoot();

        try {
            if (! $remote->fileExists($key)) {
                return $this->trashedByCrm($key) ? 'trashed' : 'missing';
            }

            $md5 = $remote->checksum($key, ['checksum_algo' => 'md5']);
            $size = $remote->size($key);
        } catch (UnableToProvideChecksum $e) {
            return match (true) {
                $e->getPrevious() instanceof StoredFileTrashed => 'trashed',
                $e->getPrevious() instanceof StoredFileMissing => 'missing',
                default => 'failed',
            };
        } catch (DocumentStorageUnavailable|DocumentStorageMisconfigured|FilesystemException) {
            return 'failed';
        }

        $expected = $media->getAttribute('checksum_md5');

        if (! is_string($expected) || $expected === '') {
            return 'failed';
        }

        return $md5 === $expected && $size === (int) $media->size ? 'ok' : 'changed';
    }

    /** Khoá không còn dòng sống, mà có dòng đã rời vì CRM cho tệp vào thùng rác. */
    private function trashedByCrm(string $key): bool
    {
        return DriveObject::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->where('former_key', $key)
            ->where('retired_reason', DriveObjectRetirement::Trashed->value)
            ->exists();
    }
}
