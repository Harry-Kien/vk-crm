<?php

namespace App\Console\Commands;

use App\Actions\Storage\EnableRemoteDocumentStore;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * `vkcrm:storage:enable` (kế hoạch M14, R2, R11, R13; Phụ lục C bước 6) — BẬT kho tài liệu: ghi mốc
 * `settings.storage.remote_enabled_at`. Nghiệp vụ ở {@see EnableRemoteDocumentStore}; lớp này chỉ in
 * câu tiếng Việt và chọn mã thoát.
 *
 * Mã thoát: 0 khi bật xong, hoặc kho đã bật từ trước (in mốc cũ, không dời); 2 khi một điều kiện tiên
 * quyết không đạt (công tắc chưa là `google_drive`, kiểm tra sẵn sàng có ĐỎ, cổng pháp lý production).
 */
class StorageEnableCommand extends Command
{
    protected $signature = 'vkcrm:storage:enable';

    protected $description = 'Bật kho tài liệu Google Drive: từ lúc này tệp MỚI tự lên kho (M14)';

    public function handle(EnableRemoteDocumentStore $enable): int
    {
        $result = $enable->handle();
        $at = $result['enabled_at'] === null ? '—' : self::time($result['enabled_at']);

        switch ($result['status']) {
            case 'enabled':
                $this->info(__('storage.commands.enable.enabled', ['at' => $at]));

                return self::SUCCESS;
            case 'already':
                $this->info(__('storage.commands.enable.already', ['at' => $at]));

                return self::SUCCESS;
            case 'not_google_drive':
                $this->error(__('storage.commands.enable.not_google_drive'));

                return 2;
            case 'not_ready':
                $this->error(__('storage.commands.enable.not_ready'));

                foreach ($result['red_rows'] as $row) {
                    $this->line('  ['.$row['level']->label().'] '.$row['message']);
                }

                return 2;
            default:
                $this->error(__('storage.commands.enable.dossier_missing'));

                return 2;
        }
    }

    public static function time(CarbonImmutable $at): string
    {
        return $at->setTimezone((string) config('app.timezone'))->format('H:i d/m/Y');
    }
}
