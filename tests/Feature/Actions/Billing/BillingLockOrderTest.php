<?php

use App\Actions\Billing\RecordPayment;
use App\Actions\Billing\VoidPayment;
use App\Actions\Billing\WaiveInstalment;
use App\Enums\InstalmentStatus;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Rà soát vòng 1, Task 5, I1 (Important). Test "hai kế toán" trong `RecordPaymentTest` gọi
 * `RecordPayment::handle()` HAI LẦN LIÊN TIẾP trong MỘT tiến trình (giới hạn đã ghi ở đó, cùng
 * thành ngữ `TeamMemberTest`) — một hồi quy XOÁ SẠCH mọi `lockForUpdate()` vẫn qua được test đó,
 * vì phép cộng dồn vẫn đúng khi không có tranh chấp thật giữa hai kết nối.
 *
 * **Phán quyết controller: không dựng test hai kết nối thật.** Thay vào đó, đọc QUERY LOG và
 * khẳng định mỗi Action THỰC SỰ phát ra đúng những khoá `for update` mà docblock của nó tuyên bố,
 * ĐÚNG THỨ TỰ bảng. Một `lockForUpdate()` bị xoá thì dòng `for update` tương ứng biến mất khỏi
 * log — RED ngay cả khi không một race nào chạy, đúng thứ I1 muốn bắt.
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
 * Chỉ đọc từ `FROM <bảng>` đầu tiên của mỗi câu — mọi truy vấn khoá của ba Action này đều là
 * `whereKey(...)->lockForUpdate()->first()/firstOrFail()` trên một bảng duy nhất, không JOIN.
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

beforeEach(function () {
    requireLockingMariadb();

    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->contract = Contract::factory()->for($this->matter)->active()->create(['total_amount' => 10_000_000]);
});

it('locks contracts, then instalments, then matters, in that order, when recording a payment', function () {
    $instalment = Instalment::factory()->for($this->contract)->create([
        'amount' => 10_000_000,
        'status' => InstalmentStatus::Pending,
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(RecordPayment::class)->handle(
        $this->accountant,
        $instalment,
        4_000_000,
        today(),
        PaymentMethod::BankTransfer,
        null,
        null,
        null,
    );

    expect(lockedTableOrder())->toBe(['contracts', 'instalments', 'matters']);
});

it('locks contracts, then instalments, then payments, in that order, when voiding a payment', function () {
    $instalment = Instalment::factory()->for($this->contract)->create([
        'amount' => 10_000_000,
        'status' => InstalmentStatus::Paid,
    ]);
    $payment = Payment::factory()->for($instalment)->create([
        'amount' => 10_000_000,
        'attributed_lawyer_id' => $this->lead->id,
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(VoidPayment::class)->handle($this->accountant, $payment, str_repeat('a', 20));

    expect(lockedTableOrder())->toBe(['contracts', 'instalments', 'payments']);
});

it('locks contracts, then instalments, in that order, when waiving an instalment', function () {
    $instalment = Instalment::factory()->for($this->contract)->create([
        'amount' => 10_000_000,
        'status' => InstalmentStatus::Pending,
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(WaiveInstalment::class)->handle($this->lead, $instalment, str_repeat('a', 20));

    expect(lockedTableOrder())->toBe(['contracts', 'instalments']);
});
