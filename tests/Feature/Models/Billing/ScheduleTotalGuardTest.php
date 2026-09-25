<?php

use App\Enums\ContractStatus;
use App\Enums\InstalmentStatus;
use App\Exceptions\ContractTotalMismatch;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\Instalment;
use App\Support\Billing\ScheduleTotal;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Support\Facades\DB;

/**
 * Tầng 2 của bất biến tổng M9 (`SUM(amount)` các đợt chưa huỷ === `total_amount`): hook của
 * `Instalment` (`saving`) và `Contract` (`updating`) chặn mọi lần ghi QUA MODEL làm lệch tổng trên
 * hợp đồng `active`. Đây chính là đường đi vòng qua Action (`ActivateContract`, `AmendContract`).
 *
 * Mỗi điều kiện của {@see ScheduleTotal::assertInstalmentWriteKeepsBalance()} và
 * {@see ScheduleTotal::assertContractWriteKeepsBalance()} có một test âm và một test dương đặt cạnh.
 */

/** Hợp đồng `active` khớp tổng, dựng đúng đường một Action đi: soạn nháp, thêm đợt, rồi mới kích hoạt. */
function balancedActiveContract(array $amounts): Contract
{
    $contract = Contract::factory()->create(['status' => ContractStatus::Draft, 'total_amount' => array_sum($amounts)]);

    foreach (array_values($amounts) as $index => $amount) {
        Instalment::factory()->for($contract)->create(['sequence' => $index + 1, 'amount' => $amount]);
    }

    $contract->update(['status' => ContractStatus::Active, 'signed_at' => today()->toDateString()]);

    return $contract->refresh();
}

function onWriteMessage(Contract $contract, int $scheduleTotal): string
{
    return ContractTotalMismatch::onWrite($contract->refresh(), $scheduleTotal)->getMessage();
}

// --- Instalment: tạo mới -----------------------------------------------------------------------

it('refuses creating an extra instalment on an active contract through the model', function () {
    $contract = balancedActiveContract([60_000_000, 40_000_000]);

    expect(fn () => Instalment::factory()->for($contract)->create(['sequence' => 3, 'amount' => 1]))
        ->toThrow(ContractTotalMismatch::class, onWriteMessage($contract, 100_000_001))
        ->and($contract->instalments()->count())->toBe(2);
});

it('accepts creating the instalment that makes an active contract match its total', function () {
    $contract = Contract::factory()->active()->create(['total_amount' => 50_000_000]);

    Instalment::factory()->for($contract)->create(['amount' => 50_000_000]);

    expect(ScheduleTotal::of($contract->id))->toBe(50_000_000);
});

it('lets instalments of a draft contract be written with any amount', function () {
    $contract = Contract::factory()->create(['status' => ContractStatus::Draft, 'total_amount' => 100_000_000]);

    $instalment = Instalment::factory()->for($contract)->create(['amount' => 1]);
    $instalment->update(['amount' => 7]);

    expect($instalment->refresh()->amount)->toBe(7);
});

// --- Instalment: sửa ---------------------------------------------------------------------------

it('refuses changing the amount of an instalment on an active contract through the model, by even one dong', function () {
    $contract = balancedActiveContract([60_000_000, 40_000_000]);
    $instalment = $contract->instalments()->first();

    expect(fn () => $instalment->update(['amount' => 60_000_001]))
        ->toThrow(ContractTotalMismatch::class, onWriteMessage($contract, 100_000_001))
        ->and($instalment->refresh()->amount)->toBe(60_000_000);
});

it('refuses cancelling an instalment of an active contract through the model', function () {
    $contract = balancedActiveContract([60_000_000, 40_000_000]);
    $instalment = $contract->instalments()->first();

    expect(fn () => $instalment->update(['status' => InstalmentStatus::Cancelled]))
        ->toThrow(ContractTotalMismatch::class, onWriteMessage($contract, 40_000_000))
        ->and($instalment->refresh()->status)->toBe(InstalmentStatus::Pending);
});

it('accepts a status write that keeps the total, such as marking an instalment paid or waived', function () {
    $contract = balancedActiveContract([60_000_000, 40_000_000]);
    [$first, $second] = $contract->instalments()->get()->all();

    $first->update(['status' => InstalmentStatus::Paid]);
    $second->update(['status' => InstalmentStatus::Waived, 'waived_reason' => 'Miễn theo thoả thuận riêng với khách hàng.']);

    expect($first->refresh()->status)->toBe(InstalmentStatus::Paid)
        ->and($second->refresh()->status)->toBe(InstalmentStatus::Waived);
});

it('leaves a cancelled instalment out of the total', function () {
    $contract = Contract::factory()->create(['status' => ContractStatus::Draft, 'total_amount' => 60_000_000]);
    $kept = Instalment::factory()->for($contract)->create(['sequence' => 1, 'amount' => 60_000_000]);
    Instalment::factory()->for($contract)->cancelled()->create(['sequence' => 2, 'amount' => 40_000_000]);
    $contract->update(['status' => ContractStatus::Active, 'signed_at' => today()->toDateString()]);

    $kept->update(['status' => InstalmentStatus::Paid]);

    expect($kept->refresh()->status)->toBe(InstalmentStatus::Paid)
        ->and(ScheduleTotal::of($contract->id))->toBe(60_000_000);
});

it('refuses moving an instalment off an active contract, even onto a draft one', function () {
    $active = balancedActiveContract([60_000_000, 40_000_000]);
    $draft = Contract::factory()->create(['status' => ContractStatus::Draft]);
    $instalment = $active->instalments()->first();

    expect(fn () => $instalment->update(['contract_id' => $draft->id, 'sequence' => 9]))
        ->toThrow(ContractTotalMismatch::class, onWriteMessage($active, 40_000_000))
        ->and($instalment->refresh()->contract_id)->toBe($active->id);
});

/**
 * Hợp đồng đã lệch sẵn chỉ có thể do một lần ghi thô (`DB::table`) — đúng thứ tầng 4 báo. Một lần
 * ghi không đụng ba cột quyết định tổng (`amount`, `status`, `contract_id`) không đổi tổng, nên
 * không bị chặn; một lần ghi đụng tới chúng thì phải để lại hợp đồng khớp tổng.
 */
it('does not block a write that leaves amount, status and contract alone, even on an out-of-balance contract', function () {
    $contract = balancedActiveContract([60_000_000, 40_000_000]);
    DB::table('contracts')->where('id', $contract->id)->update(['total_amount' => 90_000_000]);
    $instalment = $contract->instalments()->first();

    $instalment->update(['note' => 'Khách hẹn chuyển khoản đầu tháng.', 'due_date' => today()->addDays(5)->toDateString()]);

    expect($instalment->refresh()->note)->toBe('Khách hẹn chuyển khoản đầu tháng.');
});

it('blocks a write that touches the amount of an out-of-balance contract without fixing it', function () {
    $contract = balancedActiveContract([60_000_000, 40_000_000]);
    DB::table('contracts')->where('id', $contract->id)->update(['total_amount' => 90_000_000]);
    $instalment = $contract->instalments()->first();

    expect(fn () => $instalment->update(['amount' => 55_000_000]))
        ->toThrow(ContractTotalMismatch::class, onWriteMessage($contract, 95_000_000));
});

// --- Contract ----------------------------------------------------------------------------------

it('refuses changing the total of an active contract through the model', function () {
    $contract = balancedActiveContract([60_000_000, 40_000_000]);

    expect(fn () => $contract->update(['total_amount' => 120_000_000]))
        ->toThrow(ContractTotalMismatch::class)
        ->and($contract->refresh()->total_amount)->toBe(100_000_000);
});

it('refuses putting a contract into active through the model when its schedule does not match', function () {
    $contract = Contract::factory()->create(['status' => ContractStatus::Draft, 'total_amount' => 100_000_000]);
    Instalment::factory()->for($contract)->create(['amount' => 99_999_999]);

    expect(fn () => $contract->update(['status' => ContractStatus::Active, 'signed_at' => today()->toDateString()]))
        ->toThrow(ContractTotalMismatch::class, ContractTotalMismatch::onWrite($contract->refresh(), 99_999_999)->getMessage())
        ->and($contract->refresh()->status)->toBe(ContractStatus::Draft);
});

it('accepts putting a contract into active through the model when its schedule matches', function () {
    $contract = balancedActiveContract([60_000_000, 40_000_000]);

    expect($contract->status)->toBe(ContractStatus::Active);
});

it('lets the total of a draft contract change freely', function () {
    $contract = Contract::factory()->create(['status' => ContractStatus::Draft, 'total_amount' => 100_000_000]);
    Instalment::factory()->for($contract)->create(['amount' => 100_000_000]);

    $contract->update(['total_amount' => 120_000_000]);

    expect($contract->refresh()->total_amount)->toBe(120_000_000);
});

it('does not block an update of an active contract that leaves status and total alone, even when out of balance', function () {
    $contract = Contract::factory()->active()->create(['total_amount' => 100_000_000]);

    $contract->update(['note' => 'Khách đề nghị gửi hoá đơn qua thư điện tử.']);

    expect($contract->refresh()->note)->toBe('Khách đề nghị gửi hoá đơn qua thư điện tử.');
});

// --- whileAmending -----------------------------------------------------------------------------

it('lifts the guard for the contract being amended only, and only while the amendment runs', function () {
    $amended = balancedActiveContract([60_000_000, 40_000_000]);
    $other = balancedActiveContract([10_000_000]);

    // Thứ tự có chủ đích: lần ghi thứ nhất (giá trị) và thứ hai (đợt đầu) đều để lại tổng lệch,
    // nên mỗi nửa của cơ chế tạm tắt — phía hợp đồng và phía đợt — phải thật sự tắt thì mới qua.
    ScheduleTotal::whileAmending($amended, function () use ($amended, $other) {
        $amended->update(['total_amount' => 130_000_000]);
        [$first, $second] = $amended->instalments()->get()->all();
        $first->update(['amount' => 80_000_000]);
        $second->update(['amount' => 50_000_000]);

        expect(fn () => $other->instalments()->first()->update(['amount' => 1]))
            ->toThrow(ContractTotalMismatch::class);
    });

    expect($amended->refresh()->total_amount)->toBe(130_000_000)
        ->and(fn () => $amended->instalments()->first()->update(['amount' => 1]))
        ->toThrow(ContractTotalMismatch::class);
});

it('restores the guard even when the amendment throws', function () {
    $contract = balancedActiveContract([60_000_000, 40_000_000]);

    try {
        ScheduleTotal::whileAmending($contract, fn () => throw new RuntimeException('phụ lục hỏng giữa chừng'));
    } catch (RuntimeException) {
    }

    expect(ScheduleTotal::isBeingAmended($contract->id))->toBeFalse()
        ->and(fn () => $contract->instalments()->first()->update(['amount' => 1]))
        ->toThrow(ContractTotalMismatch::class);
});

/**
 * Hook đọc lại hợp đồng và tổng các đợt từ DB. Một phiên cổng khách mở song song (hai panel dùng
 * chung cookie) bật `ClientPortalScope`, và bốn model tiền dưới scope đó trả KHÔNG hàng nào — một
 * lần đọc lại có scope sẽ thấy "không có hợp đồng" và âm thầm bỏ qua kiểm tra. Guard phải đứng
 * vững bất kể guard nào đang mở.
 */
it('holds while a client portal session is open', function () {
    $contract = balancedActiveContract([60_000_000, 40_000_000]);
    $clientUser = ClientUser::factory()->create(['client_id' => $contract->matter->client_id]);

    ClientPortalScope::actingAs($clientUser, function () use ($contract) {
        expect(fn () => $contract->instalments()->withoutGlobalScopes()->first()->update(['amount' => 1]))
            ->toThrow(ContractTotalMismatch::class)
            ->and(fn () => Instalment::factory()->for($contract)->create(['sequence' => 3, 'amount' => 1]))
            ->toThrow(ContractTotalMismatch::class)
            ->and(fn () => Contract::query()->withoutGlobalScopes()->find($contract->id)->update(['total_amount' => 1]))
            ->toThrow(ContractTotalMismatch::class);
    });
});
