<?php

use App\Exceptions\ContractAmendmentImmutable;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\User;

it('records who wrote it and belongs to its contract', function () {
    $author = User::factory()->create();
    $this->actingAs($author, 'web');
    $contract = Contract::factory()->create();

    $amendment = ContractAmendment::factory()->for($contract)->create();

    expect($amendment->created_by)->toBe($author->id)
        ->and($amendment->contract->is($contract))->toBeTrue();
});

it('cannot be updated after it is written', function () {
    $amendment = ContractAmendment::factory()->create();

    expect(fn () => $amendment->update(['reason' => 'Sửa lại lý do sau khi đã ghi, không được phép theo quy định.']))
        ->toThrow(ContractAmendmentImmutable::class);
});

it('cannot be deleted', function () {
    $amendment = ContractAmendment::factory()->create();

    expect(fn () => $amendment->delete())->toThrow(ContractAmendmentImmutable::class)
        ->and(ContractAmendment::count())->toBe(1);
});

it('refuses in vietnamese, from the language file', function () {
    $amendment = ContractAmendment::factory()->create();

    expect(fn () => $amendment->delete())
        ->toThrow(ContractAmendmentImmutable::class, __('exceptions.contract_amendment_immutable'));
});
