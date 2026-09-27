<?php

use App\Enums\ContractStatus;
use App\Enums\InstalmentState;
use App\Enums\InstalmentStatus;
use App\Exceptions\InstalmentNotDestroyable;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Payment;
use App\Support\Billing\BillingSummary;
use Illuminate\Support\Facades\DB;

it('cannot be deleted once the contract has left draft', function () {
    $contract = Contract::factory()->active()->create();
    $instalment = Instalment::factory()->for($contract)->create(['amount' => $contract->total_amount]);

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
    $instalment = Instalment::factory()->for($contract)->create(['amount' => $contract->total_amount]);

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
    // Hợp đồng NHÁP, có chủ đích: `state()` không đọc trạng thái hợp đồng, còn trên hợp đồng
    // `active` một đợt `cancelled` đứng một mình là một lịch thu lệch tổng mà hook bất biến (M9
    // Task 4) từ chối ghi.
    $contract = Contract::factory()->create(['status' => ContractStatus::Draft, 'total_amount' => $amount]);
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
    $contract = Contract::factory()->active()->create(['total_amount' => 10_000_000]);
    $instalment = Instalment::factory()->for($contract)->create([
        'status' => InstalmentStatus::Pending,
        'amount' => 10_000_000,
        'due_date' => today()->addDays(10)->toDateString(),
    ]);

    Payment::factory()->for($instalment)->voided()->create(['amount' => 10_000_000]);

    expect($instalment->state())->toBe(InstalmentState::Due);
});

/**
 * `outstanding()` (M9 Task 5) — còn phải thu của MỘT đợt, số nguyên đồng.
 */
it('computes outstanding as amount minus uncancelled payments, zero for waived/cancelled/paid', function (
    InstalmentStatus $status,
    int $collected,
    int $expected,
) {
    // Hợp đồng NHÁP, cố ý — cùng lý do test state() ở trên: cô lập outstanding() khỏi bất biến
    // tổng M9, không liên quan tới điều đang thử ở đây.
    $contract = Contract::factory()->create(['status' => ContractStatus::Draft, 'total_amount' => 10_000_000]);
    $instalment = Instalment::factory()->for($contract)->create(['status' => $status, 'amount' => 10_000_000]);

    if ($collected > 0) {
        Payment::factory()->for($instalment)->create(['amount' => $collected]);
    }

    expect($instalment->fresh()->outstanding())->toBe($expected);
})->with([
    'pending, nothing collected' => [InstalmentStatus::Pending, 0, 10_000_000],
    'pending, partially collected' => [InstalmentStatus::Pending, 4_000_000, 6_000_000],
    'pending, fully collected' => [InstalmentStatus::Pending, 10_000_000, 0],
    'paid -> 0 by definition, regardless of any payments row' => [InstalmentStatus::Paid, 0, 0],
    'waived -> 0' => [InstalmentStatus::Waived, 0, 0],
    'cancelled -> 0' => [InstalmentStatus::Cancelled, 0, 0],
]);

it('ignores voided payments when computing outstanding()', function () {
    $contract = Contract::factory()->create(['status' => ContractStatus::Draft, 'total_amount' => 10_000_000]);
    $instalment = Instalment::factory()->for($contract)->create(['status' => InstalmentStatus::Pending, 'amount' => 10_000_000]);

    Payment::factory()->for($instalment)->voided()->create(['amount' => 10_000_000]);

    expect($instalment->outstanding())->toBe(10_000_000);
});

/**
 * `scopeOverdue()` (M9 Task 5) — cùng điều kiện với nhánh cuối của `pendingState()`, viết bằng SQL.
 * Tập biên NẰM TRÊN MỘT HỢP ĐỒNG `active` DUY NHẤT (constraint (b), Task 4: fixture phải giữ tổng
 * cân bằng — soạn nháp, thêm đủ đợt, rồi mới kích hoạt bằng một lần ghi thẳng).
 */
it('agrees with state() on the instalment-level boundary set: due today, past due, partially paid, waived, cancelled', function () {
    // Tổng chỉ tính 5 đợt CHƯA HUỶ (10 triệu x 5 = 50 triệu, `ScheduleTotal::counted()` loại đợt
    // `cancelled` khỏi bất biến tổng M9), không phải 60 triệu gồm cả đợt `cancelled` thứ sáu.
    $contract = Contract::factory()->create(['status' => ContractStatus::Draft, 'total_amount' => 50_000_000]);

    $dueToday = Instalment::factory()->for($contract)->create(['sequence' => 1, 'amount' => 10_000_000, 'due_date' => today()->toDateString()]);
    $overdue = Instalment::factory()->for($contract)->create(['sequence' => 2, 'amount' => 10_000_000, 'due_date' => today()->subDay()->toDateString()]);
    $partiallyPaidPastDue = Instalment::factory()->for($contract)->create(['sequence' => 3, 'amount' => 10_000_000, 'due_date' => today()->subDays(5)->toDateString()]);
    $fullyCollectedStillPending = Instalment::factory()->for($contract)->create(['sequence' => 4, 'amount' => 10_000_000, 'due_date' => today()->subDays(5)->toDateString()]);
    $waived = Instalment::factory()->for($contract)->create(['sequence' => 5, 'amount' => 10_000_000, 'due_date' => today()->subDay()->toDateString(), 'status' => InstalmentStatus::Waived, 'waived_reason' => str_repeat('a', 20), 'waived_at' => now()]);
    $cancelled = Instalment::factory()->for($contract)->create(['sequence' => 6, 'amount' => 10_000_000, 'due_date' => today()->subDay()->toDateString(), 'status' => InstalmentStatus::Cancelled]);

    Payment::factory()->for($partiallyPaidPastDue)->create(['amount' => 4_000_000]);
    Payment::factory()->for($fullyCollectedStillPending)->create(['amount' => 10_000_000]);

    // Kích hoạt bằng cách ghi thẳng model (constraint (b)): tổng các đợt chưa huỷ (60 triệu) đã
    // khớp `total_amount` từ trước, nên lần chuyển sang `active` này qua được hook bất biến.
    $contract->fill(['status' => ContractStatus::Active, 'signed_at' => today()->subDay()->toDateString()])->save();

    $cases = [
        'due today' => $dueToday,
        'overdue' => $overdue,
        'partially paid, past due' => $partiallyPaidPastDue,
        'fully collected, status not yet synced' => $fullyCollectedStillPending,
        'waived' => $waived,
        'cancelled' => $cancelled,
    ];

    foreach ($cases as $label => $instalment) {
        $fresh = $instalment->fresh();
        $isOverdueByState = $fresh->state() === InstalmentState::Overdue;
        $isOverdueByScope = Instalment::query()->overdue()->whereKey($instalment->id)->exists();

        expect($isOverdueByScope)->toBe($isOverdueByState, "lệch nhau ở ca: {$label}");
    }

    expect($overdue->fresh()->state())->toBe(InstalmentState::Overdue)
        ->and(Instalment::query()->overdue()->pluck('id')->all())->toBe([$overdue->id]);
});

/**
 * Constraint (a), mang từ Task 4: `scopeOverdue()` phải lọc hợp đồng `active`. Đây là điểm DUY
 * NHẤT `state()` (gọi trên một instance, không biết gì về hợp đồng cha) và `scopeOverdue()` được
 * PHÉP lệch nhau, có chủ đích — xem docblock `scopeOverdue()`.
 */
it('excludes a pending instalment of a cancelled or completed contract from scopeOverdue(), even though its own state() calls it overdue', function (string $contractState) {
    $contract = Contract::factory()->{$contractState}()->create();
    $instalment = Instalment::factory()->for($contract)->create([
        'status' => InstalmentStatus::Pending,
        'due_date' => today()->subDays(5)->toDateString(),
    ]);

    expect($instalment->state())->toBe(InstalmentState::Overdue)
        ->and(Instalment::query()->overdue()->whereKey($instalment->id)->exists())->toBeFalse();
})->with(['cancelled', 'completed']);

/**
 * M9 Task 8, phán quyết controller 1: `state()` (qua `pendingState()`) trả lời ĐÚNG như trước khi
 * đọc bằng cách nào (BillingSummary::pendingInstalmentsQuery()` selectRaw `collected_amount`, hay
 * truy vấn `payments()->sum()` như mọi nơi khác) — chỉ NGUỒN đọc khác, không kết quả.
 */
it('agrees with the normal query path whether collected_amount comes pre-computed or is queried fresh', function () {
    $contract = Contract::factory()->active()->create(['total_amount' => 10_000_000]);
    $instalment = Instalment::factory()->for($contract)->create([
        'status' => InstalmentStatus::Pending,
        'amount' => 10_000_000,
        'due_date' => today()->subDays(3)->toDateString(),
    ]);
    Payment::factory()->for($instalment)->create(['amount' => 4_000_000]);

    $viaQuery = Instalment::query()->find($instalment->id)->state();
    $viaAggregate = BillingSummary::pendingInstalmentsQuery()->find($instalment->id)->state();

    expect($viaAggregate)->toBe(InstalmentState::PartiallyPaid)
        ->and($viaAggregate)->toBe($viaQuery);
});

/**
 * Chuẩn mutation probe (M4): con số trên PHẢI đến từ cột `collected_amount` đã tính sẵn, không
 * phải một truy vấn `payments()->sum()` mới — đo bằng số truy vấn, không chỉ bằng kết quả (hai
 * nguồn đọc CÙNG một số tiền một cách tình cờ sẽ không bị một test chỉ so kết quả bắt được).
 */
it('reads state() from the pre-computed collected_amount column without an extra payments query', function () {
    $contract = Contract::factory()->active()->create(['total_amount' => 10_000_000]);
    $instalment = Instalment::factory()->for($contract)->create([
        'status' => InstalmentStatus::Pending,
        'amount' => 10_000_000,
        'due_date' => today()->subDays(3)->toDateString(),
    ]);
    Payment::factory()->for($instalment)->create(['amount' => 4_000_000]);

    $viaAggregate = BillingSummary::pendingInstalmentsQuery()->findOrFail($instalment->id);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $stateFromAggregate = $viaAggregate->state();
    $queriesWithAggregate = count(DB::getQueryLog());

    $plain = Instalment::query()->findOrFail($instalment->id);
    DB::flushQueryLog();
    $stateFromPlainLoad = $plain->state();
    $queriesWithoutAggregate = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($stateFromAggregate)->toBe($stateFromPlainLoad)
        ->and($queriesWithAggregate)->toBe(0)
        ->and($queriesWithoutAggregate)->toBe(1);
});
