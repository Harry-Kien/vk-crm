<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Support\Billing\Money;
use App\Support\Billing\ScheduleTotal;
use Illuminate\Console\Command;

/**
 * `billing:check-invariants` — TẦNG 4 của bất biến tổng M9: quét mọi hợp đồng `active`, liệt kê
 * hợp đồng mà tổng các đợt chưa huỷ khác `total_amount`. Ba tầng kia chặn trước khi ghi; tầng này
 * bắt những gì đã lọt qua đường không có hook — `DB::table()`, SQL thô, một lần nhập tay vào CSDL.
 *
 * Mã thoát `1` khi có hợp đồng lệch (để `vkcrm:preflight` và cron có thể đỏ lên, M9 Task 13),
 * `0` khi sạch. Chỉ đọc: lệnh không sửa gì — sửa một hợp đồng lệch là quyết định của người, qua
 * phụ lục. Định nghĩa bất biến và truy vấn nằm ở {@see ScheduleTotal}; lệnh chỉ in.
 */
class CheckBillingInvariants extends Command
{
    protected $signature = 'billing:check-invariants';

    public function __construct()
    {
        parent::__construct();

        $this->setDescription(__('billing.check_invariants.description'));
    }

    public function handle(): int
    {
        $mismatched = ScheduleTotal::mismatchedActiveContracts();

        if ($mismatched->isEmpty()) {
            $this->info(__('billing.check_invariants.clean', ['count' => ScheduleTotal::activeContractCount()]));

            return self::SUCCESS;
        }

        $this->error(__('billing.check_invariants.found', ['count' => $mismatched->count()]));

        $this->table(
            [
                __('billing.check_invariants.columns.code'),
                __('billing.check_invariants.columns.total'),
                __('billing.check_invariants.columns.schedule'),
                __('billing.check_invariants.columns.difference'),
            ],
            $mismatched->map(function (Contract $contract): array {
                $scheduleTotal = (int) $contract->getAttribute('schedule_total');

                return [
                    $contract->code,
                    Money::format($contract->total_amount),
                    Money::format($scheduleTotal),
                    Money::format($scheduleTotal - $contract->total_amount),
                ];
            })->all(),
        );

        return self::FAILURE;
    }
}
