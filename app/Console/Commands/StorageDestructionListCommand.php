<?php

namespace App\Console\Commands;

use App\Actions\Storage\ListMatterFilesForDestruction;
use Illuminate\Console\Command;

/**
 * `vkcrm:storage:destruction-list {matter} --by=<email>` (kế hoạch M14, R15; sổ tay "Huỷ tệp của hồ sơ
 * đã quá hạn lưu") — in ba danh sách nơi còn tệp của một vụ đã ghi quyết định huỷ. Nghiệp vụ và cổng ở
 * {@see ListMatterFilesForDestruction}; lớp này chỉ in (không bao giờ in lại mã hồ sơ đã nhận) và chọn
 * mã thoát.
 *
 * Mã thoát: 0 đã liệt kê; 2 vụ không có, chưa ghi quyết định huỷ, hay người `--by` không phải quản trị
 * viên đang hoạt động.
 */
class StorageDestructionListCommand extends Command
{
    protected $signature = 'vkcrm:storage:destruction-list
        {matter : Mã hồ sơ của vụ đã ghi quyết định tiêu huỷ}
        {--by= : Email của quản trị viên chạy lệnh}';

    protected $description = 'Liệt kê tệp cần huỷ của một hồ sơ đã quá hạn lưu, ở kho, máy chủ và máy văn phòng (M14)';

    public function handle(ListMatterFilesForDestruction $list): int
    {
        $result = $list->handle((string) $this->argument('matter'), (string) $this->option('by'));

        if ($result['status'] !== 'done') {
            $this->error(__('storage.commands.destruction.'.$result['status']));

            return 2;
        }

        foreach (['drive_names', 'staged_paths', 'office_paths'] as $group) {
            $this->info(__('storage.commands.destruction.'.$group, ['count' => count($result[$group])]));

            foreach ($result[$group] as $item) {
                $this->line('  '.$item);
            }
        }

        $this->line(__('storage.commands.destruction.footer'));

        return self::SUCCESS;
    }
}
