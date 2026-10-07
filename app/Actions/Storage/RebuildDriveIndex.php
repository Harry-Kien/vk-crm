<?php

namespace App\Actions\Storage;

use App\Enums\DriveObjectRetirement;
use App\Exceptions\DocumentStorageMisconfigured;
use App\Exceptions\DocumentStorageUnavailable;
use App\Models\DriveFolder;
use App\Models\DriveObject;
use App\Support\Audit;
use App\Support\Scopes\ClientPortalScope;
use App\Support\Storage\GoogleDrive\DriveApiError;
use App\Support\Storage\GoogleDrive\DriveListing;
use App\Support\Storage\GoogleDrive\DriveObjectName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Dựng lại chỉ mục `drive_objects` của MỘT Shared Drive từ danh sách tệp thật trên Drive —
 * `vkcrm:storage:reindex --drive=<id> --root=<id> [--dry-run]` (kế hoạch M14, R4, R11). Dùng khi chỉ
 * mục mất hay lệch (khôi phục CSDL từ bản sao lưu cũ), hay khi chuyển sang một Shared Drive mới.
 *
 * # Cổng
 *
 * `--drive`/`--root` phải bằng `GOOGLE_DRIVE_SHARED_DRIVE_ID`/`GOOGLE_DRIVE_ROOT_FOLDER_ID` đang cấu
 * hình (đọc qua `config()`); khác (hay cấu hình trống) → `mismatch`, không request nào. Không ai dựng
 * chỉ mục cho một drive mà ứng dụng không dùng: đổi `.env` và chạy `optimize` trước.
 *
 * # Từ tên tới dòng
 *
 * Danh sách đọc bằng {@see DriveListing} (gốc + thư mục tháng, chỉ tệp chưa vào thùng rác). Mỗi tên
 * đọc ngược bằng {@see DriveObjectName::parse()} ra khoá `<media_id>/<file_name>` và thế hệ; với mỗi
 * khoá:
 *
 * - không có dòng `media` cùng mã VÀ cùng `file_name` → báo `no_media`, không ghi (`orphans` là nơi
 *   xem kỹ);
 * - media không có `checksum_md5` để so → báo `no_checksum`, không chọn;
 * - không tệp nào có md5 bằng `media.checksum_md5` → báo `md5_mismatch`, không ghi;
 * - nếu không: chọn tệp khớp md5 có THẾ HỆ CAO NHẤT. Mọi tệp khác của khoá (thế hệ khác, hay trùng tên
 *   cùng thế hệ — một job bị giết sau khi tải lên mà trước khi ghi chỉ mục) được báo (`not_chosen`),
 *   không ghi, không xoá.
 *
 * Tệp được chọn so với dòng SỐNG hiện có của khoá (trên bất kỳ Shared Drive nào):
 * - đúng drive này và đúng mã tệp → `unchanged`;
 * - còn lại → dòng cũ rời chỉ mục sống với `retired_reason = superseded` (`object_key = NULL`,
 *   `former_key` giữ khoá) rồi dòng mới được ghi, trong MỘT transaction chỉ có SQL. Bản trước đụng
 *   unique `object_key` ở đúng bước này. Mã tệp đã có một dòng ĐÃ RỜI (tệp được Manager lấy lại từ thùng
 *   rác) → dòng đó được đưa lại vào chỉ mục sống thay cho một dòng mới (unique `file_id`), giữ
 *   `office_copied_at` của nó: cùng một tệp, cùng biên nhận.
 *
 * Tên không đọc ngược được: tệp thăm dò `preflight~…` (kiểm tra sẵn sàng, đo tốc độ của `migrate`) đếm
 * riêng (`probes`); tên khác đếm `unknown` — CHỈ số đếm: tên do người đặt tay trên Drive có thể mang
 * tên khách, và lệnh không in thứ gì ngoài tên mờ.
 *
 * `drive_folders`: mỗi thư mục tháng `<YYYY-MM>` dưới gốc mà bảng chưa có `(root_folder_id, name)` thì
 * được ghi, để lượt đẩy sau không tạo một thư mục tháng thứ hai cùng tên. Hai thư mục cùng tên trên
 * Drive → giữ cái gặp trước (hoặc cái bảng đã có), báo số còn lại.
 *
 * `--dry-run`: cùng phép tính, không ghi gì. Không bao giờ xoá dòng nào; không bao giờ gửi lệnh ghi nào
 * tới Drive. Lượt thật ghi MỘT audit `drive_index_rebuilt` với các số đếm.
 */
final class RebuildDriveIndex
{
    private const MEDIA_CHUNK = 500;

    /** @return array<string, mixed> */
    public function handle(string $driveId, string $rootFolderId, bool $dryRun = false): array
    {
        $configuredDrive = (string) config('vkcrm.storage.google_drive.shared_drive_id');
        $configuredRoot = (string) config('vkcrm.storage.google_drive.root_folder_id');

        if ($configuredDrive === '' || $configuredRoot === '' || $driveId !== $configuredDrive || $rootFolderId !== $configuredRoot) {
            return ['status' => 'mismatch'];
        }

        try {
            $scan = DriveListing::fromConfig()->scan();
        } catch (DocumentStorageUnavailable|DocumentStorageMisconfigured|DriveApiError $e) {
            return ['status' => 'unreachable', 'error' => $e->getMessage()];
        }

        $report = [
            'status' => $dryRun ? 'dry_run' : 'done',
            'files' => count($scan['files']),
            'created' => 0, 'superseded' => 0, 'revived' => 0, 'unchanged' => 0,
            'folders_added' => 0, 'duplicate_folders' => 0, 'other_folders' => count($scan['other_folders']),
            'unknown' => 0, 'probes' => 0,
            'no_media' => [], 'no_checksum' => [], 'md5_mismatch' => [], 'not_chosen' => [],
        ];

        $folders = $this->planFolders($scan['folders'], $driveId, $rootFolderId, $report);
        $plans = $this->planObjects($scan['files'], $driveId, $report);

        if (! $dryRun) {
            foreach ($folders as $folder) {
                $this->folders()->create($folder);
            }

            foreach ($plans as $plan) {
                $this->apply($plan, $driveId);
            }

            Audit::record('drive_index_rebuilt', null, collect($report)
                ->except(['status'])
                ->map(fn ($value) => is_array($value) ? count($value) : $value)
                ->all());
        }

        return $report;
    }

    /**
     * Thư mục tháng cần ghi vào `drive_folders`.
     *
     * @param  list<array{id: string, name: string}>  $folders
     * @param  array<string, mixed>  $report
     * @return list<array{drive_id: string, root_folder_id: string, name: string, folder_id: string}>
     */
    private function planFolders(array $folders, string $driveId, string $rootFolderId, array &$report): array
    {
        $known = $this->folders()->where('root_folder_id', $rootFolderId)->pluck('folder_id', 'name')->all();
        $plan = [];

        foreach ($folders as $folder) {
            if (isset($known[$folder['name']])) {
                if ($known[$folder['name']] !== $folder['id']) {
                    $report['duplicate_folders']++;
                }

                continue;
            }

            $known[$folder['name']] = $folder['id'];
            $report['folders_added']++;
            $plan[] = ['drive_id' => $driveId, 'root_folder_id' => $rootFolderId, 'name' => $folder['name'], 'folder_id' => $folder['id']];
        }

        return $plan;
    }

    /**
     * Mỗi khoá: tệp được chọn và dòng sống cũ phải nhường chỗ (nếu có).
     *
     * @param  list<array{id: string, name: string, size: int, md5: ?string, mime: ?string, parent: string}>  $files
     * @param  array<string, mixed>  $report
     * @return list<array{key: string, generation: int, file: array<string, mixed>, existing: ?DriveObject, revive: ?DriveObject}>
     */
    private function planObjects(array $files, string $driveId, array &$report): array
    {
        $byKey = [];

        foreach ($files as $file) {
            $parsed = DriveObjectName::parse($file['name']);

            if ($parsed === null) {
                $report[str_starts_with($file['name'], DriveObjectName::PROBE_NAME_PREFIX) ? 'probes' : 'unknown']++;

                continue;
            }

            $byKey[$parsed['key']][] = $file + ['generation' => $parsed['generation']];
        }

        $media = $this->mediaFor(array_keys($byKey));
        $plans = [];

        foreach ($byKey as $key => $candidates) {
            $row = $media[$key] ?? null;

            if ($row === null) {
                $report['no_media'][] = $key;

                continue;
            }

            if (! is_string($row->checksum_md5) || $row->checksum_md5 === '') {
                $report['no_checksum'][] = $key;

                continue;
            }

            $matching = array_values(array_filter($candidates, fn (array $file): bool => $file['md5'] === $row->checksum_md5));

            if ($matching === []) {
                $report['md5_mismatch'][] = $key;

                continue;
            }

            usort($matching, fn (array $a, array $b): int => $b['generation'] <=> $a['generation']);
            $chosen = $matching[0];

            foreach ($candidates as $candidate) {
                if ($candidate['id'] !== $chosen['id']) {
                    $report['not_chosen'][] = DriveObjectName::fromKey($key, $candidate['generation']);
                }
            }

            $existing = $this->objects()->where('object_key', $key)->first();

            if ($existing !== null && $existing->drive_id === $driveId && $existing->file_id === $chosen['id']) {
                $report['unchanged']++;

                continue;
            }

            $revive = $this->objects()->where('file_id', $chosen['id'])->whereNull('object_key')->first();
            $report[$revive === null ? 'created' : 'revived']++;

            if ($existing !== null) {
                $report['superseded']++;
            }

            $plans[] = ['key' => $key, 'generation' => $chosen['generation'], 'file' => $chosen, 'existing' => $existing, 'revive' => $revive];
        }

        return $plans;
    }

    /** @param  array{key: string, generation: int, file: array<string, mixed>, existing: ?DriveObject, revive: ?DriveObject}  $plan */
    private function apply(array $plan, string $driveId): void
    {
        $attributes = [
            'drive_id' => $driveId,
            'object_key' => $plan['key'],
            'generation' => $plan['generation'],
            'file_id' => $plan['file']['id'],
            'parent_id' => $plan['file']['parent'],
            'size' => $plan['file']['size'],
            'md5' => $plan['file']['md5'],
            'mime_type' => $plan['file']['mime'],
        ];

        DB::transaction(function () use ($plan, $attributes): void {
            if ($plan['existing'] !== null) {
                $this->objects()
                    ->whereKey($plan['existing']->getKey())
                    ->where('object_key', $plan['key'])
                    ->update([
                        'object_key' => null,
                        'former_key' => $plan['key'],
                        'retired_reason' => DriveObjectRetirement::Superseded->value,
                        'retired_at' => now(),
                    ]);
            }

            if ($plan['revive'] !== null) {
                $this->objects()->whereKey($plan['revive']->getKey())->update($attributes + [
                    'former_key' => null,
                    'retired_reason' => null,
                    'retired_at' => null,
                ]);

                return;
            }

            $this->objects()->create($attributes);
        });
    }

    /**
     * Dòng `media` của các khoá (`<id>/<file_name>`), theo khoá. Khoá chỉ khớp khi CẢ mã lẫn `file_name`
     * trùng.
     *
     * @param  list<string>  $keys
     * @return array<string, object>
     */
    private function mediaFor(array $keys): array
    {
        $wanted = [];

        foreach ($keys as $key) {
            [$id, $fileName] = explode('/', $key, 2);
            $wanted[(int) $id] = $fileName;
        }

        $found = [];

        foreach (array_chunk(array_keys($wanted), self::MEDIA_CHUNK) as $ids) {
            foreach (DB::table('media')->whereIn('id', $ids)->get(['id', 'file_name', 'checksum_md5']) as $row) {
                if ($wanted[(int) $row->id] === $row->file_name) {
                    $found[$row->id.'/'.$row->file_name] = $row;
                }
            }
        }

        return $found;
    }

    /** Chỉ mục, BỎ `ClientPortalScope` như mọi mã của kho (docblock `DriveObjectIndex::query()`). */
    private function objects(): Builder
    {
        return DriveObject::query()->withoutGlobalScope(ClientPortalScope::class);
    }

    private function folders(): Builder
    {
        return DriveFolder::query()->withoutGlobalScope(ClientPortalScope::class);
    }
}
