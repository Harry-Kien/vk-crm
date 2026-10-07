<?php

namespace App\Console\Commands;

use App\Actions\Storage\RebuildDriveIndex;
use Illuminate\Console\Command;

/**
 * `vkcrm:storage:reindex --drive=<id> --root=<id> [--dry-run]` (kế hoạch M14, R4, R11) — dựng lại chỉ
 * mục `drive_objects` và `drive_folders` của Shared Drive đang cấu hình từ danh sách tệp trên Drive.
 * Nghiệp vụ ở {@see RebuildDriveIndex}; lớp này in số đếm (và tên mờ của tệp cần xem) và chọn mã thoát.
 *
 * Mã thoát: 0 dựng xong (kể cả còn tệp được báo); 1 Drive không liệt kê được; 2 `--drive`/`--root`
 * khác cấu hình hay thiếu.
 */
class StorageReindexCommand extends Command
{
    protected $signature = 'vkcrm:storage:reindex
        {--drive= : Mã Shared Drive, phải bằng GOOGLE_DRIVE_SHARED_DRIVE_ID}
        {--root= : Mã thư mục gốc, phải bằng GOOGLE_DRIVE_ROOT_FOLDER_ID}
        {--dry-run : Chỉ in khác biệt, không ghi gì}';

    protected $description = 'Dựng lại chỉ mục kho tài liệu từ danh sách tệp trên Google Drive (M14)';

    public function handle(RebuildDriveIndex $rebuild): int
    {
        $report = $rebuild->handle((string) $this->option('drive'), (string) $this->option('root'), (bool) $this->option('dry-run'));

        if ($report['status'] === 'mismatch') {
            $this->error(__('storage.commands.reindex.mismatch'));

            return 2;
        }

        if ($report['status'] === 'unreachable') {
            $this->error(__('storage.commands.reindex.unreachable', ['error' => $report['error']]));

            return self::FAILURE;
        }

        $dry = $report['status'] === 'dry_run';
        $this->line(__('storage.commands.reindex.'.($dry ? 'heading_dry_run' : 'heading'), ['files' => $report['files']]));

        foreach (['created', 'revived', 'superseded', 'unchanged', 'folders_added', 'duplicate_folders', 'other_folders', 'unknown', 'probes'] as $line) {
            $this->line(__('storage.commands.reindex.'.$line, ['count' => $report[$line]]));
        }

        foreach (['no_media', 'no_checksum', 'md5_mismatch', 'not_chosen'] as $group) {
            $items = $report[$group];
            $this->line(__('storage.commands.reindex.'.$group, ['count' => count($items)]).($items === [] ? '' : ' — '.implode(', ', $items)));
        }

        return self::SUCCESS;
    }
}
