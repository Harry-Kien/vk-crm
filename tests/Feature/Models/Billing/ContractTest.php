<?php

use App\Enums\ContractStatus;
use App\Exceptions\ContractNotDestroyable;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\User;

it('records who wrote the contract and belongs to its matter', function () {
    $author = User::factory()->create();
    $this->actingAs($author, 'web');
    $matter = Matter::factory()->create();

    $contract = Contract::factory()->for($matter)->create();

    expect($contract->created_by)->toBe($author->id)
        ->and($contract->matter->is($matter))->toBeTrue();
});

it('cannot be deleted once it has left draft', function () {
    $contract = Contract::factory()->active()->create();

    expect(fn () => $contract->delete())->toThrow(ContractNotDestroyable::class)
        ->and(Contract::count())->toBe(1);
});

it('cannot be deleted while draft if it already has a payment', function () {
    $contract = Contract::factory()->create(['status' => ContractStatus::Draft]);
    $instalment = Instalment::factory()->for($contract)->create();
    Payment::factory()->for($instalment)->create();

    expect(fn () => $contract->delete())->toThrow(ContractNotDestroyable::class)
        ->and(Contract::count())->toBe(1);
});

it('can be hard deleted while draft with no payments', function () {
    $contract = Contract::factory()->create(['status' => ContractStatus::Draft]);

    $contract->delete();

    expect(Contract::count())->toBe(0);
});

it('refuses in vietnamese, from the language file', function () {
    $contract = Contract::factory()->active()->create();

    expect(fn () => $contract->delete())
        ->toThrow(ContractNotDestroyable::class, __('exceptions.contract_not_destroyable_not_draft'));

    expect(__('exceptions.contract_not_destroyable_not_draft'))->not->toBe('exceptions.contract_not_destroyable_not_draft');
});

it('orders instalments and amendments by sequence', function () {
    $contract = Contract::factory()->create();
    Instalment::factory()->for($contract)->create(['sequence' => 2, 'name' => 'Đợt 2']);
    Instalment::factory()->for($contract)->create(['sequence' => 1, 'name' => 'Đợt 1']);

    expect($contract->instalments->pluck('sequence')->all())->toBe([1, 2]);
});

it('does not have a deleted_at column', function () {
    expect(Contract::factory()->create()->getAttributes())->not->toHaveKey('deleted_at');
});
