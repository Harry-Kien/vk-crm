<?php

use App\Enums\ContractStatus;
use App\Enums\InstalmentStatus;
use App\Enums\PaymentMethod;
use App\Filament\Portal\Pages\MatterProgress;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\Setting;
use App\Support\Billing\ClientPaymentSettings;
use App\Support\Billing\VietQr;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Làn nt Task 1 — "Lộ trình thanh toán" trên trang hồ sơ của cổng khách, sau công tắc
|--------------------------------------------------------------------------
|
| Công tắc TẮT (mặc định): trang hồ sơ đúng như trước làn nt — khối "Hợp đồng và thanh toán" của M9
| (P1) vẫn ở đó, nhưng không có phần "Lộ trình thanh toán", không thông tin chuyển khoản, không mã QR,
| và phần đó không chạy truy vấn tiền nào. BẬT: giá trị hợp đồng (sau phụ lục), đã thanh toán, đã miễn,
| còn lại, thông tin chuyển khoản, và cho mỗi đợt đã tới hạn còn phải trả: số tiền, nội dung chuyển
| khoản gợi ý, mã QR VietQR của đúng số tiền và nội dung đó.
|
| Mốc thời gian cố định 20/10/2026.
*/

const CPP_MARKER = 'NOIBO-LOTRINH-7Q2';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-20 09:00:00'));

    $this->clientA = Client::factory()->create(['name' => 'Khách hàng A']);
    $this->userA = ClientUser::factory()->activated()->create(['client_id' => $this->clientA->id]);

    $this->matterA = Matter::factory()->for($this->clientA)->create([
        'code' => 'VK-2026-DS-0042',
        'is_published_to_portal' => true,
    ]);

    $this->plan = cppSchedule($this->matterA);
});

/**
 * Hợp đồng `active` 58 triệu (phụ lục nâng từ 45 triệu): đợt 1 đã thu đủ 20 triệu; đợt 2 quá hạn
 * 15 triệu, thu một phần 5 triệu (cộng một khoản 3.333.000 đã huỷ); đợt 3 sắp đến hạn 8 triệu; đợt 4
 * theo giai đoạn 10 triệu chưa tới bước (không có ngày hạn); đợt 5 đã miễn 5 triệu; một đợt đã huỷ theo
 * phụ lục 7.777.000. Đã thanh toán 25 tr + đã miễn 5 tr + còn lại 28 tr = 58 tr.
 *
 * @return array<string, mixed>
 */
function cppSchedule(Matter $matter, string $code = 'HD-2026-0042'): array
{
    $contract = Contract::factory()->for($matter)->create([
        'code' => $code,
        'total_amount' => 58_000_000,
        'note' => 'Ghi chu '.CPP_MARKER,
    ]);

    $paid = Instalment::factory()->for($contract)->create(['sequence' => 1, 'name' => 'Tạm ứng khi ký', 'amount' => 20_000_000, 'due_date' => '2026-09-15', 'status' => InstalmentStatus::Paid]);
    $overdue = Instalment::factory()->for($contract)->create(['sequence' => 2, 'name' => 'Đợt 2 khi nộp hồ sơ', 'amount' => 15_000_000, 'due_date' => '2026-10-01', 'note' => 'Ghi chu dot '.CPP_MARKER]);
    $upcoming = Instalment::factory()->for($contract)->create(['sequence' => 3, 'name' => 'Đợt 3 trước phiên hoà giải', 'amount' => 8_000_000, 'due_date' => '2026-10-25']);
    $stage = Instalment::factory()->for($contract)->onStage('court_accepted')->create(['sequence' => 4, 'name' => 'Khi toà thụ lý', 'amount' => 10_000_000]);
    $waived = Instalment::factory()->for($contract)->waived('Ly do mien '.CPP_MARKER)->create(['sequence' => 5, 'name' => 'Phí đi lại', 'amount' => 5_000_000]);
    $cancelled = Instalment::factory()->for($contract)->cancelled()->create(['sequence' => 6, 'name' => 'Đợt đã bỏ CPPHUY', 'amount' => 7_777_000]);

    $contract->update(['status' => ContractStatus::Active, 'signed_at' => '2026-09-15']);

    Payment::factory()->for($paid)->create(['amount' => 20_000_000, 'paid_on' => '2026-09-16', 'method' => PaymentMethod::BankTransfer]);
    Payment::factory()->for($overdue)->create(['amount' => 5_000_000, 'paid_on' => '2026-10-02', 'method' => PaymentMethod::Cash]);
    Payment::factory()->for($overdue)->voided('Ghi nham '.CPP_MARKER)->create(['amount' => 3_333_000, 'paid_on' => '2026-10-03']);

    ContractAmendment::factory()->for($contract)->create(['sequence' => 1, 'previous_total_amount' => 45_000_000, 'new_total_amount' => 58_000_000, 'reason' => 'Ly do phu luc '.CPP_MARKER]);

    return compact('contract', 'paid', 'overdue', 'upcoming', 'stage', 'waived', 'cancelled');
}

/** Thông tin chuyển khoản rõ là giả; công tắc theo `$on`. Dữ liệu dựng thẳng — trang cấu hình có test riêng. */
function cppSettings(bool $on, bool $withBank = true): void
{
    $values = [ClientPaymentSettings::ENABLED => $on ? ClientPaymentSettings::ON : ClientPaymentSettings::OFF];

    if ($withBank) {
        $values += [
            ClientPaymentSettings::BANK_BIN => '970436',
            ClientPaymentSettings::ACCOUNT_NUMBER => '0071000123456',
            ClientPaymentSettings::ACCOUNT_HOLDER => 'CONG TY LUAT THU NGHIEM',
            ClientPaymentSettings::BANK_BRANCH => 'Chi nhánh Thử nghiệm',
        ];
    }

    foreach ($values as $key => $value) {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}

function cppHtml(ClientUser $user, Matter $matter): string
{
    return test()->actingAs($user, 'client')
        ->get(MatterProgress::getUrl(['record' => $matter->getKey()], panel: 'portal'))
        ->assertOk()
        ->getContent();
}

/** Phần "Lộ trình thanh toán", cắt từ mốc của nó tới hết khối tiền. `null` khi không có. */
function cppSection(string $html): ?string
{
    $start = strpos($html, 'data-portal-section="payment-plan"');

    if ($start === false) {
        return null;
    }

    $end = strpos($html, 'data-portal-block="7"', $start);

    return substr($html, $start, (int) $end - $start);
}

function cppQr(string $note, int $amount): string
{
    return VietQr::svgDataUri(VietQr::payload('970436', '0071000123456', $amount, $note));
}

// =========================================================================================
// Công tắc TẮT — đúng như trước
// =========================================================================================

/** Mutation probe: bỏ lần hỏi công tắc ở `paymentPlan()` → đỏ (phần lộ trình hiện ra). */
it('keeps the page as it was when the switch is OFF: the M9 billing block stays, no payment plan, no bank details', function () {
    cppSettings(false);

    $html = cppHtml($this->userA, $this->matterA);

    expect($html)->toContain('data-portal-block="billing"')
        ->and(cppSection($html))->toBeNull()
        ->and($html)->not->toContain(__('client_payments.portal.heading'))
        ->and($html)->not->toContain('0071000123456')
        ->and($html)->not->toContain('VK-2026-DS-0042 DOT 2')
        // Trang có ảnh `data:` khác (ảnh đại diện dựng tại chỗ), nên đo đúng mã QR của đợt.
        ->and($html)->not->toContain(cppQr('VK-2026-DS-0042 DOT 2', 10_000_000));
});

it('keeps the page as it was when nothing has ever been configured', function () {
    $html = cppHtml($this->userA, $this->matterA);

    expect($html)->toContain('data-portal-block="billing"')
        ->and(cppSection($html))->toBeNull();
});

/**
 * "Khi công tắc tắt: khối này không render và không có truy vấn tiền nào chạy cho tài khoản khách."
 * Đo trên chính hàm mà view gọi, trên một trang mới (chưa nhớ gì): TẮT → đúng MỘT truy vấn (dòng công
 * tắc), không chạm bảng tiền nào; BẬT → có đọc tiền.
 *
 * Mutation probe: dời lần hỏi công tắc xuống SAU `billingRecords()` → đỏ.
 */
it('runs no money query for the payment plan while the switch is OFF', function () {
    Filament::setCurrentPanel('portal');
    $this->actingAs($this->userA, 'client');

    $queriesOf = function (): array {
        $page = new MatterProgress;
        $page->mount($this->matterA->getKey());

        DB::flushQueryLog();
        DB::enableQueryLog();
        $plan = $page->paymentPlan();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return [$plan, $queries];
    };

    cppSettings(false);
    [$plan, $queries] = $queriesOf();

    $moneyTables = '/\b(contracts|instalments|payments|contract_amendments)\b/';

    expect($plan)->toBeNull()
        ->and($queries)->toHaveCount(1)
        ->and($queries[0])->toContain('settings')
        ->and(preg_grep($moneyTables, $queries))->toBe([]);

    cppSettings(true);
    [$plan, $queries] = $queriesOf();

    expect($plan)->not->toBeNull()
        ->and(preg_grep($moneyTables, $queries))->not->toBe([]);
});

// =========================================================================================
// Công tắc BẬT — đúng số
// =========================================================================================

it('shows contract value after amendment, collected, waived and outstanding that add up when the switch is ON', function () {
    cppSettings(true);

    $section = cppSection(cppHtml($this->userA, $this->matterA));

    expect($section)->not->toBeNull()
        ->toContain(__('client_payments.portal.heading'))
        ->toContain(e(__('client_payments.portal.contract_value', ['amount' => '58.000.000 ₫'])))
        ->toContain(e(__('client_payments.portal.collected', ['amount' => '25.000.000 ₫'])))
        ->toContain(e(__('client_payments.portal.waived', ['amount' => '5.000.000 ₫'])))
        ->toContain(e(__('client_payments.portal.outstanding', ['amount' => '28.000.000 ₫'])))
        // Khoản thu đã huỷ không vào "đã thanh toán"; đợt đã huỷ không vào đâu cả.
        ->not->toContain('3.333.000')
        ->not->toContain('7.777.000')
        ->not->toContain('CPPHUY');
});

/**
 * Chỉ đợt ĐÃ TỚI HẠN còn phải trả mới có nội dung chuyển khoản và mã QR: đợt quá hạn (còn 10 tr sau
 * khoản thu một phần) và đợt sắp đến hạn (8 tr). Đợt đã thu đủ, đã miễn, và đợt theo giai đoạn chưa tới
 * bước (chưa có ngày hạn) thì không.
 *
 * Mutation probe: bỏ điều kiện `due_date !== null` → đỏ (đợt theo giai đoạn có QR "DOT 4").
 */
it('lists each due instalment still owed with its amount left, transfer note and a QR of exactly that', function () {
    cppSettings(true);

    $section = cppSection(cppHtml($this->userA, $this->matterA));

    expect($section)
        ->toContain(e(__('client_payments.portal.transfer_note', ['note' => 'VK-2026-DS-0042 DOT 2'])))
        ->toContain(e(__('client_payments.portal.payable_amount', ['amount' => '10.000.000 ₫'])))
        ->toContain(cppQr('VK-2026-DS-0042 DOT 2', 10_000_000))
        ->toContain(e(__('client_payments.portal.transfer_note', ['note' => 'VK-2026-DS-0042 DOT 3'])))
        ->toContain(e(__('client_payments.portal.payable_amount', ['amount' => '8.000.000 ₫'])))
        ->toContain(cppQr('VK-2026-DS-0042 DOT 3', 8_000_000))
        ->toContain(e(__('enums.instalment_state.overdue')))
        ->not->toContain('DOT 1')
        ->not->toContain('DOT 4')
        ->not->toContain('DOT 5');

    expect(substr_count($section, 'data:image/svg+xml;base64,'))->toBe(2);
});

it('prints the office bank details next to the instalments to pay', function () {
    cppSettings(true);

    $section = cppSection(cppHtml($this->userA, $this->matterA));

    expect($section)
        ->toContain(e(__('client_payments.portal.bank', ['name' => 'Vietcombank — Ngân hàng TMCP Ngoại thương Việt Nam'])))
        ->toContain(e(__('client_payments.portal.account_number', ['number' => '0071000123456'])))
        ->toContain(e(__('client_payments.portal.account_holder', ['holder' => 'CONG TY LUAT THU NGHIEM'])))
        ->toContain(e(__('client_payments.portal.bank_branch', ['branch' => 'Chi nhánh Thử nghiệm'])));
});

/** Mutation probe: bỏ kiểm `transferAccount() === null` → lỗi 500 / QR từ tài khoản trống. */
it('drops bank details and QR codes, keeping the totals and notes, when the stored bank details are incomplete', function () {
    cppSettings(true, withBank: false);

    $section = cppSection(cppHtml($this->userA, $this->matterA));

    expect($section)->not->toBeNull()
        ->toContain(e(__('client_payments.portal.outstanding', ['amount' => '28.000.000 ₫'])))
        ->toContain('VK-2026-DS-0042 DOT 2')
        ->not->toContain(__('client_payments.portal.transfer_heading'))
        ->not->toContain('data:image/svg+xml;base64,');
});

it('reflects a payment recorded later at the next page load', function () {
    cppSettings(true);

    Payment::factory()->for($this->plan['upcoming'])->create(['amount' => 8_000_000, 'paid_on' => '2026-10-20']);
    $this->plan['upcoming']->update(['status' => InstalmentStatus::Paid]);

    $section = cppSection(cppHtml($this->userA, $this->matterA));

    expect($section)
        ->toContain(e(__('client_payments.portal.collected', ['amount' => '33.000.000 ₫'])))
        ->toContain(e(__('client_payments.portal.outstanding', ['amount' => '20.000.000 ₫'])))
        ->not->toContain('DOT 3');
});

it('says the contract is settled and shows no bank details on a completed contract', function () {
    cppSettings(true);

    $matter = Matter::factory()->for($this->clientA)->create(['code' => 'VK-2026-DS-0050', 'is_published_to_portal' => true]);
    $contract = Contract::factory()->for($matter)->create(['total_amount' => 9_000_000]);
    $instalment = Instalment::factory()->for($contract)->create(['sequence' => 1, 'amount' => 9_000_000, 'due_date' => '2026-09-01']);
    $contract->update(['status' => ContractStatus::Active, 'signed_at' => '2026-08-20']);
    Payment::factory()->for($instalment)->create(['amount' => 9_000_000, 'paid_on' => '2026-09-01']);
    $instalment->update(['status' => InstalmentStatus::Paid]);
    $contract->update(['status' => ContractStatus::Completed, 'ended_at' => '2026-10-05']);

    $section = cppSection(cppHtml($this->userA, $matter));

    expect($section)->not->toBeNull()
        ->toContain(e(__('client_payments.portal.collected', ['amount' => '9.000.000 ₫'])))
        ->toContain(e(__('client_payments.portal.outstanding', ['amount' => '0 ₫'])))
        ->toContain(__('client_payments.portal.settled'))
        ->not->toContain('0071000123456')
        ->not->toContain('data:image/svg+xml;base64,');

    // `paymentPlan()` là hàm công khai (gọi được từ trình duyệt): hợp đồng không còn gì phải trả thì
    // thông tin chuyển khoản không đi ra cả trong mảng trả về, không chỉ trên view.
    // Mutation probe: bỏ `$payables !== []` ở `ClientPaymentPlan::present()` → đỏ.
    Filament::setCurrentPanel('portal');
    $page = new MatterProgress;
    $page->mount($matter->getKey());

    expect($page->paymentPlan())->toMatchArray(['settled' => true, 'transfer' => null, 'payables' => []]);
});

it('shows no payment plan for a draft or a cancelled contract', function (ContractStatus $status) {
    cppSettings(true);

    $matter = Matter::factory()->for($this->clientA)->create(['is_published_to_portal' => true]);
    $contract = Contract::factory()->for($matter)->create(['total_amount' => 9_000_000]);
    Instalment::factory()->for($contract)->create(['sequence' => 1, 'amount' => 9_000_000, 'due_date' => '2026-10-01']);

    if ($status === ContractStatus::Cancelled) {
        $contract->update(['status' => ContractStatus::Cancelled, 'signed_at' => '2026-08-20', 'ended_at' => '2026-10-05', 'ended_reason' => 'Khách hàng chấm dứt hợp đồng trước hạn theo thoả thuận.']);
    }

    $html = cppHtml($this->userA, $matter);

    expect(cppSection($html))->toBeNull()
        ->and($html)->not->toContain('0071000123456');
})->with([ContractStatus::Draft, ContractStatus::Cancelled]);

// =========================================================================================
// Ranh giới cổng
// =========================================================================================

it('shows client B only the payment plan of client B, and gives client B a 404 on the matter of client A', function () {
    cppSettings(true);

    $clientB = Client::factory()->create();
    $userB = ClientUser::factory()->activated()->create(['client_id' => $clientB->id]);
    $matterB = Matter::factory()->for($clientB)->create(['code' => 'VK-2026-DS-0077', 'is_published_to_portal' => true]);
    $contractB = Contract::factory()->for($matterB)->create(['total_amount' => 6_000_000]);
    Instalment::factory()->for($contractB)->create(['sequence' => 1, 'amount' => 6_000_000, 'due_date' => '2026-10-10']);
    $contractB->update(['status' => ContractStatus::Active, 'signed_at' => '2026-10-01']);

    $section = cppSection(cppHtml($userB, $matterB));

    expect($section)->toContain('VK-2026-DS-0077 DOT 1')
        ->toContain(e(__('client_payments.portal.outstanding', ['amount' => '6.000.000 ₫'])))
        ->not->toContain('VK-2026-DS-0042')
        ->not->toContain('28.000.000');

    $this->actingAs($userB, 'client')
        ->get(MatterProgress::getUrl(['record' => $this->matterA->getKey()], panel: 'portal'))
        ->assertNotFound();
});

it('serialises only formatted strings from paymentPlan(), never an internal note, reason or id', function () {
    cppSettings(true);

    Filament::setCurrentPanel('portal');
    $this->actingAs($this->userA, 'client');

    $page = new MatterProgress;
    $page->mount($this->matterA->getKey());

    $plan = $page->paymentPlan();
    $json = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    expect($plan)->toBeArray()
        ->and($json)->not->toContain(CPP_MARKER)
        ->not->toContain('"id"')
        ->not->toContain('contract_id')
        ->not->toContain('waived_reason')
        ->not->toContain('"note":"Ghi');

    array_walk_recursive($plan, fn (mixed $value) => expect($value === null || is_string($value) || is_bool($value))->toBeTrue());
});
