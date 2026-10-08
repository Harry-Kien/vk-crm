<?php

use App\Enums\ContractStatus;
use App\Enums\InstalmentState;
use App\Enums\InstalmentStatus;
use App\Enums\InstalmentTrigger;
use App\Filament\Portal\Pages\MatterProgress;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\User;
use App\Support\Billing\BillingSummary;
use App\Support\Billing\ScheduleTotal;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\TeamPerformanceSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/**
 * M9 Task 13 — `BillingSeeder` (trong `DemoDataSeeder`, không bao giờ trong `ReferenceDataSeeder`):
 * dữ liệu tiền mẫu đủ để trang doanh thu, trang "Công nợ", tab tiền của vụ và khối tiền trên cổng
 * vẽ ra một bức tranh THẬT — đúng danh sách của kế hoạch Task 13 bước 1, mỗi mục một khẳng định.
 *
 * Mọi tiền đi qua đúng các Action của sản phẩm (`DraftContract`, `ActivateContract`, `AmendContract`,
 * `RecordPayment`, `VoidPayment`, `WaiveInstalment`, `ReassignMatter`), nên các khẳng định dưới đây
 * đọc dấu vết mà CHỈ Action mới để lại (người ghi, luật sư được ghi doanh thu, dòng nhật ký) — một
 * seeder ghi thẳng bảng tiền không qua được chúng.
 */
beforeEach(function () {
    // MatterSeeder ghi tệp PDF thật (xem DemoDataSeederTest).
    Storage::fake('private');

    $this->seed(DatabaseSeeder::class);
});

function m9demoUser(string $email): User
{
    return User::query()->where('email', $email)->firstOrFail();
}

/** Mọi hợp đồng của dữ liệu mẫu, kèm lịch thu và vụ việc, không qua scope cổng. */
function m9demoContracts(): Collection
{
    return Contract::query()->with(['matter.matterType.stages', 'instalments.payments'])->orderBy('id')->get();
}

it('gives every matter that has left its first stage an active contract, a draft to one still at intake, and none to one on purpose', function () {
    $matters = Matter::query()->with(['matterType.stages', 'contract'])->get();

    $leftIntake = $matters->filter(fn (Matter $m): bool => $m->stage !== $m->matterType->firstStage()->key);
    $atIntake = $matters->reject(fn (Matter $m): bool => $m->stage !== $m->matterType->firstStage()->key);

    expect($leftIntake)->not->toBeEmpty()
        ->and($leftIntake->every(fn (Matter $m): bool => $m->contract?->status === ContractStatus::Active))->toBeTrue()
        ->and($atIntake->filter(fn (Matter $m): bool => $m->contract?->status === ContractStatus::Draft))->not->toBeEmpty()
        ->and($atIntake->filter(fn (Matter $m): bool => $m->contract === null))->not->toBeEmpty();
});

it('signs values from fifteen to four hundred and fifty million, some carrying 8 or 10 percent VAT and some none', function () {
    $contracts = m9demoContracts();

    expect($contracts->min('total_amount'))->toBeGreaterThanOrEqual(15_000_000)
        ->and($contracts->max('total_amount'))->toBeLessThanOrEqual(450_000_000)
        ->and($contracts->pluck('vat_rate_percent')->unique()->sort()->values()->all())->toBe([null, 8, 10]);
});

it('schedules thirty percent at signing, forty at filing and thirty at the first-instance judgment on at least four active contracts', function () {
    $thirtyFortyThirty = m9demoContracts()
        ->where('status', ContractStatus::Active)
        ->filter(function (Contract $contract): bool {
            $rows = $contract->instalments->sortBy('sequence')->values();

            return $rows->count() === 3
                && $rows[0]->trigger_type === InstalmentTrigger::OnSigning && (string) $rows[0]->percent_basis === '30.00'
                && $rows[1]->trigger_type === InstalmentTrigger::Stage && $rows[1]->trigger_stage_key === 'filed' && (string) $rows[1]->percent_basis === '40.00'
                && $rows[2]->trigger_type === InstalmentTrigger::Stage && $rows[2]->trigger_stage_key === 'first_instance' && (string) $rows[2]->percent_basis === '30.00';
        });

    $stageTriggered = m9demoContracts()
        ->where('status', ContractStatus::Active)
        ->filter(fn (Contract $c): bool => $c->instalments->contains('trigger_type', InstalmentTrigger::Stage));

    expect($thirtyFortyThirty->count())->toBeGreaterThanOrEqual(4)
        ->and($stageTriggered->count())->toBeGreaterThanOrEqual(4);
});

it('keeps every active contract balanced to the dong, and billing:check-invariants agrees', function () {
    $active = m9demoContracts()->where('status', ContractStatus::Active);

    expect($active->count())->toBeGreaterThanOrEqual(15)
        ->and(ScheduleTotal::mismatchedActiveContracts())->toBeEmpty();

    $active->each(fn (Contract $c) => expect($c->instalments->where('status', '!=', InstalmentStatus::Cancelled)->sum('amount'))->toBe($c->total_amount));

    $this->artisan('billing:check-invariants')
        ->expectsOutputToContain(__('billing.check_invariants.clean', ['count' => $active->count()]))
        ->assertExitCode(0);
});

it('plants an instalment overdue by date, one overdue by a stage the matter really reached, and one partly paid but not yet due', function () {
    $overdue = Instalment::query()->overdue()->get();

    $byDate = $overdue->where('trigger_type', InstalmentTrigger::DueDate);
    $byStage = $overdue->where('trigger_type', InstalmentTrigger::Stage);

    expect($byDate)->not->toBeEmpty()
        ->and($byStage)->not->toBeEmpty();

    // Đợt theo giai đoạn quá hạn là đợt được KÍCH HOẠT bởi một dòng tiến độ có thật của chính vụ đó.
    $stageRow = $byStage->first();

    expect($stageRow->triggered_by_stage_log_id)->not->toBeNull()
        ->and($stageRow->state())->toBe(InstalmentState::Overdue);

    $partly = Instalment::query()->with('payments')->get()->filter(fn (Instalment $i): bool => $i->state() === InstalmentState::PartiallyPaid);

    expect($partly)->not->toBeEmpty();
});

it('waives one instalment with a reason a person can read, and voids one payment with its reason', function () {
    $waived = Instalment::query()->where('status', InstalmentStatus::Waived)->get();

    expect($waived)->toHaveCount(1);

    $reason = (string) $waived->first()->waived_reason;

    expect(mb_strlen($reason))->toBeGreaterThanOrEqual(20)
        // "Đọc được": một câu tiếng Việt có dấu nhiều từ, không phải một chuỗi lấp chỗ.
        ->and($reason)->toMatch('/\p{L}+ \p{L}+ \p{L}+/u')
        ->and($reason)->toMatch('/[ăâđêôơưàảãáạ]/u')
        ->and($waived->first()->waived_by)->not->toBeNull();

    $voided = Payment::query()->whereNotNull('voided_at')->get();

    expect($voided)->toHaveCount(1)
        ->and(mb_strlen((string) $voided->first()->void_reason))->toBeGreaterThanOrEqual(20)
        ->and($voided->first()->voided_by)->not->toBeNull();
});

it('amends one contract upward, and leaves a closed matter still owing money', function () {
    $amendment = ContractAmendment::query()->sole();
    $contract = Contract::query()->findOrFail($amendment->contract_id);

    expect($amendment->new_total_amount)->toBeGreaterThan($amendment->previous_total_amount)
        ->and($contract->total_amount)->toBe($amendment->new_total_amount)
        ->and(mb_strlen((string) $amendment->reason))->toBeGreaterThanOrEqual(20);

    $closedOwing = Matter::query()->closed()->get()->filter(fn (Matter $m): bool => BillingSummary::hasOutstandingBalance($m->id));

    expect($closedOwing)->not->toBeEmpty();
});

it('gives the restricted matter a contract that only its lead lawyer and the admin see', function () {
    // Vụ `restricted` ĐẦU TIÊN — đúng vụ `BillingSeeder` chọn (`orderBy('id')`). M13 Task 8 thêm một vụ
    // `restricted` thứ hai (`TeamPerformanceSeeder`), có hợp đồng riêng, cũng do luật sư phụ trách ghi tiền.
    $restricted = Matter::query()->where('confidentiality', 'restricted')->orderBy('id')->firstOrFail();
    $contract = Contract::query()->where('matter_id', $restricted->id)->sole();

    expect($contract->status)->toBe(ContractStatus::Active)
        ->and(m9demoUser('luatsu1@luatvukhang.com')->can('view', $contract))->toBeTrue()
        ->and(m9demoUser('admin@luatvukhang.com')->can('view', $contract))->toBeTrue()
        ->and(m9demoUser('ketoan@luatvukhang.com')->can('view', $contract))->toBeFalse()
        ->and(m9demoUser('quanly@luatvukhang.com')->can('view', $contract))->toBeFalse();

    // P3: trên vụ `restricted`, chính luật sư phụ trách ghi khoản thu (không ai khác ngoài admin thấy vụ).
    $payments = Payment::query()->whereIn('instalment_id', $contract->instalments()->select('id'))->get();

    expect($payments)->not->toBeEmpty()
        ->and($payments->pluck('created_by')->unique()->all())->toBe([$restricted->lead_lawyer_id]);
});

it('keeps the money collected before a handover with the old lead and the money collected after it with the new one', function () {
    // Lần bàn giao của `BillingSeeder` (vụ 20). M13 Task 8 thêm lần bàn giao của luật sư nghỉ việc
    // (`TeamPerformanceSeeder`), mang `from_user_id` của người đó.
    $departed = m9demoUser(TeamPerformanceSeeder::DEPARTED_EMAIL);
    $reassigned = Activity::query()->where('event', 'matter_reassigned')->get()
        ->reject(fn (Activity $row): bool => (int) $row->properties['from_user_id'] === $departed->id)
        ->sole();
    $matter = Matter::query()->findOrFail($reassigned->subject_id);
    $oldLeadId = (int) $reassigned->properties['from_user_id'];

    $payments = Payment::query()
        ->whereNull('voided_at')
        ->whereIn('instalment_id', Instalment::query()->whereIn('contract_id', Contract::query()->where('matter_id', $matter->id)->select('id'))->select('id'))
        ->orderBy('id')
        ->get();

    expect($matter->lead_lawyer_id)->not->toBe($oldLeadId)
        ->and($payments->pluck('attributed_lawyer_id')->unique()->values()->all())->toBe([$oldLeadId, $matter->lead_lawyer_id])
        // Trước bàn giao ghi trước, sau bàn giao ghi sau — và đúng theo thứ tự ngày tiền về.
        ->and($payments->first()->paid_on->lt($payments->last()->paid_on))->toBeTrue();
});

it('spreads the collected money over at least eight calendar months, ending in the current one', function () {
    $months = Payment::query()->whereNull('voided_at')->pluck('paid_on')->map(fn ($d) => $d->format('Y-m'))->unique();

    expect($months->count())->toBeGreaterThanOrEqual(8)
        ->and($months->all())->toContain(today()->format('Y-m'))
        ->and(Payment::query()->max('paid_on'))->not->toBeNull();

    expect(Payment::query()->pluck('paid_on')->every(fn ($d): bool => $d->lte(today())))->toBeTrue();
});

it('shows the documented first demo client the money block of its published matter', function () {
    $demo = ClientUser::query()->where('email', 'khach1@example.com')->firstOrFail();

    $matter = Matter::query()
        ->where('client_id', $demo->client_id)
        ->where('is_published_to_portal', true)
        ->whereHas('contract', fn ($q) => $q->where('status', ContractStatus::Active->value))
        ->orderBy('id')
        ->firstOrFail();

    Filament::setCurrentPanel('portal');

    $html = $this->actingAs($demo, 'client')
        ->get(MatterProgress::getUrl(['record' => $matter->getKey()], panel: 'portal'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('data-portal-block="billing"')
        ->toContain(e($matter->contract->code))
        // Đợt chờ bước "nộp đơn" nói bằng nhãn cho khách của bước đó, không bằng khoá nội bộ.
        ->toContain(e(__('portal_progress.billing.due.stage_after', ['days' => 15, 'stage' => $matter->matterType->stage('filed')->client_label])));
});

it('adds no second contract, payment or amendment when the demo seeder runs again', function () {
    $before = [Contract::count(), Instalment::count(), Payment::count(), ContractAmendment::count()];

    $this->seed(DemoDataSeeder::class);

    expect([Contract::count(), Instalment::count(), Payment::count(), ContractAmendment::count()])->toBe($before)
        // Một lần bàn giao của BillingSeeder, một của TeamPerformanceSeeder (M13 Task 8) — không thêm lần nào.
        ->and(Activity::query()->where('event', 'matter_reassigned')->count())->toBe(2);
});
