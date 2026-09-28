<?php

use App\Actions\Billing\RecordPayment;
use App\Enums\ContractStatus;
use App\Enums\DocumentGroup;
use App\Enums\InstalmentState;
use App\Enums\InstalmentStatus;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Exceptions\InstalmentNotPayable;
use App\Exceptions\PaymentExceedsInstalment;
use App\Models\Contract;
use App\Models\Document;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\User;
use App\Support\Billing\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();
    $this->manager = User::factory()->withRole(Role::Manager)->create();
    $this->admin = User::factory()->withRole(Role::Admin)->create();

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->contract = Contract::factory()->for($this->matter)->active()->create(['total_amount' => 10_000_000]);
    $this->instalment = Instalment::factory()->for($this->contract)->create([
        'amount' => 10_000_000,
        'due_date' => today()->subDay()->toDateString(),
        'status' => InstalmentStatus::Pending,
    ]);
});

/** @param  array<string, mixed>  $overrides */
function recordFor(User $actor, Instalment $instalment, array $overrides = []): Payment
{
    return app(RecordPayment::class)->handle(
        $actor,
        $instalment,
        $overrides['amount'] ?? 4_000_000,
        $overrides['paid_on'] ?? today(),
        $overrides['method'] ?? PaymentMethod::BankTransfer,
        $overrides['reference'] ?? null,
        $overrides['receipt'] ?? null,
        $overrides['note'] ?? null,
    );
}

// --- Đường chính: một phần, đủ, vượt -------------------------------------------------------------

it('records a partial payment, leaving the instalment pending and displaying partially_paid while not yet due', function () {
    $this->instalment->forceFill(['due_date' => today()->addDays(5)->toDateString()])->save();

    $payment = recordFor($this->accountant, $this->instalment, ['amount' => 4_000_000]);

    expect($payment->amount)->toBe(4_000_000)
        ->and($this->instalment->fresh()->status)->toBe(InstalmentStatus::Pending)
        ->and($this->instalment->fresh()->state())->toBe(InstalmentState::PartiallyPaid);
});

/** Lượt rà soát cuối M9, I1: đợt đã quá hạn vẫn QUÁ HẠN sau một khoản thu một phần — còn nợ là còn nợ. */
it('keeps a past-due instalment overdue after a partial payment', function () {
    recordFor($this->accountant, $this->instalment, ['amount' => 4_000_000]);

    expect($this->instalment->fresh()->state())->toBe(InstalmentState::Overdue);
});

it('marks the instalment paid once collected reaches its amount, across two payments', function () {
    recordFor($this->accountant, $this->instalment, ['amount' => 4_000_000]);
    recordFor($this->accountant, $this->instalment, ['amount' => 6_000_000]);

    expect($this->instalment->fresh()->status)->toBe(InstalmentStatus::Paid)
        // (int): MariaDB trả SUM() của một cột nguyên là chuỗi số qua PDO (kiểu DECIMAL), khác
        // SQLite trả thẳng int — ép kiểu ở phép so sánh test, không phải một điều Action phải lo
        // (các Action đã tự ép `(int)` mọi lần đọc SUM() nội bộ).
        ->and((int) Payment::query()->where('instalment_id', $this->instalment->id)->sum('amount'))->toBe(10_000_000);
});

it('accepts a single payment for exactly the full amount', function () {
    $payment = recordFor($this->accountant, $this->instalment, ['amount' => 10_000_000]);

    expect($payment->amount)->toBe(10_000_000)
        ->and($this->instalment->fresh()->status)->toBe(InstalmentStatus::Paid);
});

it('refuses an overpayment with a readable message, and does not record anything, nor spill to the next instalment', function () {
    recordFor($this->accountant, $this->instalment, ['amount' => 4_000_000]);

    expect(fn () => recordFor($this->accountant, $this->instalment, ['amount' => 6_000_001]))
        ->toThrow(PaymentExceedsInstalment::class, __('billing.errors.payment_exceeds_instalment', [
            'name' => $this->instalment->name,
            'amount' => Money::format(6_000_001),
            'remaining' => Money::format(6_000_000),
        ]))
        ->and(Payment::count())->toBe(1)
        ->and($this->instalment->fresh()->status)->toBe(InstalmentStatus::Pending);
});

// --- paid_on ---------------------------------------------------------------------------------

it('refuses a paid_on date in the future', function () {
    expect(fn () => recordFor($this->accountant, $this->instalment, ['paid_on' => today()->addDay()]))
        ->toThrow(ValidationException::class)
        ->and(Payment::count())->toBe(0);
});

it('accepts paid_on as today', function () {
    expect(recordFor($this->accountant, $this->instalment, ['paid_on' => today()])->paid_on->toDateString())
        ->toBe(today()->toDateString());
});

// --- Actor tường minh, không phải phiên đăng nhập -------------------------------------------------

it('records the actor passed in as author, not whoever holds the session', function () {
    $this->actingAs($this->admin, 'web');

    $payment = recordFor($this->accountant, $this->instalment);

    expect($payment->created_by)->toBe($this->accountant->id);

    $audit = Activity::query()->where('event', 'payment_recorded')->sole();

    expect($audit->causer_id)->toBe($this->accountant->id)
        ->and($audit->subject_id)->toBe($payment->id);
});

// --- P2: attributed_lawyer_id chốt tại lúc ghi ----------------------------------------------------

it('attributes the payment to the lead lawyer at the time of recording, and keeps it after a reassignment', function () {
    $payment = recordFor($this->accountant, $this->instalment, ['amount' => 4_000_000]);

    expect($payment->attributed_lawyer_id)->toBe($this->lead->id);

    // `ReassignMatter` (M6.5 Task 4) chưa tồn tại trong nhánh này — xem báo cáo. Mô phỏng đúng
    // điều nó sẽ làm: đổi thẳng `lead_lawyer_id`.
    $newLead = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter->update(['lead_lawyer_id' => $newLead->id]);

    expect($payment->fresh()->attributed_lawyer_id)->toBe($this->lead->id);

    // Một khoản thu MỚI, sau khi bàn giao, theo đúng luật sư phụ trách MỚI — chứng minh giá trị
    // được đọc lại mỗi lần từ matters.lead_lawyer_id, không phải một giá trị nhớ sẵn.
    $second = recordFor($this->accountant, $this->instalment, ['amount' => 6_000_000]);

    expect($second->attributed_lawyer_id)->toBe($newLead->id);
});

// --- Quyền -------------------------------------------------------------------------------------

it('refuses a manager, who can only view the money, not record it', function () {
    expect(fn () => recordFor($this->manager, $this->instalment))->toThrow(AuthorizationException::class)
        ->and(Payment::count())->toBe(0);

    // Cặp dương trên CÙNG đợt: kế toán ghi được bình thường.
    expect(recordFor($this->accountant, $this->instalment)->id)->toBeInt();
});

it('lets the lead lawyer of a restricted matter record a payment without payment.record, but refuses the accountant on that matter', function () {
    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lead->id]);
    $contract = Contract::factory()->for($restricted)->active()->create(['total_amount' => 5_000_000]);
    $instalment = Instalment::factory()->for($contract)->create(['amount' => 5_000_000]);

    expect(recordFor($this->lead, $instalment, ['amount' => 1_000_000])->id)->toBeInt();

    expect(fn () => recordFor($this->accountant, $instalment, ['amount' => 1_000_000]))
        ->toThrow(AuthorizationException::class)
        ->and(Payment::query()->where('instalment_id', $instalment->id)->count())->toBe(1);
});

// --- Trạng thái đợt và hợp đồng phải cho phép -------------------------------------------------------

it('refuses to record a payment on an instalment that is not pending', function () {
    $this->instalment->fill(['status' => InstalmentStatus::Waived, 'waived_reason' => str_repeat('a', 20), 'waived_at' => now()])->save();

    expect(fn () => recordFor($this->accountant, $this->instalment))
        ->toThrow(InstalmentNotPayable::class)
        ->and(Payment::count())->toBe(0);
});

it('refuses to record a payment against a contract that is no longer active, even though the instalment column still reads pending', function () {
    // Constraint (a), Task 4: CancelContract không chạm tới đợt nào — một đợt vẫn `pending` sau
    // khi hợp đồng đã huỷ. Ghi thẳng trạng thái để cô lập ĐÚNG điều kiện này.
    $this->contract->fill(['status' => ContractStatus::Cancelled, 'ended_at' => today(), 'ended_reason' => str_repeat('a', 20)])->save();

    expect(fn () => recordFor($this->accountant, $this->instalment))
        ->toThrow(InstalmentNotPayable::class)
        ->and(Payment::count())->toBe(0);
});

// --- Bản scan biên lai (nhóm D) ------------------------------------------------------------------

it('accepts an internal-group receipt document belonging to the same matter', function () {
    $document = Document::factory()->for($this->matter)->group(DocumentGroup::Internal)->create();

    $payment = recordFor($this->accountant, $this->instalment, ['receipt' => $document]);

    expect($payment->receipt_document_id)->toBe($document->id);
});

it('refuses a receipt document that is not group-D internal', function () {
    $document = Document::factory()->for($this->matter)->group(DocumentGroup::Issued)->create();

    expect(fn () => recordFor($this->accountant, $this->instalment, ['receipt' => $document]))
        ->toThrow(ValidationException::class)
        ->and(Payment::count())->toBe(0);
});

it('refuses a receipt document belonging to a different matter', function () {
    $otherMatter = Matter::factory()->create();
    $document = Document::factory()->for($otherMatter)->group(DocumentGroup::Internal)->create();

    expect(fn () => recordFor($this->accountant, $this->instalment, ['receipt' => $document]))
        ->toThrow(ValidationException::class)
        ->and(Payment::count())->toBe(0);
});

// --- reference / note ----------------------------------------------------------------------------

it('reads a blank reference and a blank note as nothing, and trims a reference', function () {
    $payment = recordFor($this->accountant, $this->instalment, ['reference' => '  UNC-001  ', 'note' => '   ']);

    expect($payment->reference)->toBe('UNC-001')
        ->and($payment->note)->toBeNull();
});

it('refuses a reference longer than the column', function () {
    expect(fn () => recordFor($this->accountant, $this->instalment, ['reference' => str_repeat('a', 101)]))
        ->toThrow(ValidationException::class)
        ->and(Payment::count())->toBe(0);
});

// --- Concurrency (MariaDB, tuần tự — xem báo cáo về giới hạn của test này) -------------------------

/**
 * `bin/dev test:mariadb` bắt buộc cho task này (khoá `lockForUpdate`). Cùng giới hạn đã ghi ở
 * `TeamMemberTest` ("turns a duplicate add through the action itself into a clean refusal"): một
 * tiến trình PHP đơn luồng không tái hiện được HAI KẾT NỐI THẬT tranh chấp cùng lúc, nên test này
 * gọi `RecordPayment::handle()` HAI LẦN LIÊN TIẾP (giả lập chính xác "hai kế toán cùng ghi trên
 * một đợt") và khẳng định: dưới MariaDB thật, `lockForUpdate()` + đọc lại tổng trong transaction
 * khiến lần ghi thứ hai thấy đúng số đã ghi ở lần đầu — không vượt tổng, `status` đúng.
 */
it('keeps the running total correct and the status right when two accountants record on the same instalment one after another', function () {
    $second = User::factory()->withRole(Role::Accountant)->create();

    recordFor($this->accountant, $this->instalment, ['amount' => 6_000_000]);
    recordFor($second, $this->instalment, ['amount' => 4_000_000]);

    expect((int) Payment::query()->where('instalment_id', $this->instalment->id)->sum('amount'))->toBe(10_000_000)
        ->and($this->instalment->fresh()->status)->toBe(InstalmentStatus::Paid);

    // Đợt đã `paid` (đúng sau khi tổng khớp): một lần ghi thêm bị chặn ở kiểm tra trạng thái
    // trước khi tới kiểm tra số tiền — dù chỉ 1 đồng cũng không lọt qua.
    expect(fn () => recordFor($second, $this->instalment, ['amount' => 1]))
        ->toThrow(InstalmentNotPayable::class);
});
