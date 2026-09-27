<?php

use App\Actions\Billing\ActivateContract;
use App\Actions\Billing\AmendContract;
use App\Actions\Billing\CancelContract;
use App\Actions\Billing\CompleteContract;
use App\Actions\Billing\DeleteDraftContract;
use App\Actions\Billing\DraftContract;
use App\Actions\Billing\RecordPayment;
use App\Actions\Billing\UpdateDraftContract;
use App\Actions\Billing\VoidPayment;
use App\Actions\Billing\WaiveInstalment;
use App\Enums\ContractStatus;
use App\Enums\DocumentGroup;
use App\Enums\InstalmentStatus;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Models\Contract;
use App\Models\Document;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Thứ tự khoá hàng của MỌI Action tiền — phán quyết toàn dự án (controller M6.5, 2026-09-27):
 * **`matters` TRƯỚC, rồi `contracts` → `instalments` → `payments`**; hàng nào khác (bản scan
 * `documents`, `code_sequences`) khoá SAU chuỗi đó. Định nghĩa ở MỘT chỗ,
 * `App\Actions\Billing\Concerns\LocksBillingRows`.
 *
 * Nguồn gốc: rà soát vòng 1, Task 5, I1 — test "hai kế toán" trong `RecordPaymentTest` gọi
 * `RecordPayment::handle()` HAI LẦN LIÊN TIẾP trong MỘT tiến trình, nên một hồi quy XOÁ SẠCH mọi
 * `lockForUpdate()` vẫn qua được nó. **Phán quyết controller: không dựng test hai kết nối thật.**
 * Thay vào đó đọc QUERY LOG và khẳng định mỗi Action THỰC SỰ phát ra đúng những khoá `for update`
 * mà docblock của nó tuyên bố, ĐÚNG THỨ TỰ bảng. Một `lockForUpdate()` bị xoá thì dòng `for update`
 * tương ứng biến mất khỏi log; hai khoá bị đảo thì danh sách đổi thứ tự — RED ngay cả khi không
 * một race nào chạy.
 *
 * **Chỉ chứng minh được trên MariaDB thật.** `MySqlGrammar::compileLock()` trả `'for update'`;
 * `SQLiteGrammar::compileLock()` (bộ test mặc định chạy trên đó) trả CHUỖI RỖNG — cú pháp khoá
 * hàng không tồn tại ở SQLite, nên không có gì để đọc từ log. Tự bỏ qua kèm lý do khi không chạy
 * trên MariaDB, cùng thành ngữ `LoginTest::requirePortalMariadb()`.
 */
function requireLockingMariadb(): void
{
    $driver = DB::connection()->getDriverName();

    if (in_array($driver, ['mysql', 'mariadb'], true)) {
        return;
    }

    test()->markTestSkipped(
        "Cần chạy trên chính MariaDB: SQLite không phát ra 'for update' (SQLiteGrammar::compileLock() "
        .'trả chuỗi rỗng), nên không có gì để đọc từ query log. Chạy lại với bin/dev test:mariadb. '
        ."Đang chạy trên: [{$driver}]."
    );
}

/**
 * Tên bảng của mỗi truy vấn có khoá `for update` trong query log hiện tại, ĐÚNG THỨ TỰ đã chạy.
 * Chỉ đọc từ `FROM <bảng>` đầu tiên của mỗi câu — mọi truy vấn khoá của các Action tiền đều là
 * `whereKey(...)`/`where(...)->lockForUpdate()` trên MỘT bảng, không JOIN.
 *
 * @return list<string>
 */
function lockedTableOrder(): array
{
    return collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $sql): bool => str_contains(strtolower($sql), 'for update'))
        ->map(function (string $sql): ?string {
            preg_match('/from\s+`?(\w+)`?/i', $sql, $matches);

            return $matches[1] ?? null;
        })
        ->values()
        ->all();
}

/**
 * Chạy `$action` với query log bật, trả thứ tự bảng bị khoá.
 *
 * @return list<string>
 */
function lockOrderOf(callable $action): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $action();

    $order = lockedTableOrder();
    DB::disableQueryLog();

    return $order;
}

beforeEach(function () {
    requireLockingMariadb();

    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->contract = Contract::factory()->for($this->matter)->active()->create(['total_amount' => 10_000_000]);
});

it('locks matters first, then contracts, then instalments, when recording a payment', function () {
    $instalment = Instalment::factory()->for($this->contract)->create([
        'amount' => 10_000_000,
        'status' => InstalmentStatus::Pending,
    ]);

    $order = lockOrderOf(fn () => app(RecordPayment::class)->handle(
        $this->accountant,
        $instalment,
        4_000_000,
        today(),
        PaymentMethod::BankTransfer,
        null,
        null,
        null,
    ));

    expect($order)->toBe(['matters', 'contracts', 'instalments']);
});

it('locks matters first, then contracts, then instalments, then payments, when voiding a payment', function () {
    $instalment = Instalment::factory()->for($this->contract)->create([
        'amount' => 10_000_000,
        'status' => InstalmentStatus::Paid,
    ]);
    $payment = Payment::factory()->for($instalment)->create([
        'amount' => 10_000_000,
        'attributed_lawyer_id' => $this->lead->id,
    ]);

    $order = lockOrderOf(fn () => app(VoidPayment::class)->handle($this->accountant, $payment, str_repeat('a', 20)));

    expect($order)->toBe(['matters', 'contracts', 'instalments', 'payments']);
});

it('locks matters first, then contracts, then instalments, when waiving an instalment', function () {
    $instalment = Instalment::factory()->for($this->contract)->create([
        'amount' => 10_000_000,
        'status' => InstalmentStatus::Pending,
    ]);

    $order = lockOrderOf(fn () => app(WaiveInstalment::class)->handle($this->lead, $instalment, str_repeat('a', 20)));

    expect($order)->toBe(['matters', 'contracts', 'instalments']);
});

it('locks matters first, then contracts, then instalments, when completing a contract', function () {
    Instalment::factory()->for($this->contract)->create([
        'amount' => 10_000_000,
        'status' => InstalmentStatus::Paid,
    ]);

    $order = lockOrderOf(fn () => app(CompleteContract::class)->handle($this->lead, $this->contract));

    expect($order)->toBe(['matters', 'contracts', 'instalments']);
});

it('locks matters first, then contracts, when cancelling a contract', function () {
    Instalment::factory()->for($this->contract)->create(['amount' => 10_000_000]);

    $order = lockOrderOf(fn () => app(CancelContract::class)->handle($this->lead, $this->contract, str_repeat('a', 20)));

    expect($order)->toBe(['matters', 'contracts']);
});

it('locks matters first, then contracts, when activating a draft', function () {
    $draft = Contract::factory()->for(Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]))
        ->create(['status' => ContractStatus::Draft, 'total_amount' => 10_000_000]);
    Instalment::factory()->for($draft)->create(['amount' => 10_000_000]);

    $order = lockOrderOf(fn () => app(ActivateContract::class)->handle($this->lead, $draft, today()->toDateString()));

    expect($order)->toBe(['matters', 'contracts']);
});

it('locks matters first, then contracts, when updating a draft', function () {
    $draft = Contract::factory()->for(Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]))
        ->create(['status' => ContractStatus::Draft, 'total_amount' => 10_000_000]);

    $order = lockOrderOf(fn () => app(UpdateDraftContract::class)->handle($this->lead, $draft, ['total_amount' => 20_000_000], [
        ['name' => 'Trọn gói', 'amount' => 20_000_000, 'trigger_type' => 'on_signing'],
    ]));

    expect($order)->toBe(['matters', 'contracts']);
});

it('locks matters first, then contracts, when deleting a draft', function () {
    $draft = Contract::factory()->for(Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]))
        ->create(['status' => ContractStatus::Draft, 'total_amount' => 10_000_000]);
    Instalment::factory()->for($draft)->create(['amount' => 10_000_000]);

    $order = lockOrderOf(fn () => app(DeleteDraftContract::class)->handle($this->lead, $draft));

    expect($order)->toBe(['matters', 'contracts']);
});

it('locks matters first, then the code sequence, when drafting a contract', function () {
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);

    $order = lockOrderOf(fn () => app(DraftContract::class)->handle($this->lead, $matter, ['total_amount' => 10_000_000], [
        ['name' => 'Trọn gói', 'amount' => 10_000_000, 'trigger_type' => 'on_signing'],
    ]));

    expect($order)->toBe(['matters', 'code_sequences']);
});

it('locks matters first, then contracts, then instalments, and the amendment scan last, when amending', function () {
    $instalment = Instalment::factory()->for($this->contract)->create([
        'amount' => 10_000_000,
        'status' => InstalmentStatus::Pending,
    ]);
    $scan = Document::factory()->group(DocumentGroup::Internal)->create(['matter_id' => $this->matter->id]);

    $order = lockOrderOf(fn () => app(AmendContract::class)->handle(
        $this->lead,
        $this->contract,
        12_000_000,
        [['action' => AmendContract::UPDATE, 'instalment_id' => $instalment->id, 'amount' => 12_000_000]],
        str_repeat('a', 20),
        today()->toDateString(),
        $scan,
    ));

    expect($order)->toBe(['matters', 'contracts', 'instalments', 'documents']);
});
