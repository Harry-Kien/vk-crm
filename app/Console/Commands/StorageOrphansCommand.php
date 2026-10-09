<?php

namespace App\Console\Commands;

use App\Actions\Storage\ListRemoteOrphans;
use Illuminate\Console\Command;

/**
 * `vkcrm:storage:orphans` (kế hoạch M14, R4, R11, R15) — CHỈ báo cáo tệp lệch giữa kho, chỉ mục, thư
 * viện media và vùng đệm; không xoá gì. Nghiệp vụ ở {@see ListRemoteOrphans}; lớp này in từng nhóm (mã
 * media, khoá và tên mờ; tên không mờ chỉ được đếm) và chọn mã thoát.
 *
 * Mã thoát: 0 khi không nhóm lỗi nào (tệp thăm dò còn sống và tệp của vụ đã ghi huỷ chỉ là thông tin);
 * 1 khi có nhóm lỗi, hoặc Drive không liệt kê được.
 */
class StorageOrphansCommand extends Command
{
    protected $signature = 'vkcrm:storage:orphans';

    protected $description = 'Báo cáo tệp mồ côi, thiếu hay trùng tên giữa kho Google Drive và CRM; không xoá gì (M14)';

    public function handle(ListRemoteOrphans $orphans): int
    {
        $report = $orphans->handle();

        $this->groups($report, [
            'index_without_media' => fn (array $keys) => implode(', ', $keys),
            'media_without_file' => fn (array $ids) => StorageVerifyCommand::ids($ids),
            'drive_unindexed' => fn (array $names) => implode(', ', $names),
            'duplicates' => fn (array $names) => implode(', ', $names),
            'staged_orphans' => fn (array $directories) => implode(', ', $directories),
        ], error: true);

        foreach (['drive_unindexed_unknown', 'duplicates_unknown'] as $count) {
            if ($report[$count] > 0) {
                $this->error(__('storage.commands.orphans.'.$count, ['count' => $report[$count]]));
            }
        }

        $this->groups($report, [
            'probes' => fn (array $items) => implode(', ', $items),
            'destroyed' => fn (array $ids) => StorageVerifyCommand::ids($ids),
        ], error: false);

        if ($report['listing_failed'] !== null) {
            $this->error(__('storage.commands.orphans.listing_failed', ['error' => $report['listing_failed']]));
        }

        $problems = ListRemoteOrphans::hasProblems($report);

        $this->line($problems ? __('storage.commands.orphans.problems') : __('storage.commands.orphans.clean'));

        return $problems ? self::FAILURE : self::SUCCESS;
    }

    /** @param  array<string, callable(array): string>  $groups */
    private function groups(array $report, array $groups, bool $error): void
    {
        foreach ($groups as $group => $format) {
            if ($report[$group] === []) {
                continue;
            }

            $line = __('storage.commands.orphans.'.$group, ['count' => count($report[$group])]).': '.$format($report[$group]);
            $error ? $this->error($line) : $this->warn($line);
        }
    }
}
