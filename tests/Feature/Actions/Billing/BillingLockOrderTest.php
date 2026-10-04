<?php

use App\Actions\Billing\ActivateContract;
use App\Actions\Billing\AmendContract;
use App\Actions\Billing\CancelContract;
use App\Actions\Billing\CompleteContract;
use App\Actions\Billing\DeleteDraftContract;
use App\Actions\Billing\DraftContract;
use App\Actions\Billing\RecordPayment;
use App\Actions\Billing\TriggerInstalmentsForStage;
use App\Actions\Billing\UpdateDraftContract;
use App\Actions\Billing\VoidPayment;
use App\Actions\Billing\WaiveInstalment;
use App\Actions\Matter\CancelMatter;
use App\Actions\Matter\ReassignMatter;
use App\Actions\Matter\UpdateMatterDetails;
use App\Enums\ContractStatus;
use App\Enums\DocumentGroup;
use App\Enums\InstalmentStatus;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Models\Contract;
use App\Models\Document;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\Payment;
use App\Models\StageLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

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
 * **Và câu ĐẦU TIÊN bên trong transaction là khoá `matters`** (lượt sửa thứ hai sau rà soát cuối
 * M9, N1): một lần đọc thường nào đứng trước khoá đầu tiên sẽ đóng băng ảnh chụp REPEATABLE READ
 * ở thời điểm TRƯỚC lúc đợi khoá, và mọi con số đọc thường sau đó là số cũ (docblock
 * `LocksBillingRows`). {@see lockOrderOf()} khẳng định điều đó cho MỌI Action ở đây: câu SQL ngay
 * sau `BEGIN`/`SAVEPOINT` của Action là `select … from matters … for update`, và trước đó chỉ có
 * các lần thăm dò `select` không khoá. Kịch bản hai kết nối thật của cùng lỗi ở
 * `RecordPaymentConcurrencyTest`.
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
 * Tên bảng của mỗi câu có khoá `for update`, ĐÚNG THỨ TỰ đã chạy. Chỉ đọc từ `FROM <bảng>` đầu
 * tiên của mỗi câu — mọi truy vấn khoá của các Action tiền đều trên MỘT bảng, không JOIN.
 *
 * @param  list<string>  $statements
 * @return list<string>
 */
function lockedTableOrder(array $statements): array
{
    return collect($statements)
        ->filter(fn (string $sql): bool => str_contains(strtolower($sql), 'for update'))
        ->map(function (string $sql): ?string {
            preg_match('/from\s+`?(\w+)`?/i', $sql, $matches);

            return $matches[1] ?? null;
        })
        ->values()
        ->all();
}

/**
 * Chạy `$action`, trả thứ tự bảng bị khoá — và khẳng định luật N1 trên chính lần chạy đó: câu
 * ĐẦU TIÊN sau khi transaction của Action mở (`TransactionBeginning` — dưới `RefreshDatabase` là
 * một SAVEPOINT, cùng sự kiện) là lần đọc CÓ KHOÁ hàng `matters`, và mọi câu TRƯỚC nó chỉ là
 * thăm dò `select` không khoá (không ghi gì ngoài transaction).
 *
 * @return list<string>
 */
function lockOrderOf(callable $action): array
{
    /** @var list<string|null> $log `null` đánh dấu một lần mở transaction */
    $log = [];

    Event::listen(TransactionBeginning::class, function () use (&$log): void {
        $log[] = null;
    });
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$log): void {
        $log[] = $query->sql;
    });

    $action();

    $begin = array_search(null, $log, true);

    expect($begin)->not->toBeFalse();

    $before = array_slice($log, 0, $begin);
    $inside = array_values(array_filter(array_slice($log, $begin + 1), fn (?string $sql): bool => $sql !== null));

    expect($inside[0] ?? '')->toMatch('/^select \* from `matters` where .+ for update$/');

    foreach ($before as $probe) {
        expect(strtolower($probe))->toStartWith('select ')->not->toContain('for update');
    }

    return lockedTableOrder($inside);
}

beforeEach(function () {
    requireLockingMariadb();

    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->contract = Contract::factory()->for($this->matter)->active()->create(['total_amount' => 10_000_000]);
});

it('locks matters first, then contracts, then instalments, then the payments it sums, when recording a payment', function () {
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

    expect($order)->toBe(['matters', 'contracts', 'instalments', 'payments']);
});

it('locks matters first, then contracts, then instalments, then the payment, then the payments it sums, when voiding a payment', function () {
    $instalment = Instalment::factory()->for($this->contract)->create([
        'amount' => 10_000_000,
        'status' => InstalmentStatus::Paid,
    ]);
    $payment = Payment::factory()->for($instalment)->create([
        'amount' => 10_000_000,
        'attributed_lawyer_id' => $this->lead->id,
    ]);

    $order = lockOrderOf(fn () => app(VoidPayment::class)->handle($this->accountant, $payment, str_repeat('a', 20)));

    expect($order)->toBe(['matters', 'contracts', 'instalments', 'payments', 'payments']);
});

it('locks matters first, then contracts, then instalments, when waiving an instalment', function () {
    $instalment = Instalment::factory()->for($this->contract)->create([
        'amount' => 10_000_000,
        'status' => InstalmentStatus::Pending,
    ]);

    $order = lockOrderOf(fn () => app(WaiveInstalment::class)->handle($this->lead, $instalment, str_repeat('a', 20)));

    expect($order)->toBe(['matters', 'contracts', 'instalments']);
});

it('locks matters first, then contracts, then instalments, then the payments it sums, when completing a contract', function () {
    Instalment::factory()->for($this->contract)->create([
        'amount' => 10_000_000,
        'status' => InstalmentStatus::Paid,
    ]);

    $order = lockOrderOf(fn () => app(CompleteContract::class)->handle($this->lead, $this->contract));

    expect($order)->toBe(['matters', 'contracts', 'instalments', 'payments']);
});

it('locks matters first, then contracts, when cancelling a contract', function () {
    Instalment::factory()->for($this->contract)->create(['amount' => 10_000_000]);

    $order = lockOrderOf(fn () => app(CancelContract::class)->handle($this->lead, $this->contract, str_repeat('a', 20)));

    expect($order)->toBe(['matters', 'contracts']);
});

/**
 * M9 Task 6: hai khoá `instalments`, cùng chỗ trong chuỗi. Lần thứ nhất là tổng lịch thu
 * (`ScheduleTotal::lockedOf()`), lần thứ hai là các đợt `stage` còn chờ mà lõi của
 * `TriggerInstalmentsForStage::releaseLocked()` có thể kích hoạt ngay trong lần kích hoạt — vẫn
 * dưới khoá `matters` → `contracts` của chính `ActivateContract`, không mở transaction thứ hai.
 */
it('locks matters first, then contracts, then the instalments it sums, then the stage instalments it may release, when activating a draft', function () {
    $draft = Contract::factory()->for(Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]))
        ->create(['status' => ContractStatus::Draft, 'total_amount' => 10_000_000]);
    Instalment::factory()->for($draft)->create(['amount' => 10_000_000]);

    $order = lockOrderOf(fn () => app(ActivateContract::class)->handle($this->lead, $draft, today()->toDateString()));

    expect($order)->toBe(['matters', 'contracts', 'instalments', 'instalments']);
});

/**
 * M9 Task 6: đường listener/đối chiếu tự mở transaction tiền của nó — thăm dò hợp đồng và đợt chờ
 * NGOÀI transaction, rồi khoá `matters` (câu đầu tiên) → `contracts` → đúng các đợt chờ giai đoạn đó.
 */
it('locks matters first, then contracts, then the waiting instalments, when releasing stage-triggered instalments', function () {
    Instalment::factory()->for($this->contract)->onStage('filed')->create(['amount' => 10_000_000]);
    $entry = StageLog::factory()->for($this->matter)->transition('drafting', 'filed')->create();

    $order = lockOrderOf(fn () => app(TriggerInstalmentsForStage::class)->handle($this->matter, 'filed', $entry));

    expect($order)->toBe(['matters', 'contracts', 'instalments']);
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

/**
 * Chuỗi tiền trọn vẹn (`matters` → `contracts` → `instalments` → `payments` của chúng) khoá TRƯỚC
 * bản scan. Hai khoá sau bản scan không phải hàng MỚI của chuỗi tiền: `instalments` thứ hai là
 * lần kiểm lại tầng 3 (`ScheduleTotal::lockedOf()`) trên đúng các đợt đã khoá ở trên cộng đợt
 * vừa thêm của chính transaction này, `contract_amendments` là số thứ tự phụ lục — cả hai chỉ
 * `AmendContract` chạm tới, dưới khoá `contracts` của nó.
 */
it('locks matters first, then contracts, instalments and their payments, and the amendment scan after that chain, when amending', function () {
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

    expect($order)->toBe(['matters', 'contracts', 'instalments', 'payments', 'documents', 'instalments', 'contract_amendments']);
});

/**
 * Lượt rà soát Task 6, I1: phụ lục thêm một đợt `stage` cho giai đoạn vụ ĐÃ chạm thì kích hoạt nó
 * ngay (`TriggerInstalmentsForStage::releaseAddedByAmendment()`) — một khoá `instalments` nữa, trên
 * đúng các đợt vừa thêm (hàng của chính transaction này), SAU lần kiểm lại tầng 3 và TRƯỚC
 * `contract_amendments`, vẫn dưới khoá `matters` → `contracts` của phụ lục. Phụ lục không thêm đợt
 * `stage` nào (ở đây: một đợt `on_signing`) thì không khoá thêm gì.
 */
it('locks the stage instalments an amendment adds after the schedule re-check, and nothing more when it adds none', function (string $trigger, array $expected, int $released) {
    $civil = MatterType::factory()->withStages()->create(['code' => 'CIV']);
    $matter = Matter::factory()->for($civil, 'matterType')->atStage('filed')->create(['lead_lawyer_id' => $this->lead->id]);
    $contract = Contract::factory()->for($matter)->active()->create(['total_amount' => 10_000_000, 'signed_at' => today()->subMonth()->toDateString()]);
    Instalment::factory()->for($contract)->create(['amount' => 10_000_000]);
    StageLog::factory()->for($matter)->transition('drafting', 'filed')->create(['occurred_at' => today()->subWeek()]);

    $order = lockOrderOf(fn () => app(AmendContract::class)->handle(
        $this->lead,
        $contract,
        15_000_000,
        [['action' => AmendContract::ADD, 'name' => 'Đợt bổ sung', 'amount' => 5_000_000, 'trigger_type' => $trigger, 'trigger_stage_key' => 'filed']],
        str_repeat('a', 20),
        today()->toDateString(),
    ));

    expect($order)->toBe($expected)
        ->and(Instalment::query()->where('contract_id', $contract->id)->whereNotNull('triggered_by_stage_log_id')->count())->toBe($released);
})->with([
    'a stage row for a stage the matter reached' => ['stage', ['matters', 'contracts', 'instalments', 'payments', 'instalments', 'instalments', 'contract_amendments'], 1],
    'an on-signing row' => ['on_signing', ['matters', 'contracts', 'instalments', 'payments', 'instalments', 'contract_amendments'], 0],
]);

/**
 * Gộp M6.5 + M9 (xung đột 4): ba Action của VỤ VIỆC mở transaction riêng. Luật thứ tự khoá toàn dự
 * án đòi chúng khoá `matters` TRƯỚC — cùng câu đầu tiên với mọi Action tiền, nên hai bên chờ nhau
 * trên đúng một hàng thay vì khoá chéo — và không khoá một hàng tiền nào (chúng không gọi Action tiền
 * nào; `CancelMatter` chỉ ĐỌC dư nợ, dưới khoá vụ việc). Hợp đồng active của `beforeEach` không có
 * đợt nào, tức dư nợ 0: `CancelMatter` đi trọn tới xoá mềm, và hook `Matter::deleting` của M9 cũng
 * chạy trong cùng lần đo.
 */
it('locks matters first and never a money row when cancelling, editing or reassigning a matter', function (string $action) {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $newLead = User::factory()->withRole(Role::Lawyer)->create();

    $order = lockOrderOf(match ($action) {
        'cancel' => fn () => app(CancelMatter::class)->handle($this->matter, $admin, 'Mở nhầm khách hàng.'),
        'update' => fn () => app(UpdateMatterDetails::class)->handle($this->matter, $admin, ['title' => 'Tiêu đề mới sau khi sửa']),
        'reassign' => fn () => app(ReassignMatter::class)->handle(
            matter: $this->matter,
            actor: $admin,
            newLead: $newLead,
            reason: 'Luật sư phụ trách cũ nghỉ dài ngày.',
            keepOldLeadAsAssociate: false,
        ),
    });

    expect($order[0] ?? null)->toBe('matters')
        ->and(array_values(array_intersect($order, ['contracts', 'instalments', 'payments', 'contract_amendments'])))->toBe([]);
})->with(['cancel', 'update', 'reassign']);
