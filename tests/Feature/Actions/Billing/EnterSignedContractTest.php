<?php

use App\Actions\Billing\EnterSignedContract;
use App\Enums\ContractStatus;
use App\Enums\Role;
use App\Exceptions\ContractTotalMismatch;
use App\Models\Contract;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * Làn kt — {@see EnterSignedContract}: soạn và kích hoạt trong MỘT transaction tiền. Đường màn hình ở
 * `ContractEntryPageTest`; ở đây là các bất biến của chính Action (tất cả hoặc không gì cả, quyền của
 * từng lõi), thành ngữ `DraftContractTest`/`ActivateContractTest`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
});

/** @return list<array<string, mixed>> */
function signedSchedule(int $amount): array
{
    return [['name' => 'Trọn gói', 'amount' => $amount, 'trigger_type' => 'on_signing', 'due_days_after_trigger' => 3]];
}

it('drafts and activates in one transaction, recording both steps under the person who entered it', function () {
    $contract = app(EnterSignedContract::class)->handle($this->accountant, $this->matter, ['total_amount' => 12_000_000], signedSchedule(12_000_000), today()->subDay());

    expect($contract->status)->toBe(ContractStatus::Active)
        ->and($contract->signed_at->toDateString())->toBe(today()->subDay()->toDateString())
        ->and($contract->activated_by)->toBe($this->accountant->id)
        ->and($contract->created_by)->toBe($this->accountant->id)
        ->and($contract->instalments()->sole()->due_date->toDateString())->toBe(today()->addDays(2)->toDateString())
        ->and(Activity::query()->whereIn('event', ['contract_drafted', 'contract_activated'])->pluck('causer_id')->unique()->all())->toBe([$this->accountant->id])
        ->and(Activity::query()->whereIn('event', ['contract_drafted', 'contract_activated'])->count())->toBe(2);
});

it('leaves no draft behind and burns no contract number when the activation step refuses', function (int $instalment, string $signedAt, string $exception) {
    $before = DB::table('code_sequences')->where('key', 'contract:'.now()->year)->value('last_number');

    expect(fn () => app(EnterSignedContract::class)->handle($this->accountant, $this->matter, ['total_amount' => 12_000_000], signedSchedule($instalment), $signedAt))
        ->toThrow($exception);

    expect(Contract::query()->where('matter_id', $this->matter->id)->exists())->toBeFalse()
        ->and(DB::table('code_sequences')->where('key', 'contract:'.now()->year)->value('last_number'))->toBe($before)
        ->and(Activity::query()->where('event', 'contract_drafted')->exists())->toBeFalse()
        ->and($this->lead->notifications()->count())->toBe(0);
})->with([
    'tổng các đợt lệch giá trị' => [10_000_000, fn () => today()->toDateString(), ContractTotalMismatch::class],
    'ngày ký ở tương lai' => [12_000_000, fn () => today()->addDay()->toDateString(), ValidationException::class],
]);

it('refuses someone who may not enter contracts and writes nothing', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();

    expect(fn () => app(EnterSignedContract::class)->handle($assistant, $this->matter, ['total_amount' => 12_000_000], signedSchedule(12_000_000), today()))
        ->toThrow(AuthorizationException::class);

    expect(Contract::query()->where('matter_id', $this->matter->id)->exists())->toBeFalse();
});

it('refuses the accountant on a restricted matter, whose money it cannot see', function () {
    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lead->id]);

    expect(fn () => app(EnterSignedContract::class)->handle($this->accountant, $restricted, ['total_amount' => 12_000_000], signedSchedule(12_000_000), today()))
        ->toThrow(AuthorizationException::class);

    expect(Contract::query()->where('matter_id', $restricted->id)->exists())->toBeFalse();
});

it('keeps one open contract per matter', function () {
    Contract::factory()->for($this->matter)->create(['status' => ContractStatus::Draft, 'total_amount' => 5_000_000]);

    expect(fn () => app(EnterSignedContract::class)->handle($this->accountant, $this->matter, ['total_amount' => 12_000_000], signedSchedule(12_000_000), today()))
        ->toThrow(ValidationException::class);

    expect(Contract::query()->where('matter_id', $this->matter->id)->count())->toBe(1);
});
