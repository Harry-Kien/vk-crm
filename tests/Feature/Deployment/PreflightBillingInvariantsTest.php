<?php

use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\Instalment;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| M9 Task 13 — `vkcrm:preflight` có một dòng "bất biến tiền"
|--------------------------------------------------------------------------
|
| Tầng 4 của bất biến tổng M9 (`billing:check-invariants`, `ScheduleTotal::mismatchedActiveContracts()`)
| nay cũng là một dòng của `vkcrm:preflight`: ĐỎ khi có hợp đồng `active` mà tổng các đợt chưa huỷ
| khác `total_amount`, XANH khi sạch. Dữ liệu chứ không phải cấu hình máy, nên dòng này chạy ở MỌI
| `APP_ENV` (như ba biến số sao lưu), không chỉ dưới `production`.
|
| Tệp riêng (không nối vào `PreflightCommandTest.php`): nhánh `main` đang thêm test vào cuối tệp đó,
| tách ra để lần gộp không xung đột văn bản. Đi qua lệnh Artisan thật như tệp kia.
| Hợp đồng lệch dựng bằng `DB::table()` — đúng đường duy nhất lọt qua ba tầng kia.
*/

/** Hợp đồng `active` có lịch thu cân đúng tổng, dựng ở `draft` rồi mới kích hoạt (hook tầng 2 đứng gác). */
function pfbContract(string $code, array $amounts): Contract
{
    $contract = Contract::factory()->create(['code' => $code, 'status' => ContractStatus::Draft, 'total_amount' => array_sum($amounts)]);

    foreach (array_values($amounts) as $index => $amount) {
        Instalment::factory()->for($contract)->create(['sequence' => $index + 1, 'amount' => $amount]);
    }

    $contract->update(['status' => ContractStatus::Active, 'signed_at' => today()->toDateString()]);

    return $contract;
}

it('§preflight M9 một hợp đồng đang hiệu lực lệch tổng là ĐỎ, nêu mã hợp đồng, mã thoát khác 0, ở mọi APP_ENV', function () {
    config(['app.env' => 'staging']);

    pfbContract('HD-2026-0101', [60_000_000, 40_000_000]);
    $drifted = pfbContract('HD-2026-0102', [30_000_000, 20_000_000]);
    DB::table('instalments')->where('contract_id', $drifted->id)->where('sequence', 2)->update(['amount' => 20_000_001]);

    $exitCode = Artisan::call('vkcrm:preflight');
    $output = Artisan::output();

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain(__('preflight.billing_invariants_mismatch', ['count' => 1, 'codes' => 'HD-2026-0102']))
        ->and($output)->not->toContain('HD-2026-0101')
        ->and($output)->toContain(__('preflight.summary_red'));
});

it('§preflight M9 mọi hợp đồng đang hiệu lực khớp tổng là XANH, nêu số hợp đồng đã quét, không đổi mã thoát', function () {
    config(['app.env' => 'staging']);

    pfbContract('HD-2026-0201', [60_000_000, 40_000_000]);
    pfbContract('HD-2026-0202', [25_000_000]);
    // Hợp đồng không `active` không được tính, dù lịch thu của nó lệch.
    Contract::factory()->create(['code' => 'HD-2026-0203', 'status' => ContractStatus::Draft, 'total_amount' => 10_000_000]);

    $exitCode = Artisan::call('vkcrm:preflight');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain(__('preflight.billing_invariants_ok', ['count' => 2]))
        ->and($output)->not->toContain('HD-2026-0203')
        ->and($output)->not->toContain(__('preflight.summary_red'));
});

it('§preflight M9 nêu tối đa năm mã hợp đồng lệch, rồi chỉ sang billing:check-invariants', function () {
    config(['app.env' => 'staging']);

    foreach (range(1, 7) as $n) {
        $contract = pfbContract(sprintf('HD-2026-03%02d', $n), [10_000_000]);
        DB::table('contracts')->where('id', $contract->id)->update(['total_amount' => 10_000_001]);
    }

    Artisan::call('vkcrm:preflight');
    $output = Artisan::output();

    expect($output)->toContain(__('preflight.billing_invariants_mismatch', [
        'count' => 7,
        'codes' => 'HD-2026-0301, HD-2026-0302, HD-2026-0303, HD-2026-0304, HD-2026-0305, …',
    ]))
        ->and($output)->not->toContain('HD-2026-0306')
        ->and($output)->toContain('billing:check-invariants');
});
