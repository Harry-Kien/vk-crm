<?php

use App\Actions\Billing\DeleteDraftContract;
use App\Enums\ContractStatus;
use App\Enums\Role;
use App\Exceptions\ContractNotDestroyable;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Activitylog\Models\Activity;

/**
 * `DeleteDraftContract` (lượt rà soát cuối M9, M9): xoá cứng một hợp đồng còn `draft` chưa có
 * khoản thu nào — qua `ContractPolicy::delete` và nhật ký, không gọi `$contract->delete()` thẳng
 * từ màn hình. "Khi nào xoá được" vẫn là MỘT định nghĩa: `Contract::assertDestroyable()` (hook
 * `deleting` gọi đúng hàm đó).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->manager = User::factory()->withRole(Role::Manager)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);

    $this->draft = Contract::factory()->for($this->matter)->create(['status' => ContractStatus::Draft, 'total_amount' => 30_000_000]);
    Instalment::factory()->for($this->draft)->create(['sequence' => 1, 'amount' => 10_000_000]);
    Instalment::factory()->for($this->draft)->create(['sequence' => 2, 'amount' => 20_000_000]);
});

function deleteDraft(User $actor, Contract $contract): void
{
    app(DeleteDraftContract::class)->handle($actor, $contract);
}

it('hard deletes a draft contract together with its schedule', function () {
    deleteDraft($this->lead, $this->draft);

    expect(Contract::query()->whereKey($this->draft->id)->exists())->toBeFalse()
        ->and(Instalment::query()->where('contract_id', $this->draft->id)->count())->toBe(0);
});

it('records the deletion against the matter, with the code of the contract, caused by the actor passed in', function () {
    $this->actingAs($this->manager, 'web');

    deleteDraft($this->lead, $this->draft);

    $audit = Activity::query()->where('event', 'contract_draft_deleted')->sole();

    expect($audit->causer_id)->toBe($this->lead->id)
        ->and($audit->subject_type)->toBe($this->matter->getMorphClass())
        ->and($audit->subject_id)->toBe($this->matter->id)
        ->and($audit->properties['code'])->toBe($this->draft->code)
        ->and($audit->properties['instalment_count'])->toBe(2);
});

it('refuses a contract that has left draft, and deletes nothing', function (string $state) {
    $contract = Contract::factory()->for(Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]))->{$state}()->create(['total_amount' => 10_000_000]);
    // Có một đợt, để lời từ chối phải là câu của HỢP ĐỒNG — không phải câu "đợt không xoá được" của
    // `Instalment::deleting` nếu Action lỡ xoá đợt trước khi hỏi `assertDestroyable()`.
    Instalment::factory()->for($contract)->create(['amount' => 10_000_000]);

    expect(fn () => deleteDraft($this->lead, $contract))
        ->toThrow(ContractNotDestroyable::class, __('exceptions.contract_not_destroyable_not_draft'));

    expect(Contract::query()->whereKey($contract->id)->exists())->toBeTrue()
        ->and(Instalment::query()->where('contract_id', $contract->id)->count())->toBe(1);
})->with(['active', 'completed', 'cancelled']);

it('refuses a draft that somehow carries a payment row, and deletes nothing', function () {
    $instalment = $this->draft->instalments()->firstOrFail();
    Payment::factory()->for($instalment)->create(['amount' => 1_000_000]);

    expect(fn () => deleteDraft($this->lead, $this->draft))
        ->toThrow(ContractNotDestroyable::class, __('exceptions.contract_not_destroyable_has_payments'));

    expect(Contract::query()->whereKey($this->draft->id)->exists())->toBeTrue()
        ->and(Instalment::query()->where('contract_id', $this->draft->id)->count())->toBe(2);
});

it('refuses an accountant, who cannot manage contracts, and deletes nothing', function () {
    expect(fn () => deleteDraft($this->accountant, $this->draft))->toThrow(AuthorizationException::class);

    expect(Contract::query()->whereKey($this->draft->id)->exists())->toBeTrue()
        ->and(Activity::query()->where('event', 'contract_draft_deleted')->exists())->toBeFalse();
});

it('refuses a lawyer who is not on the matter', function () {
    $outsider = User::factory()->withRole(Role::Lawyer)->create();

    expect(fn () => deleteDraft($outsider, $this->draft))->toThrow(AuthorizationException::class);

    expect(Contract::query()->whereKey($this->draft->id)->exists())->toBeTrue();
});
