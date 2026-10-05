<?php

namespace App\Console\Commands;

use App\Actions\Storage\StorageReadiness;
use App\Enums\PreflightLevel;
use Illuminate\Console\Command;

/**
 * `vkcrm:storage:check` (kế hoạch M14, R7, Task 5) — in các dòng sẵn sàng và các dòng trạng thái của kho
 * tài liệu ở MỌI `APP_ENV`. `vkcrm:preflight` chỉ kiểm điều kiện ra mắt khi `APP_ENV=production`, nên ở
 * làn, ở máy thử và trong lúc chủ văn phòng làm Phụ lục A (`docs/KHO-TAI-LIEU-GOOGLE-DRIVE.md`), lệnh
 * này là cổng. Nghiệp vụ ở {@see StorageReadiness}; lớp này chỉ in và chọn mã thoát.
 *
 * Mã thoát giống `vkcrm:preflight`: có dòng ĐỎ → 1; chỉ VÀNG/XANH → 0.
 *
 * Lệnh gọi Google thật: tệp thăm dò `drive_roundtrip` được ghi rồi cho vào thùng rác trên Shared Drive
 * đang cấu hình. Trong làn và trong test, chỉ chạy trên Drive giả (phán quyết C2).
 */
class StorageCheckCommand extends Command
{
    protected $signature = 'vkcrm:storage:check';

    protected $description = 'Kiểm kho tài liệu Google Drive: dòng sẵn sàng và dòng trạng thái, ở mọi môi trường (M14)';

    public function handle(StorageReadiness $readiness): int
    {
        $hasRed = false;
        $hasYellow = false;

        foreach ([
            'readiness_heading' => $readiness->rows(),
            'state_heading' => $readiness->stateRows(),
        ] as $heading => $rows) {
            $this->line(__('document_store.check.'.$heading));

            foreach ($rows as $row) {
                $line = '['.$row['level']->label().'] '.$row['message'];

                match ($row['level']) {
                    PreflightLevel::Red => $this->error($line),
                    PreflightLevel::Yellow => $this->warn($line),
                    PreflightLevel::Green => $this->info($line),
                };

                $hasRed = $hasRed || $row['level'] === PreflightLevel::Red;
                $hasYellow = $hasYellow || $row['level'] === PreflightLevel::Yellow;
            }

            $this->newLine();
        }

        if ($hasRed) {
            $this->error(__('preflight.summary_red'));

            return self::FAILURE;
        }

        $hasYellow ? $this->warn(__('preflight.summary_yellow')) : $this->info(__('document_store.check.summary_ok'));

        return self::SUCCESS;
    }
}
