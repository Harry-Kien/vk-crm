<?php

namespace App\Console\Commands;

use App\Actions\Storage\VerifyRemoteDocuments;
use Illuminate\Console\Command;

/**
 * `vkcrm:storage:verify [--all|--sample=N]` (kế hoạch M14, R11, R15; Phụ lục C bước 8) — so md5 và cỡ
 * của bản trên kho với dòng `media`. Nghiệp vụ ở {@see VerifyRemoteDocuments}; lớp này in từng nhóm
 * (chỉ mã media) và chọn mã thoát. Không tuỳ chọn nào: mẫu ngẫu nhiên
 * {@see VerifyRemoteDocuments::DEFAULT_SAMPLE} media.
 *
 * Mã thoát: 0 khi mọi tệp đã kiểm khớp (tệp của vụ đã ghi huỷ là nhóm riêng, không tính); 1 khi có tệp
 * bị đổi, đã vào thùng rác, thiếu, hay không kiểm được; 2 khi tuỳ chọn sai.
 */
class StorageVerifyCommand extends Command
{
    protected $signature = 'vkcrm:storage:verify
        {--all : Kiểm mọi media trên kho}
        {--sample= : Kiểm chừng này media chọn ngẫu nhiên}';

    protected $description = 'Kiểm md5 và kích thước của tệp trên kho Google Drive (M14)';

    public function handle(VerifyRemoteDocuments $verify): int
    {
        $sample = $this->option('sample');

        if ($sample !== null && (! ctype_digit((string) $sample) || (int) $sample < 1)) {
            $this->error(__('storage.commands.invalid_option'));

            return 2;
        }

        $report = $verify->handle($this->option('all') ? null : ($sample === null ? VerifyRemoteDocuments::DEFAULT_SAMPLE : (int) $sample));

        $this->line(__('storage.commands.verify.checked', ['checked' => $report['checked'], 'total' => $report['total']]));
        $this->info(__('storage.commands.verify.ok', ['count' => $report['ok']]));

        foreach (['changed', 'trashed', 'missing', 'failed'] as $group) {
            if ($report[$group] !== []) {
                $this->error(__('storage.commands.verify.'.$group, ['ids' => self::ids($report[$group])]));
            }
        }

        if ($report['destroyed'] !== []) {
            $this->warn(__('storage.commands.verify.destroyed', ['ids' => self::ids($report['destroyed'])]));
        }

        $problems = $report['changed'] !== [] || $report['trashed'] !== [] || $report['missing'] !== [] || $report['failed'] !== [];

        $this->line($problems ? __('storage.commands.verify.problems') : __('storage.commands.verify.clean'));

        return $problems ? self::FAILURE : self::SUCCESS;
    }

    /** @param  list<int>  $ids */
    public static function ids(array $ids): string
    {
        return implode(', ', array_map(fn (int $id): string => '#'.$id, $ids));
    }
}
