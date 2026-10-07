<?php

namespace App\Actions\Storage;

use App\Exceptions\DocumentStorageMisconfigured;
use App\Exceptions\DocumentStorageUnavailable;
use App\Models\DriveObject;
use App\Support\Scopes\ClientPortalScope;
use App\Support\Storage\DestroyedMatterMedia;
use App\Support\Storage\DocumentStore;
use App\Support\Storage\GoogleDrive\DriveApiError;
use App\Support\Storage\GoogleDrive\DriveListing;
use App\Support\Storage\GoogleDrive\DriveObjectName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Báo cáo tệp lệch giữa kho, chỉ mục và thư viện media — `vkcrm:storage:orphans` (kế hoạch M14, R4,
 * R11, R15). CHỈ BÁO CÁO: không xoá, không thùng rác, không ghi dòng nào, không lệnh ghi nào tới Drive.
 * Đây là lưới cho các đường để lại tệp lệch mà không ai biết: `DefaultFileRemover` nuốt lỗi khi xoá
 * media (vai `writer` không cho vào thùng rác được, kho sập lúc xoá), một job bị giết sau khi tải lên
 * mà trước khi ghi chỉ mục, Drive cho trùng tên.
 *
 * | Nhóm | Nguồn | Lỗi? |
 * |---|---|---|
 * | `index_without_media` | dòng chỉ mục SỐNG mà không còn media cùng mã và `file_name` (media bị xoá mà lượt thùng rác hỏng hay bị bỏ qua — rà soát Task 3, m2) | có |
 * | `media_without_file` | media ở `documents_remote` mà chỉ mục không có dòng sống của khoá nó | có |
 * | `drive_unindexed` | tệp trên Drive (danh sách thật, {@see DriveListing}) mà mã tệp không là dòng sống nào của chỉ mục | có |
 * | `duplicates` | tên xuất hiện nhiều lần trên Drive | có |
 * | `staged_orphans` | thư mục vùng đệm `private/<số>/` mà không còn dòng `media` mã đó (listener xoá vùng đệm hỏng — rà soát Task 3, m1) | có |
 * | `probes` | tệp thăm dò còn sống: dòng chỉ mục khoá `preflight/…`, hay tệp `preflight~…` trên Drive ngoài chỉ mục (rà soát Task 5, m7) | không — dữ liệu ngẫu nhiên, không phải tệp khách; người vận hành cho vào thùng rác tay |
 * | `destroyed` | media của vụ đã ghi quyết định huỷ mà không có tệp trên kho (R15) | không |
 *
 * Tên trên Drive chỉ được in khi nó là tên MỜ (đọc ngược được thành khoá, hay tên thăm dò). Tên khác —
 * do người đặt tay trên Drive, có thể mang tên khách — chỉ được ĐẾM (`*_unknown`). Không mã tệp Drive
 * nào trong kết quả.
 *
 * Drive không liệt kê được (chưa cấu hình, kho sập) → các nhóm từ chỉ mục và vùng đệm vẫn được báo,
 * `listing_failed` mang câu lỗi, và lệnh thoát 1.
 */
final class ListRemoteOrphans
{
    private const CHUNK = 500;

    /** @return array<string, mixed> */
    public function handle(): array
    {
        $report = [
            'index_without_media' => [],
            'media_without_file' => [],
            'drive_unindexed' => [],
            'drive_unindexed_unknown' => 0,
            'duplicates' => [],
            'duplicates_unknown' => 0,
            'staged_orphans' => [],
            'probes' => [],
            'destroyed' => [],
            'listing_failed' => null,
        ];

        $this->indexWithoutMedia($report);
        $this->mediaWithoutFile($report);
        $this->stagedOrphans($report);
        $this->driveListing($report);

        return $report;
    }

    /** Nhóm nào trong báo cáo là lỗi (mã thoát 1). */
    public static function hasProblems(array $report): bool
    {
        return $report['index_without_media'] !== []
            || $report['media_without_file'] !== []
            || $report['drive_unindexed'] !== [] || $report['drive_unindexed_unknown'] > 0
            || $report['duplicates'] !== [] || $report['duplicates_unknown'] > 0
            || $report['staged_orphans'] !== []
            || $report['listing_failed'] !== null;
    }

    /** @param  array<string, mixed>  $report */
    private function indexWithoutMedia(array &$report): void
    {
        $this->objects()->whereNotNull('object_key')->select(['id', 'object_key'])
            ->chunkById(self::CHUNK, function (Collection $rows) use (&$report): void {
                $keys = [];

                foreach ($rows as $row) {
                    if (str_starts_with($row->object_key, DriveObjectName::PROBE_KEY_PREFIX)) {
                        $report['probes'][] = $row->object_key;

                        continue;
                    }

                    $keys[] = $row->object_key;
                }

                $existing = $this->existingKeys($keys);

                foreach ($keys as $key) {
                    if (! isset($existing[$key])) {
                        $report['index_without_media'][] = $key;
                    }
                }
            });
    }

    /** @param  array<string, mixed>  $report */
    private function mediaWithoutFile(array &$report): void
    {
        Media::query()->where('disk', DocumentStore::REMOTE_DISK)
            ->chunkById(self::CHUNK, function (Collection $media) use (&$report): void {
                $keys = $media->mapWithKeys(fn (Media $item): array => [$item->getPathRelativeToRoot() => (int) $item->getKey()]);
                $live = $this->objects()->whereIn('object_key', $keys->keys()->all())->pluck('object_key')->flip();
                $lost = $keys->reject(fn (int $id, string $key): bool => $live->has($key))->values();
                $destroyed = DestroyedMatterMedia::among($lost);

                foreach ($lost as $id) {
                    $report[isset($destroyed[$id]) ? 'destroyed' : 'media_without_file'][] = $id;
                }
            });
    }

    /** @param  array<string, mixed>  $report */
    private function stagedOrphans(array &$report): void
    {
        $directories = array_values(array_filter(
            DocumentStore::staging()->directories(),
            fn (string $directory): bool => preg_match('/^\d+$/D', $directory) === 1,
        ));

        foreach (array_chunk($directories, self::CHUNK) as $chunk) {
            $known = DB::table('media')->whereIn('id', array_map('intval', $chunk))->pluck('id')->map(fn ($id): string => (string) $id)->flip();

            foreach ($chunk as $directory) {
                if (! $known->has($directory)) {
                    $report['staged_orphans'][] = $directory;
                }
            }
        }
    }

    /** @param  array<string, mixed>  $report */
    private function driveListing(array &$report): void
    {
        if (blank(config('vkcrm.storage.google_drive.shared_drive_id')) || blank(config('vkcrm.storage.google_drive.root_folder_id'))) {
            $report['listing_failed'] = __('storage.commands.orphans.not_configured');

            return;
        }

        try {
            $files = DriveListing::fromConfig()->scan()['files'];
        } catch (DocumentStorageUnavailable|DocumentStorageMisconfigured|DriveApiError $e) {
            $report['listing_failed'] = $e->getMessage();

            return;
        }

        $names = [];

        foreach (array_chunk($files, self::CHUNK) as $chunk) {
            $live = $this->objects()->whereNotNull('object_key')->whereIn('file_id', array_column($chunk, 'id'))->pluck('file_id')->flip();

            foreach ($chunk as $file) {
                $names[$file['name']] = ($names[$file['name']] ?? 0) + 1;

                if ($live->has($file['id'])) {
                    continue;
                }

                if (str_starts_with($file['name'], DriveObjectName::PROBE_NAME_PREFIX)) {
                    $report['probes'][] = $file['name'];
                } elseif (DriveObjectName::parse($file['name']) !== null) {
                    $report['drive_unindexed'][] = $file['name'];
                } else {
                    $report['drive_unindexed_unknown']++;
                }
            }
        }

        foreach ($names as $name => $count) {
            if ($count < 2) {
                continue;
            }

            if (DriveObjectName::parse((string) $name) !== null || str_starts_with((string) $name, DriveObjectName::PROBE_NAME_PREFIX)) {
                $report['duplicates'][] = (string) $name;
            } else {
                $report['duplicates_unknown']++;
            }
        }
    }

    /**
     * Khoá nào trong danh sách còn dòng `media` cùng mã VÀ cùng `file_name`.
     *
     * @param  list<string>  $keys
     * @return array<string, true>
     */
    private function existingKeys(array $keys): array
    {
        $wanted = [];

        foreach ($keys as $key) {
            $parts = explode('/', $key, 2);

            if (count($parts) === 2 && ctype_digit($parts[0])) {
                $wanted[(int) $parts[0]][] = $parts[1];
            }
        }

        $existing = [];

        foreach (DB::table('media')->whereIn('id', array_keys($wanted))->get(['id', 'file_name']) as $row) {
            if (in_array($row->file_name, $wanted[(int) $row->id], true)) {
                $existing[$row->id.'/'.$row->file_name] = true;
            }
        }

        return $existing;
    }

    /** Chỉ mục, BỎ `ClientPortalScope` như mọi mã của kho (docblock `DriveObjectIndex::query()`). */
    private function objects(): Builder
    {
        return DriveObject::query()->withoutGlobalScope(ClientPortalScope::class);
    }
}
