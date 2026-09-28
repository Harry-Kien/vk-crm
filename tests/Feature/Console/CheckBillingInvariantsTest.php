<?php

use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\Instalment;
use App\Support\Billing\Money;
use Illuminate\Support\Facades\DB;

/**
 * Tầng 4 của bất biến tổng M9. Hợp đồng lệch được dựng bằng `DB::table()` — đúng đường duy nhất
 * đi lọt ba tầng kia, và là thứ lệnh này tồn tại để bắt.
 */
function invariantContract(string $code, array $amounts, array $cancelled = []): Contract
{
    $contract = Contract::factory()->create(['code' => $code, 'status' => ContractStatus::Draft, 'total_amount' => array_sum($amounts)]);

    foreach (array_values($amounts) as $index => $amount) {
        Instalment::factory()->for($contract)->create(['sequence' => $index + 1, 'amount' => $amount]);
    }

    foreach (array_values($cancelled) as $index => $amount) {
        Instalment::factory()->for($contract)->cancelled()->create(['sequence' => 10 + $index, 'amount' => $amount]);
    }

    $contract->update(['status' => ContractStatus::Active, 'signed_at' => today()->toDateString()]);

    return $contract;
}

it('finds an active contract made to drift with a raw query, and exits non-zero', function () {
    invariantContract('HD-2026-0001', [60_000_000, 40_000_000]);
    $drifted = invariantContract('HD-2026-0002', [30_000_000, 20_000_000]);
    DB::table('instalments')->where('contract_id', $drifted->id)->where('sequence', 2)->update(['amount' => 20_000_001]);

    $this->artisan('billing:check-invariants')
        ->expectsOutputToContain(__('billing.check_invariants.found', ['count' => 1]))
        ->expectsTable(
            [
                __('billing.check_invariants.columns.code'),
                __('billing.check_invariants.columns.total'),
                __('billing.check_invariants.columns.schedule'),
                __('billing.check_invariants.columns.difference'),
            ],
            [['HD-2026-0002', Money::format(50_000_000), Money::format(50_000_001), Money::format(1)]],
        )
        ->assertExitCode(1);
});

it('finds a drifted total as well as a drifted schedule', function () {
    $drifted = invariantContract('HD-2026-0003', [60_000_000, 40_000_000]);
    DB::table('contracts')->where('id', $drifted->id)->update(['total_amount' => 90_000_000]);

    $this->artisan('billing:check-invariants')
        ->expectsOutputToContain('HD-2026-0003')
        ->assertExitCode(1);
});

it('finds an active contract with no instalment at all', function () {
    Contract::factory()->active()->create(['code' => 'HD-2026-0004', 'total_amount' => 10_000_000]);

    $this->artisan('billing:check-invariants')
        ->expectsOutputToContain('HD-2026-0004')
        ->assertExitCode(1);
});

it('reports clean, with the number of contracts it checked, when every active contract matches', function () {
    invariantContract('HD-2026-0001', [60_000_000, 40_000_000]);
    // Đợt đã huỷ nằm ngoài tổng: 70.000.000 khớp dù có một đợt 30.000.000 đã huỷ.
    invariantContract('HD-2026-0002', [70_000_000], cancelled: [30_000_000]);

    $this->artisan('billing:check-invariants')
        ->expectsOutputToContain(__('billing.check_invariants.clean', ['count' => 2]))
        ->doesntExpectOutputToContain('HD-2026-0002')
        ->assertExitCode(0);
});

it('does not look at contracts that are not active', function () {
    Contract::factory()->create(['code' => 'HD-2026-0005', 'status' => ContractStatus::Draft, 'total_amount' => 10_000_000]);
    Contract::factory()->completed()->create(['code' => 'HD-2026-0006', 'total_amount' => 10_000_000]);
    Contract::factory()->cancelled()->create(['code' => 'HD-2026-0007', 'total_amount' => 10_000_000]);

    $this->artisan('billing:check-invariants')
        ->expectsOutputToContain(__('billing.check_invariants.clean', ['count' => 0]))
        ->assertExitCode(0);
});
