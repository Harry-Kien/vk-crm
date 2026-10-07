<?php

namespace App\Console\Commands;

use App\Actions\Storage\PullDocumentsToLocal;
use Illuminate\Console\Command;

/**
 * `vkcrm:storage:rollback` (kế hoạch M14, R11; Phụ lục C bước 11) — kéo mọi tệp trên kho về máy chủ.
 * Nghiệp vụ ở {@see PullDocumentsToLocal}; lớp này in câu tiếng Việt và chọn mã thoát.
 *
 * Mã thoát: 0 xong hết; 1 còn media chưa kéo về được (Drive không tới được, tải về hỏng hay lệch md5,
 * media đang bị khoá đẩy) — chạy lại khi Drive tới được; 2 khi công tắc chưa là `local` (không đổi gì).
 */
class StorageRollbackCommand extends Command
{
    protected $signature = 'vkcrm:storage:rollback';

    protected $description = 'Quay lui: kéo mọi tệp trên kho Google Drive về máy chủ (M14)';

    public function handle(PullDocumentsToLocal $pull): int
    {
        $report = $pull->handle();

        if ($report['status'] === 'not_local') {
            $this->error(__('storage.commands.rollback.not_local'));

            return 2;
        }

        $this->info(__('storage.commands.rollback.local', ['count' => $report['local']]));
        $this->info(__('storage.commands.rollback.downloaded', ['count' => $report['downloaded'], 'bytes' => StorageMigrateCommand::bytes($report['bytes'])]));

        if ($report['unreachable'] > 0) {
            $this->error(__('storage.commands.rollback.unreachable', ['count' => $report['unreachable']]));

            foreach ($report['red_rows'] as $row) {
                $this->line('  ['.$row['level']->label().'] '.$row['message']);
            }
        }

        if ($report['locked'] > 0) {
            $this->warn(__('storage.commands.rollback.locked', ['count' => $report['locked']]));
        }

        if ($report['failed'] !== []) {
            $this->error(__('storage.commands.rollback.failed', ['count' => count($report['failed'])]));

            foreach ($report['failed'] as $mediaId => $reason) {
                $this->line('  #'.$mediaId.': '.__('storage.commands.reasons.'.$reason));
            }
        }

        $incomplete = $report['unreachable'] > 0 || $report['locked'] > 0 || $report['failed'] !== [];

        $this->line($incomplete ? __('storage.commands.rollback.incomplete') : __('storage.commands.rollback.done'));

        return $incomplete ? self::FAILURE : self::SUCCESS;
    }
}
