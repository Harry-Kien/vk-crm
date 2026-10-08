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

    /** Lý do hỏng mà chạy lại lệnh không sửa được (tệp mất hay bị đổi trên kho). */
    private const MANUAL_REASONS = ['missing', 'changed'];

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

        // Rà soát cuối vòng sửa 1 (I7): tệp không còn trên kho hay bản trên kho đã bị đổi thì chạy lại
        // không bao giờ xong — chỉ tới verify và Phụ lục D. Những lỗi còn lại (Drive không tới được, khoá,
        // tải về đứt) thì chạy lại được.
        $manual = array_intersect($report['failed'], self::MANUAL_REASONS) !== [];
        $retry = $report['unreachable'] > 0 || $report['locked'] > 0
            || array_diff($report['failed'], self::MANUAL_REASONS) !== [];

        if (! $incomplete) {
            $this->line(__('storage.commands.rollback.done'));
        }

        if ($retry) {
            $this->line(__('storage.commands.rollback.incomplete_retry'));
        }

        if ($manual) {
            $this->line(__('storage.commands.rollback.incomplete_manual'));
        }

        return $incomplete ? self::FAILURE : self::SUCCESS;
    }
}
