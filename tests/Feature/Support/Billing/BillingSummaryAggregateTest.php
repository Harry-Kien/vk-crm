<?php

use App\Enums\ContractStatus;
use App\Enums\InstalmentStatus;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Support\Billing\BillingSummary;
use Illuminate\Support\Facades\DB;

/**
 * M9 Task 8, phán quyết controller 1 ("SQL-aggregate outstanding"): {@see
 * BillingSummary::pendingInstalmentsQuery()} (cột `outstanding_amount`, dùng bởi trang "Công nợ")
 * và {@see BillingSummary::outstandingPerMatterExpression()} (dùng bởi cột "còn phải thu" của
 * `MattersTable`) phải tính RA ĐÚNG CÙNG con số với {@see Instalment::outstanding()} —
 * ba cách viết của cùng MỘT định nghĩa, không phải ba định nghĩa khác nhau tình cờ khớp.
 *
 * Dàn cảnh trộn (constraint (b), Task 4: tổng các đợt chưa huỷ phải khớp `total_amount` — soạn
 * nháp, thêm đủ đợt, rồi kích hoạt bằng một lần ghi thẳng):
 *  - `partial`  — thu một phần (4/10 triệu), còn dư nợ 6 triệu.
 *  - `paid`     — thu đủ, cột `status` đã đồng bộ, dư nợ 0.
 *  - `waived`   — miễn, dư nợ 0 dù `amount` không đổi (bất biến tổng vẫn tính đợt này).
 *  - `voided`   — có MỘT khoản thu đã huỷ (10 triệu): khoản đó không được trừ vào dư nợ, dư nợ vẫn
 *    đủ 10 triệu — đây là ca hay bị viết sai nhất khi chuyển phép SUM() sang SQL.
 * Cộng một hợp đồng THỨ HAI, `cancelled`, mang một đợt `pending` còn nguyên — không được lọt vào
 * cả hai cách tính gộp (constraint (a), Task 4), dù tự nó có `outstanding() > 0`.
 */
function aggregateFixture(): array
{
    $matter = Matter::factory()->create();
    $contract = Contract::factory()->for($matter)->create(['status' => ContractStatus::Draft, 'total_amount' => 40_000_000]);

    $partial = Instalment::factory()->for($contract)->create(['sequence' => 1, 'amount' => 10_000_000, 'due_date' => today()->subDays(2)->toDateString()]);
    $paid = Instalment::factory()->for($contract)->create(['sequence' => 2, 'amount' => 10_000_000, 'due_date' => today()->subDays(2)->toDateString()]);
    $waived = Instalment::factory()->for($contract)->create(['sequence' => 3, 'amount' => 10_000_000, 'due_date' => today()->subDays(2)->toDateString(), 'status' => InstalmentStatus::Waived, 'waived_reason' => str_repeat('a', 20), 'waived_at' => now()]);
    $voided = Instalment::factory()->for($contract)->create(['sequence' => 4, 'amount' => 10_000_000, 'due_date' => today()->subDays(2)->toDateString()]);

    Payment::factory()->for($partial)->create(['amount' => 4_000_000]);
    Payment::factory()->for($paid)->create(['amount' => 10_000_000]);
    $paid->fill(['status' => InstalmentStatus::Paid])->save();
    Payment::factory()->for($voided)->voided()->create(['amount' => 10_000_000]);

    $contract->fill(['status' => ContractStatus::Active, 'signed_at' => today()->subDay()->toDateString()])->save();

    $cancelledMatter = Matter::factory()->create();
    $cancelledContract = Contract::factory()->for($cancelledMatter)->cancelled()->create(['total_amount' => 5_000_000]);
    $stillPendingOnCancelled = Instalment::factory()->for($cancelledContract)->create(['amount' => 5_000_000, 'due_date' => today()->subDay()->toDateString()]);

    return compact('matter', 'contract', 'partial', 'paid', 'waived', 'voided', 'cancelledMatter', 'cancelledContract', 'stillPendingOnCancelled');
}

it('agrees with Instalment::outstanding() per instalment, across partial, waived, and voided-payment rows still pending', function () {
    $fixture = aggregateFixture();

    $byId = BillingSummary::pendingInstalmentsQuery()->get()->keyBy('id');

    foreach (['partial' => 6_000_000, 'paid' => 0, 'waived' => 0, 'voided' => 10_000_000] as $key => $expected) {
        $instalment = $fixture[$key];
        $fresh = $instalment->fresh();

        expect($fresh->outstanding())->toBe($expected, "sai ở outstanding() của ca: {$key}");

        // "waived" và "paid" không nằm trong pendingInstalmentsQuery() (status != pending, đúng
        // bộ lọc "pending instalments only" của phán quyết controller 1) — outstanding() của
        // chúng vẫn đúng là 0 ở trên (không qua SQL-aggregate), nhưng không có gì để so ở đây vì
        // hai đợt đó không xuất hiện trong tập kết quả. Chỉ "partial" và "voided" còn `pending`.
        if (in_array($key, ['waived', 'paid'], true)) {
            expect($byId->has($instalment->id))->toBeFalse("đợt {$key} lẽ ra không nằm trong pendingInstalmentsQuery() (status != pending)");

            continue;
        }

        expect($byId->has($instalment->id))->toBeTrue("thiếu đợt {$key} trong pendingInstalmentsQuery()")
            ->and((int) $byId[$instalment->id]->outstanding_amount)->toBe($expected, "sai ở outstanding_amount SQL của ca: {$key}")
            ->and((int) $byId[$instalment->id]->outstanding_amount)->toBe($fresh->outstanding(), "lệch với outstanding() ở ca: {$key}");
    }

    // Đợt pending của hợp đồng cancelled KHÔNG lọt vào, dù outstanding() riêng của nó > 0
    // (constraint (a), Task 4) — cặp âm chứng minh bộ lọc hợp đồng active có tác dụng thật.
    expect($fixture['stillPendingOnCancelled']->fresh()->outstanding())->toBe(5_000_000)
        ->and($byId->has($fixture['stillPendingOnCancelled']->id))->toBeFalse();
});

it('agrees with BillingSummary::outstandingForMatter() per matter, on the same mixed fixture', function () {
    $fixture = aggregateFixture();

    $expected = BillingSummary::outstandingForMatter($fixture['matter']->id)['amount'];
    expect($expected)->toBe(16_000_000); // 6tr (partial) + 0 (paid) + 0 (waived) + 10tr (voided payment ignored)

    $aggregate = (int) Matter::query()
        ->selectRaw('matters.id, ('.BillingSummary::outstandingPerMatterExpression().') as outstanding_balance_amount')
        ->whereKey($fixture['matter']->id)
        ->first()
        ->outstanding_balance_amount;

    expect($aggregate)->toBe($expected);

    // Vụ việc mang hợp đồng cancelled: cả hai cách tính đều ra 0, dù đợt pending còn đó.
    $cancelledExpected = BillingSummary::outstandingForMatter($fixture['cancelledMatter']->id)['amount'];
    $cancelledAggregate = (int) Matter::query()
        ->selectRaw('matters.id, ('.BillingSummary::outstandingPerMatterExpression().') as outstanding_balance_amount')
        ->whereKey($fixture['cancelledMatter']->id)
        ->first()
        ->outstanding_balance_amount;

    expect($cancelledExpected)->toBe(0)
        ->and($cancelledAggregate)->toBe(0);
});

/**
 * Chuẩn mutation probe (M4, CLAUDE.md): xoá đúng điều kiện "chỉ hợp đồng active" khỏi
 * `outstandingPerMatterExpression()` (bỏ `and c.status = 'active'`) và chạy lại đúng test trên —
 * ghi lại bằng chứng ĐỎ trong báo cáo Task 8. Khôi phục ngay sau khi ghi bằng chứng, không để lại
 * trong mã.
 */

/**
 * Ngưỡng truy vấn cho DANH SÁCH (không phải một vụ việc đơn lẻ): pendingInstalmentsQuery() phải
 * là MỘT round-trip cho toàn bộ danh sách, không một câu SUM() mỗi dòng (N+1 mà phán quyết
 * controller 1 yêu cầu xoá). Ngưỡng SO SÁNH 1 dòng với 5 dòng: nếu cách tính quay lại N+1, số truy
 * vấn của 5 dòng sẽ NHIỀU HƠN 1 dòng; SQL-aggregate giữ nguyên một câu bất kể có bao nhiêu dòng.
 */
it('runs the same number of queries for the pending-instalments listing whether there is one row or five', function () {
    aggregateFixture(); // 3 đợt còn trong danh sách (partial, paid, voided) trên MỘT vụ việc

    DB::enableQueryLog();
    DB::flushQueryLog();
    BillingSummary::pendingInstalmentsQuery()->get()->each(fn (Instalment $i) => $i->outstanding_amount);
    $queriesForThree = count(DB::getQueryLog());

    // Nhân lên gấp bốn lần số đợt (thêm ba dàn cảnh nữa, tổng 12 đợt còn trong danh sách).
    aggregateFixture();
    aggregateFixture();
    aggregateFixture();

    DB::flushQueryLog();
    BillingSummary::pendingInstalmentsQuery()->get()->each(fn (Instalment $i) => $i->outstanding_amount);
    $queriesForTwelve = count(DB::getQueryLog());

    DB::disableQueryLog();

    // Đúng MỘT câu SELECT bất kể số dòng — nếu ai đó thay lại bằng vòng lặp gọi outstanding()
    // (N+1), $queriesForTwelve sẽ gấp bốn $queriesForThree thay vì bằng nhau.
    expect($queriesForThree)->toBe(1)
        ->and($queriesForTwelve)->toBe(1);
});
