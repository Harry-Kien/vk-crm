<?php

use App\Actions\Billing\EnterSignedContract;
use App\Enums\ContractStatus;
use App\Enums\Role;
use App\Models\Contract;
use App\Models\Matter;
use App\Models\User;
use App\Support\Billing\Money;
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
    'tổng các đợt lệch giá trị' => [10_000_000, fn () => today()->toDateString(), ValidationException::class],
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

/**
 * Sửa 2026-10-21 (chủ văn phòng báo: bấm "Lưu và kích hoạt" sáu lần, sáu thông báo nổi "Chưa kích hoạt
 * được hợp đồng HD-2026-0026…" mà không biết sửa ở đâu). Lệch tổng ở đường "nhập và kích hoạt luôn" là
 * lỗi NHẬP LIỆU của lịch thu, không phải trạng thái của một hợp đồng có sẵn: mọi thứ đã rollback, mã
 * `HD-…` không được giữ — nên câu không được nhắc một mã không tồn tại, và lỗi gắn vào ô `instalments`
 * (màn hình in nó ngay dưới lịch thu, hộp thoại ở lại). Lịch thu trống có câu riêng nói phải thêm đợt.
 */
it('answers an empty schedule with a field error on instalments that says to add an instalment, naming no contract code', function () {
    try {
        app(EnterSignedContract::class)->handle($this->accountant, $this->matter, ['total_amount' => 100_000_000, 'vat_rate_percent' => 4], [], today());
        $this->fail('Lịch thu trống phải bị từ chối.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['instalments'])
            ->and($exception->errors()['instalments'][0])->toBe(__('billing.errors.schedule_empty_on_entry', ['total' => Money::format(100_000_000)]))
            ->and($exception->errors()['instalments'][0])->not->toContain('HD-');
    }

    expect(Contract::query()->where('matter_id', $this->matter->id)->exists())->toBeFalse();
});

it('answers a schedule that does not add up with a field error on instalments carrying the three numbers, naming no contract code', function () {
    try {
        app(EnterSignedContract::class)->handle($this->accountant, $this->matter, ['total_amount' => 12_000_000], signedSchedule(10_000_000), today());
        $this->fail('Lịch thu lệch phải bị từ chối.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['instalments'])
            ->and($exception->errors()['instalments'][0])->toBe(__('billing.errors.schedule_mismatch_on_entry', [
                'schedule' => Money::format(10_000_000),
                'total' => Money::format(12_000_000),
                'difference' => Money::format(2_000_000),
            ]));
    }
});
