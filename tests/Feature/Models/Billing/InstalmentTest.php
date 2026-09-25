<?php

use App\Enums\ContractStatus;
use App\Enums\InstalmentState;
use App\Enums\InstalmentStatus;
use App\Exceptions\InstalmentNotDestroyable;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Payment;

it('cannot be deleted once the contract has left draft', function () {
    $contract = Contract::factory()->active()->create();
    $instalment = Instalment::factory()->for($contract)->create();

    expect(fn () => $instalment->delete())->toThrow(InstalmentNotDestroyable::class)
        ->and(Instalment::count())->toBe(1);
});

it('can be hard deleted while the contract is still draft', function () {
    $contract = Contract::factory()->create(['status' => ContractStatus::Draft]);
    $instalment = Instalment::factory()->for($contract)->create();

    $instalment->delete();

    expect(Instalment::count())->toBe(0);
});

it('refuses in vietnamese, from the language file', function () {
    $contract = Contract::factory()->active()->create();
    $instalment = Instalment::factory()->for($contract)->create();

    expect(fn () => $instalment->delete())
        ->toThrow(InstalmentNotDestroyable::class, __('exceptions.instalment_not_destroyable'));
});

it('does not have a deleted_at column', function () {
    expect(Instalment::factory()->create()->getAttributes())->not->toHaveKey('deleted_at');
});

/**
 * Bảy trạng thái hiển thị (InstalmentState), suy ra từ bốn trạng thái lưu cộng due_date và tổng
 * khoản thu chưa huỷ — đúng một chỗ, `Instalment::state()`. Mỗi dòng dữ liệu dưới đây cô lập
 * ĐÚNG một điều kiện trong docblock của hàm đó.
 */
it('derives the display state from stored status, due date, and uncancelled payments', function (
    InstalmentStatus $status,
    ?string $dueDateOffsetDays,
    int $amount,
    int $collected,
    InstalmentState $expected,
) {
    $contract = Contract::factory()->active()->create();
    $instalment = Instalment::factory()->for($contract)->create([
        'status' => $status,
        'amount' => $amount,
        'due_date' => $dueDateOffsetDays === null ? null : today()->addDays((int) $dueDateOffsetDays)->toDateString(),
    ]);

    if ($collected > 0) {
        Payment::factory()->for($instalment)->create(['amount' => $collected]);
    }

    expect($instalment->state())->toBe($expected);
})->with([
    'pending, no due date yet -> scheduled' => [InstalmentStatus::Pending, null, 10_000_000, 0, InstalmentState::Scheduled],
    'pending, due date in the future, nothing collected -> due' => [InstalmentStatus::Pending, '10', 10_000_000, 0, InstalmentState::Due],
    'pending, due date today, nothing collected -> due (not overdue)' => [InstalmentStatus::Pending, '0', 10_000_000, 0, InstalmentState::Due],
    'pending, due date in the past, nothing collected -> overdue' => [InstalmentStatus::Pending, '-1', 10_000_000, 0, InstalmentState::Overdue],
    'pending, past due date, partially collected -> partially_paid (not overdue)' => [InstalmentStatus::Pending, '-5', 10_000_000, 4_000_000, InstalmentState::PartiallyPaid],
    'pending, fully collected but status not synced yet -> paid' => [InstalmentStatus::Pending, '-5', 10_000_000, 10_000_000, InstalmentState::Paid],
    'stored paid -> paid' => [InstalmentStatus::Paid, '-5', 10_000_000, 0, InstalmentState::Paid],
    'stored waived -> waived' => [InstalmentStatus::Waived, null, 10_000_000, 0, InstalmentState::Waived],
    'stored cancelled -> cancelled' => [InstalmentStatus::Cancelled, null, 10_000_000, 0, InstalmentState::Cancelled],
]);

it('ignores voided payments when computing the collected total for state()', function () {
    $contract = Contract::factory()->active()->create();
    $instalment = Instalment::factory()->for($contract)->create([
        'status' => InstalmentStatus::Pending,
        'amount' => 10_000_000,
        'due_date' => today()->addDays(10)->toDateString(),
    ]);

    Payment::factory()->for($instalment)->voided()->create(['amount' => 10_000_000]);

    expect($instalment->state())->toBe(InstalmentState::Due);
});
