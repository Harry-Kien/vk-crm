<?php

namespace App\Console\Commands;

use App\Actions\Backup\CheckBackupDestinations;
use Illuminate\Console\Command;

/**
 * `vkcrm:backup-check` (M8a Task 2) — kiểm từng đích sao lưu (mọi disk trong `BACKUP_DISKS`, cộng
 * đích rclone khi đã cấu hình). Nghiệp vụ nằm ở {@see CheckBackupDestinations}; lớp này chỉ định
 * dạng và in kết quả bằng tiếng Việt, và quyết định mã thoát.
 *
 * Mã thoát khác 0 nếu BẤT KỲ đích nào kiểm hỏng (kể cả một `{target}` gõ sai — Action trả về đúng
 * một kết quả `ok: false` cho trường hợp đó, nên vòng lặp bên dưới không cần một nhánh riêng).
 */
class BackupCheckCommand extends Command
{
    protected $signature = 'vkcrm:backup-check {target? : Tên một disk trong BACKUP_DISKS, hoặc "rclone" cho đích Google Drive}';

    protected $description = 'Kiểm tra từng đích sao lưu bằng cách ghi/đọc/xoá thử một tệp nhỏ';

    public function handle(CheckBackupDestinations $action): int
    {
        $results = $action->handle($this->argument('target'));

        if ($results === []) {
            $this->error(__('backup.check.no_targets'));

            return self::FAILURE;
        }

        $allOk = true;

        foreach ($results as $result) {
            if ($result['ok']) {
                $this->info($result['message']);
            } else {
                $this->error($result['message']);
                $allOk = false;
            }
        }

        $this->newLine();

        if (! $allOk) {
            $this->error(__('backup.check.summary_failed'));

            return self::FAILURE;
        }

        $this->info(__('backup.check.summary_ok'));

        return self::SUCCESS;
    }
}
