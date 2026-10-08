<?php

namespace App\Console\Commands;

use App\Actions\Storage\MigrateDocumentsToRemote;
use Illuminate\Console\Command;
use Illuminate\Support\Number;

/**
 * `vkcrm:storage:migrate [--dry-run] [--limit=N] [--max-minutes=M] [--keep-local-days=30]` (kế hoạch
 * M14, R11; Phụ lục C bước 5 và 7) — chuyển tệp cũ lên kho, ngoài giờ làm việc. Nghiệp vụ ở
 * {@see MigrateDocumentsToRemote}; lớp này đọc tuỳ chọn, in câu tiếng Việt và chọn mã thoát.
 *
 * Mã thoát: 0 xong (kể cả dừng vì `--limit`/`--max-minutes`, và chạy thử); 1 có tệp lỗi, hoặc dừng vì
 * kho không tới được/bị tắt giữa chừng; 2 kho chưa bật hay kiểm tra sẵn sàng có ĐỎ, hoặc tuỳ chọn sai.
 */
class StorageMigrateCommand extends Command
{
    protected $signature = 'vkcrm:storage:migrate
        {--dry-run : Chỉ đếm, đo tốc độ và ước thời gian; không chuyển tệp nào}
        {--limit= : Dừng sau chừng này media}
        {--max-minutes= : Không bắt đầu media mới sau chừng này phút}
        {--keep-local-days=30 : Giữ bản trên máy chủ ít nhất chừng này ngày sau khi chuyển}';

    protected $description = 'Chuyển tệp cũ từ máy chủ lên kho Google Drive (M14)';

    public function handle(MigrateDocumentsToRemote $migrate): int
    {
        $limit = $this->positiveOption('limit');
        $maxMinutes = $this->positiveOption('max-minutes');
        $keepDays = $this->positiveOption('keep-local-days');

        if ($limit === false || $maxMinutes === false || $keepDays === false) {
            $this->error(__('storage.commands.invalid_option'));

            return 2;
        }

        if ($this->option('dry-run')) {
            return $this->dryRun($migrate);
        }

        $report = $migrate->handle($limit, $maxMinutes, $keepDays ?? MigrateDocumentsToRemote::DEFAULT_KEEP_LOCAL_DAYS);

        if ($report['status'] === 'not_enabled') {
            $this->error(__('storage.commands.migrate.not_enabled'));

            return 2;
        }

        if ($report['status'] === 'dossier_missing') {
            $this->error(__('storage.commands.migrate.dossier_missing'));

            return 2;
        }

        if ($report['status'] === 'not_ready') {
            $this->error(__('storage.commands.migrate.not_ready'));

            foreach ($report['red_rows'] as $row) {
                $this->line('  ['.$row['level']->label().'] '.$row['message']);
            }

            return 2;
        }

        $this->info(__('storage.commands.migrate.pushed', ['count' => $report['pushed'], 'bytes' => self::bytes($report['bytes'])]));
        $this->line(__('storage.commands.migrate.skipped', ['count' => $report['skipped']]));
        $this->line(__('storage.commands.migrate.locked', ['count' => $report['locked']]));
        $this->line(__('storage.commands.migrate.remaining', ['count' => $report['remaining'], 'seconds' => $report['seconds']]));

        if ($report['failed'] !== []) {
            $this->error(__('storage.commands.migrate.failed', ['count' => count($report['failed'])]));

            foreach ($report['failed'] as $mediaId => $reason) {
                $this->line('  #'.$mediaId.': '.__('storage.commands.reasons.'.$reason));
            }
        }

        $this->line(match ($report['status']) {
            'limit' => __('storage.commands.migrate.stopped_limit', ['limit' => $limit]),
            'time' => __('storage.commands.migrate.stopped_time', ['minutes' => $maxMinutes]),
            'unavailable' => __('storage.commands.migrate.stopped_unavailable'),
            'disabled' => __('storage.commands.migrate.stopped_disabled'),
            default => __('storage.commands.migrate.done'),
        });

        return $report['failed'] !== [] || in_array($report['status'], ['unavailable', 'disabled'], true) ? self::FAILURE : self::SUCCESS;
    }

    private function dryRun(MigrateDocumentsToRemote $migrate): int
    {
        $plan = $migrate->dryRun();

        $this->info(__('storage.commands.migrate.dry_run_files', ['count' => $plan['files'], 'bytes' => self::bytes($plan['bytes'])]));
        $this->line($plan['bytes_per_second'] === null
            ? __('storage.commands.migrate.dry_run_speed_unknown')
            : __('storage.commands.migrate.dry_run_speed', [
                'speed' => Number::fileSize((int) $plan['bytes_per_second']),
                'minutes' => (int) ceil($plan['estimated_seconds'] / 60),
            ]));
        $this->line(__('storage.commands.migrate.dry_run_quota', [
            'quota' => number_format($plan['quota_bytes'] / 1_000_000_000, 0, ',', '.').' GB',
            'days' => max(1, (int) ceil($plan['bytes'] / $plan['quota_bytes'])),
        ]));
        $this->line($plan['free_bytes'] === null
            ? __('storage.commands.migrate.dry_run_free_unknown')
            : __('storage.commands.migrate.dry_run_free', ['free' => Number::fileSize($plan['free_bytes'])]));
        $this->line(__('storage.commands.migrate.dry_run_nothing_written').($plan['bytes_per_second'] === null ? '' : ' '.__('storage.commands.migrate.dry_run_probe_trashed')));

        return self::SUCCESS;
    }

    /** Số nguyên dương của một tuỳ chọn; `null` khi không đặt; `false` khi sai. */
    private function positiveOption(string $name): int|false|null
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return null;
        }

        return ctype_digit((string) $value) && (int) $value > 0 ? (int) $value : false;
    }

    public static function bytes(int $bytes): string
    {
        return number_format($bytes, 0, ',', '.').' byte';
    }
}
