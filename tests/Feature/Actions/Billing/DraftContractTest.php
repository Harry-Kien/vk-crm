<?php

use App\Actions\Billing\DraftContract;
use App\Enums\BillingModel;
use App\Enums\ContractStatus;
use App\Enums\InstalmentStatus;
use App\Enums\InstalmentTrigger;
use App\Enums\Role;
use App\Exceptions\BillingModelNotSupported;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\User;
use App\Support\Billing\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    // Loại vụ việc dân sự CỐ ĐỊNH (mã ba chữ `CIV` → `StagePresets::civil()`: `intake` đầu tiên,
    // có `filed`, `court_accepted`). `MatterTypeFactory` tự sinh mã hai chữ ngẫu nhiên, và 2/676
    // lần nó ra `HS`/`DN` — bộ giai đoạn hình sự/doanh nghiệp không có `filed`, test đỏ ngẫu nhiên
    // (đã xảy ra một lần trong full suite). Mã ba chữ không bao giờ trùng mã factory sinh ra.
    $this->civilType = MatterType::factory()->withStages()->create(['code' => 'CIV']);
    $this->matter = Matter::factory()->for($this->civilType, 'matterType')->create(['lead_lawyer_id' => $this->lead->id]);
});

/** @return list<array<string, mixed>> */
function draftSchedule(): array
{
    return [
        ['name' => 'Tạm ứng khi ký hợp đồng', 'amount' => 30_000_000, 'percent_basis' => '30', 'trigger_type' => InstalmentTrigger::OnSigning, 'due_days_after_trigger' => 5],
        ['name' => 'Thanh toán đợt 2 khi nộp đơn khởi kiện', 'amount' => 40_000_000, 'trigger_type' => 'stage', 'trigger_stage_key' => 'filed', 'due_days_after_trigger' => 30],
        ['name' => 'Thanh toán đợt cuối', 'amount' => 30_000_000, 'trigger_type' => 'due_date', 'due_date' => '2027-03-31'],
    ];
}

/**
 * @param  array<string, mixed>  $attributes
 * @param  list<array<string, mixed>>|null  $instalments
 */
function draftFor(User $actor, Matter $matter, array $attributes = [], ?array $instalments = null): Contract
{
    return app(DraftContract::class)->handle(
        $actor,
        $matter,
        [...['total_amount' => 100_000_000, 'vat_rate_percent' => 10], ...$attributes],
        $instalments ?? draftSchedule(),
    );
}

/** Lỗi xác thực của một lần soạn, theo tên trường; mảng rỗng nếu không có lỗi. */
function draftErrors(callable $draft): array
{
    try {
        $draft();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

/** Một dòng lịch thu đúng, ghi đè bằng `$overrides`, làm lịch thu duy nhất. */
function oneInstalment(array $overrides): array
{
    return [[...['name' => 'Trọn gói', 'amount' => 100_000_000, 'trigger_type' => 'due_date', 'due_date' => '2027-01-15'], ...$overrides]];
}

// --- Đường chính -------------------------------------------------------------------------------

it('drafts a contract with its schedule, in draft, coded HD-year-0001', function () {
    $contract = draftFor($this->lead, $this->matter);
    $year = now()->year;

    expect($contract->code)->toBe("HD-{$year}-0001")
        ->and($contract->status)->toBe(ContractStatus::Draft)
        ->and($contract->billing_model)->toBe(BillingModel::FixedFee)
        ->and($contract->total_amount)->toBe(100_000_000)
        ->and($contract->vat_rate_percent)->toBe(10)
        ->and($contract->matter_id)->toBe($this->matter->id)
        ->and($contract->signed_at)->toBeNull();

    $instalments = $contract->instalments()->get();

    expect($instalments->pluck('sequence')->all())->toBe([1, 2, 3])
        ->and($instalments->pluck('amount')->all())->toBe([30_000_000, 40_000_000, 30_000_000])
        ->and($instalments->pluck('status')->all())->toBe([InstalmentStatus::Pending, InstalmentStatus::Pending, InstalmentStatus::Pending])
        ->and($instalments[0]->trigger_type)->toBe(InstalmentTrigger::OnSigning)
        ->and($instalments[0]->due_date)->toBeNull()
        ->and($instalments[0]->due_days_after_trigger)->toBe(5)
        ->and($instalments[1]->trigger_type)->toBe(InstalmentTrigger::Stage)
        ->and($instalments[1]->trigger_stage_key)->toBe('filed')
        ->and($instalments[1]->due_date)->toBeNull()
        ->and($instalments[2]->trigger_type)->toBe(InstalmentTrigger::DueDate)
        ->and($instalments[2]->due_date->toDateString())->toBe('2027-03-31');

    $second = draftFor($this->lead, Matter::factory()->for($this->civilType, 'matterType')->create(['lead_lawyer_id' => $this->lead->id]));

    expect($second->code)->toBe("HD-{$year}-0002");
});

it('records the actor passed in as author, not whoever holds the session', function () {
    $sessionUser = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($sessionUser, 'web');

    $contract = draftFor($this->lead, $this->matter);

    expect($contract->created_by)->toBe($this->lead->id)
        ->and($contract->instalments()->pluck('created_by')->unique()->all())->toBe([$this->lead->id]);

    $audit = Activity::query()->where('event', 'contract_drafted')->sole();

    expect($audit->causer_id)->toBe($this->lead->id)
        ->and($audit->subject_id)->toBe($contract->id)
        ->and($audit->properties['code'])->toBe($contract->code)
        ->and($audit->properties['total_amount'])->toBe(100_000_000)
        ->and($audit->properties['instalment_count'])->toBe(3);
});

it('does not ask the schedule of a draft to match its total', function () {
    $contract = draftFor($this->lead, $this->matter, ['total_amount' => 100_000_000], oneInstalment(['amount' => 1]));

    expect($contract->instalments()->sum('amount'))->toEqual(1);
});

it('rolls the whole draft back, code included, when one instalment is invalid', function () {
    $errors = draftErrors(fn () => draftFor($this->lead, $this->matter, [], [
        draftSchedule()[0],
        [...draftSchedule()[1], 'amount' => 0],
    ]));

    expect($errors)->toHaveKey('instalments.1.amount')
        ->and(Contract::count())->toBe(0)
        ->and(Instalment::count())->toBe(0)
        ->and(draftFor($this->lead, $this->matter)->code)->toBe('HD-'.now()->year.'-0001');
});

// --- Quyền và một hợp đồng cho một vụ ----------------------------------------------------------

it('refuses an account that cannot manage the money of this matter', function () {
    $outsider = User::factory()->withRole(Role::Lawyer)->create();

    expect(fn () => draftFor($outsider, $this->matter))->toThrow(AuthorizationException::class)
        ->and(Contract::count())->toBe(0);
});

it('lets the manager draft on an ordinary matter', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();

    expect(draftFor($manager, $this->matter)->created_by)->toBe($manager->id);
});

it('asks the gate about the matter as it is now, not as the caller loaded it', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $stale = Matter::query()->findOrFail($this->matter->id);
    DB::table('matters')->where('id', $this->matter->id)->update(['confidentiality' => 'restricted']);

    expect(fn () => draftFor($manager, $stale))->toThrow(AuthorizationException::class)
        ->and(Contract::count())->toBe(0);
});

it('refuses a second contract on the same matter', function () {
    draftFor($this->lead, $this->matter);

    expect(draftErrors(fn () => draftFor($this->lead, $this->matter)))
        ->toBe(['matter_id' => [__('billing.validation.contract_exists')]])
        ->and(Contract::count())->toBe(1);
});

// --- Cách tính phí ---------------------------------------------------------------------------------

it('refuses an hourly or mixed contract, in Vietnamese', function (BillingModel $model) {
    expect(fn () => draftFor($this->lead, $this->matter, ['billing_model' => $model]))
        ->toThrow(BillingModelNotSupported::class, __('billing.errors.billing_model_not_supported', ['model' => $model->label()]))
        ->and(Contract::count())->toBe(0);
})->with([BillingModel::Hourly, BillingModel::Mixed]);

it('refuses billing_model hourly given as a string too', function () {
    expect(fn () => draftFor($this->lead, $this->matter, ['billing_model' => 'hourly']))
        ->toThrow(BillingModelNotSupported::class);
});

it('accepts fixed_fee given explicitly, as enum or string', function (BillingModel|string $model) {
    expect(draftFor($this->lead, $this->matter, ['billing_model' => $model])->billing_model)->toBe(BillingModel::FixedFee);
})->with([BillingModel::FixedFee, 'fixed_fee']);

it('refuses a billing model that does not exist', function () {
    expect(draftErrors(fn () => draftFor($this->lead, $this->matter, ['billing_model' => 'per_page'])))
        ->toHaveKey('billing_model');
});

// --- Giá trị và thuế suất ------------------------------------------------------------------------

it('refuses a total that is not a whole number of dong between 1 and Money::MAX', function (mixed $total) {
    expect(draftErrors(fn () => draftFor($this->lead, $this->matter, ['total_amount' => $total])))
        ->toHaveKey('total_amount');
})->with([
    'zero' => 0,
    'negative' => -1,
    'a string, not yet parsed' => '100.000.000',
    'missing' => null,
    'one above the ceiling' => Money::MAX + 1,
]);

it('accepts a total of exactly Money::MAX', function () {
    expect(draftFor($this->lead, $this->matter, ['total_amount' => Money::MAX], oneInstalment([]))->total_amount)
        ->toBe(Money::MAX);
});

it('keeps no vat line apart from a zero rate', function (?int $rate) {
    expect(draftFor($this->lead, $this->matter, ['vat_rate_percent' => $rate])->vat_rate_percent)->toBe($rate);
})->with(['no tax line' => null, 'zero-rated' => 0, 'hundred' => 100]);

it('refuses a vat rate outside 0 to 100', function (mixed $rate) {
    expect(draftErrors(fn () => draftFor($this->lead, $this->matter, ['vat_rate_percent' => $rate])))
        ->toHaveKey('vat_rate_percent');
})->with(['negative' => -1, 'over a hundred' => 101, 'a string' => '10']);

// --- Một dòng lịch thu -----------------------------------------------------------------------------

it('leaves the due date of an on-signing instalment empty even when one is given', function () {
    $contract = draftFor($this->lead, $this->matter, [], oneInstalment([
        'trigger_type' => 'on_signing', 'due_date' => '2027-01-15', 'trigger_stage_key' => 'filed',
    ]));

    $instalment = $contract->instalments()->sole();

    expect($instalment->due_date)->toBeNull()
        ->and($instalment->trigger_stage_key)->toBeNull();
});

it('asks a due-date instalment for a real date, and zeroes its days-after-trigger', function () {
    $instalment = draftFor($this->lead, $this->matter, [], oneInstalment(['due_days_after_trigger' => 9]))->instalments()->sole();

    expect($instalment->due_date->toDateString())->toBe('2027-01-15')
        ->and($instalment->due_days_after_trigger)->toBe(0);
});

it('refuses a due-date instalment without a usable date', function (mixed $date, string $message) {
    expect(draftErrors(fn () => draftFor($this->lead, $this->matter, [], oneInstalment(['due_date' => $date]))))
        ->toBe(['instalments.0.due_date' => [__($message)]]);
})->with([
    'missing' => [null, 'billing.validation.due_date_required'],
    'not a date' => ['ngày mai', 'billing.validation.date_invalid'],
    'impossible date' => ['2027-02-30', 'billing.validation.date_invalid'],
    'empty' => ['', 'billing.validation.date_invalid'],
]);

it('accepts a stage of this matter type that is not its first', function () {
    $instalment = draftFor($this->lead, $this->matter, [], oneInstalment(['trigger_type' => 'stage', 'trigger_stage_key' => 'filed']))
        ->instalments()->sole();

    expect($instalment->trigger_stage_key)->toBe('filed')
        ->and($instalment->due_date)->toBeNull();
});

it('refuses the first stage of the matter type as a trigger', function () {
    expect(draftErrors(fn () => draftFor($this->lead, $this->matter, [], oneInstalment(['trigger_type' => 'stage', 'trigger_stage_key' => 'intake']))))
        ->toBe(['instalments.0.trigger_stage_key' => [__('billing.validation.trigger_stage_is_first', ['stage' => 'Tiếp nhận'])]]);
});

it('refuses a stage that this matter type does not have, even if another type does', function (?string $key) {
    MatterType::factory()->withStages()->create(['code' => 'HS']);

    expect(draftErrors(fn () => draftFor($this->lead, $this->matter, [], oneInstalment(['trigger_type' => 'stage', 'trigger_stage_key' => $key]))))
        ->toHaveKey('instalments.0.trigger_stage_key');
})->with(['criminal-only stage' => 'investigation', 'nothing like it' => 'khong_co', 'missing' => null]);

it('refuses an instalment without a name, or with one longer than the column', function (?string $name) {
    expect(draftErrors(fn () => draftFor($this->lead, $this->matter, [], oneInstalment(['name' => $name]))))
        ->toHaveKey('instalments.0.name');
})->with(['missing' => null, 'blank' => '   ', '151 characters' => str_repeat('đ', 151)]);

it('accepts a name of exactly 150 vietnamese characters', function () {
    $name = str_repeat('đ', 150);

    expect(draftFor($this->lead, $this->matter, [], oneInstalment(['name' => $name]))->instalments()->sole()->name)->toBe($name);
});

it('refuses an unknown trigger type', function () {
    expect(draftErrors(fn () => draftFor($this->lead, $this->matter, [], oneInstalment(['trigger_type' => 'when_paid']))))
        ->toHaveKey('instalments.0.trigger_type');
});

it('refuses an instalment amount that is not a whole number of dong between 1 and Money::MAX', function (mixed $amount) {
    expect(draftErrors(fn () => draftFor($this->lead, $this->matter, [], oneInstalment(['amount' => $amount]))))
        ->toHaveKey('instalments.0.amount');
})->with(['zero' => 0, 'string' => '1000', 'over the ceiling' => Money::MAX + 1]);

it('refuses days-after-trigger that do not fit the column', function (mixed $days) {
    expect(draftErrors(fn () => draftFor($this->lead, $this->matter, [], oneInstalment(['trigger_type' => 'on_signing', 'due_days_after_trigger' => $days]))))
        ->toHaveKey('instalments.0.due_days_after_trigger');
})->with(['negative' => -1, 'too many' => 65_536, 'string' => '5']);

// --- percent_basis ---------------------------------------------------------------------------------

it('stores percent_basis as typed and never recomputes the amount from it', function () {
    $instalment = draftFor($this->lead, $this->matter, [], oneInstalment(['amount' => 40_000_000, 'percent_basis' => '50']))
        ->instalments()->sole();

    expect($instalment->amount)->toBe(40_000_000)
        ->and($instalment->percent_basis)->toBe('50.00');
});

it('reads a blank percent_basis and a blank note as nothing, and trims a note', function () {
    $contract = draftFor($this->lead, $this->matter, ['note' => '   '], oneInstalment(['percent_basis' => '  ', 'note' => '  Khách xin trả bằng tiền mặt.  ']));
    $instalment = $contract->instalments()->sole();

    expect($instalment->percent_basis)->toBeNull()
        ->and($instalment->note)->toBe('Khách xin trả bằng tiền mặt.')
        ->and($contract->note)->toBeNull()
        ->and(draftFor($this->lead, Matter::factory()->for($this->civilType, 'matterType')->create(['lead_lawyer_id' => $this->lead->id]), ['note' => ' Giá đã gồm VAT. '])->note)
        ->toBe('Giá đã gồm VAT.');
});

it('refuses a percent_basis outside 0 to 100 or with more than two decimals', function (mixed $percent) {
    expect(draftErrors(fn () => draftFor($this->lead, $this->matter, [], oneInstalment(['percent_basis' => $percent]))))
        ->toHaveKey('instalments.0.percent_basis');
})->with(['zero' => '0', 'over a hundred' => '100.01', 'three decimals' => '33.333', 'text' => 'ba mươi']);
