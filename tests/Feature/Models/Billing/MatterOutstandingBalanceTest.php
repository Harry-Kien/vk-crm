<?php

use App\Enums\ContractStatus;
use App\Enums\InstalmentStatus;
use App\Exceptions\MatterHasOutstandingBalance;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Support\Billing\BillingSummary;
use App\Support\Billing\Money;

/**
 * Hook `Matter::deleting` (M9 Task 5, "Tiền trên một vụ việc… đã xoá mềm → CHẶN"). `CancelMatter`
 * (M6.5 Task 5) chưa tồn tại trong nhánh này — xem báo cáo Task 5 cho việc nó phải gọi lại đúng
 * `BillingSummary::outstandingForMatter()` khi merge, không viết một kiểm tra dư nợ thứ hai.
 */
it('refuses to soft delete a matter with an active contract carrying an unpaid instalment', function () {
    $matter = Matter::factory()->create();
    $contract = Contract::factory()->for($matter)->active()->create(['total_amount' => 10_000_000]);
    Instalment::factory()->for($contract)->create(['amount' => 10_000_000, 'status' => InstalmentStatus::Pending]);

    expect(fn () => $matter->delete())
        ->toThrow(MatterHasOutstandingBalance::class)
        ->and(Matter::query()->whereKey($matter->id)->exists())->toBeTrue();
});

it('names the outstanding amount and the number of instalments still owing, in vietnamese', function () {
    $matter = Matter::factory()->create();
    // Constraint (b), Task 4: hai đợt trên MỘT hợp đồng phải giữ tổng cân bằng — soạn nháp, thêm
    // đủ đợt, rồi mới kích hoạt bằng một lần ghi thẳng (không qua `active()` ngay từ đầu: mỗi
    // đợt tạo ra sẽ bị hook bất biến so với TOÀN BỘ total_amount ngay khi vừa tạo).
    $contract = Contract::factory()->for($matter)->create(['status' => ContractStatus::Draft, 'total_amount' => 10_000_000]);
    Instalment::factory()->for($contract)->create(['sequence' => 1, 'amount' => 6_000_000, 'status' => InstalmentStatus::Pending]);
    Instalment::factory()->for($contract)->create(['sequence' => 2, 'amount' => 4_000_000, 'status' => InstalmentStatus::Pending]);
    $contract->fill(['status' => ContractStatus::Active, 'signed_at' => today()->subDay()->toDateString()])->save();

    expect(fn () => $matter->delete())->toThrow(
        MatterHasOutstandingBalance::class,
        __('exceptions.matter_has_outstanding_balance', [
            'code' => $matter->code,
            'amount' => Money::format(10_000_000),
            'count' => 2,
        ]),
    );
});

/** Cặp dương: một vụ việc dư nợ bằng 0 (thu đủ) xoá mềm được bình thường. */
it('lets a matter whose instalments are all fully collected be soft deleted', function () {
    $matter = Matter::factory()->create();
    $contract = Contract::factory()->for($matter)->active()->create(['total_amount' => 10_000_000]);
    $instalment = Instalment::factory()->for($contract)->create(['amount' => 10_000_000, 'status' => InstalmentStatus::Pending]);
    Payment::factory()->for($instalment)->create(['amount' => 10_000_000]);
    $instalment->fill(['status' => InstalmentStatus::Paid])->save();

    $matter->delete();

    expect($matter->fresh()->trashed())->toBeTrue();
});

/** Miễn tường minh là đường thoát nợ hợp lệ — dư nợ về 0 thì xoá được. */
it('lets a matter be soft deleted once its remaining instalment has been explicitly waived', function () {
    $matter = Matter::factory()->create();
    $contract = Contract::factory()->for($matter)->active()->create(['total_amount' => 10_000_000]);
    Instalment::factory()->for($contract)->create([
        'amount' => 10_000_000,
        'status' => InstalmentStatus::Waived,
        'waived_reason' => str_repeat('a', 20),
        'waived_at' => now(),
    ]);

    $matter->delete();

    expect($matter->fresh()->trashed())->toBeTrue();
});

/**
 * Constraint (a), mang từ Task 4: một đợt `pending` của một hợp đồng đã `cancelled`/`completed`
 * KHÔNG tính vào dư nợ chặn xoá — `CancelContract` không chạm tới đợt, và dư nợ chỉ tính trên hợp
 * đồng `active` ({@see BillingSummary::outstandingForMatter()}).
 */
it('does not count a pending instalment of a cancelled contract as outstanding', function () {
    $matter = Matter::factory()->create();
    $contract = Contract::factory()->for($matter)->cancelled()->create(['total_amount' => 10_000_000]);
    Instalment::factory()->for($contract)->create(['amount' => 10_000_000, 'status' => InstalmentStatus::Pending]);

    $matter->delete();

    expect($matter->fresh()->trashed())->toBeTrue();
});

/** "Đã ĐÓNG" (closed_at) không phải "xoá mềm": một vụ đã đóng còn nợ vẫn chặn xoá MỀM. */
it('still refuses to soft delete a matter that is already closed but still carries a balance', function () {
    $matter = Matter::factory()->create(['closed_at' => today()]);
    $contract = Contract::factory()->for($matter)->active()->create(['total_amount' => 10_000_000]);
    Instalment::factory()->for($contract)->create(['amount' => 10_000_000, 'status' => InstalmentStatus::Pending]);

    expect(fn () => $matter->delete())->toThrow(MatterHasOutstandingBalance::class);
});
