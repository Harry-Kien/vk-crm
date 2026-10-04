<?php

namespace App\Console\Commands;

use App\Actions\Deployment\RunPreflight;
use App\Enums\PreflightLevel;
use Illuminate\Console\Command;

/**
 * `vkcrm:preflight` (R1, kế hoạch M8 Task 1) — kiểm các điều kiện phải đúng trước khi mở cổng một
 * máy chủ thật, và sau mỗi lần nâng cấp (`docs/CAI-DAT.md`). Nghiệp vụ ở {@see RunPreflight}; lớp
 * này chỉ in kết quả và chọn mã thoát.
 *
 * **CHẠY LỆNH NÀY TRƯỚC `php artisan config:cache`** — đọc lý do ở docblock của
 * {@see RunPreflight} (phần "Giới hạn đã biết trước"): sau khi cấu hình đã được cache, một vài
 * điều kiện đọc `env()` trực tiếp không còn thấy giá trị thật của `.env` nữa.
 *
 * Mã thoát khác 0 khi có ít nhất một dòng ĐỎ; bằng 0 khi chỉ có VÀNG/XANH — một máy CI/CD có thể
 * chặn triển khai bằng cách gọi lệnh này và đọc mã thoát, không cần đọc chuỗi tiếng Việt.
 *
 * Câu tổng kết khi có ĐỎ hỏi {@see RunPreflight::blocksOpening()}: còn một dòng ĐỎ chặn mở cổng thì
 * "KHÔNG mở cổng"; dòng ĐỎ duy nhất là "bất biến tiền" (dữ liệu, chỉ sửa được trong app) thì câu tổng
 * kết nói vẫn `php artisan up` rồi sửa bằng phụ lục — mã thoát VẪN khác 0 trong trường hợp đó.
 */
class PreflightCommand extends Command
{
    protected $signature = 'vkcrm:preflight';

    protected $description = 'Kiểm các điều kiện phải đúng trước khi mở cổng máy chủ thật (R1)';

    public function handle(RunPreflight $action): int
    {
        $rows = $action->handle();

        $hasRed = false;
        $hasBlockingRed = false;
        $hasYellow = false;

        foreach ($rows as $row) {
            $line = '['.$row['level']->label().'] '.$row['message'];

            match ($row['level']) {
                PreflightLevel::Red => $this->error($line),
                PreflightLevel::Yellow => $this->warn($line),
                PreflightLevel::Green => $this->info($line),
            };

            $hasRed = $hasRed || $row['level'] === PreflightLevel::Red;
            $hasBlockingRed = $hasBlockingRed || RunPreflight::blocksOpening($row);
            $hasYellow = $hasYellow || $row['level'] === PreflightLevel::Yellow;
        }

        $this->newLine();

        if ($hasRed) {
            $this->error(__($hasBlockingRed ? 'preflight.summary_red' : 'preflight.summary_red_billing_only'));

            return self::FAILURE;
        }

        if ($hasYellow) {
            $this->warn(__('preflight.summary_yellow'));

            return self::SUCCESS;
        }

        $this->info(__('preflight.summary_ok'));

        return self::SUCCESS;
    }
}
