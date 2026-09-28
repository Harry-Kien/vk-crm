<?php

use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\Instalment;
use App\Models\Payment;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Spatie\Activitylog\Models\Activity;

/**
 * `Relation::enforceMorphMap()` (AppServiceProvider) là bản NGHIÊM NGẶT: một model không có tên
 * trong map thì `getMorphClass()` ném `ClassMorphViolationException` thay vì lặng lẽ lưu tên lớp
 * đầy đủ. `Audit::record()` là đường thật đi qua `performedOn()` → `getMorphClass()`.
 */
it('registers a strict morph alias for all four billing models', function (string $class, string $alias) {
    $model = $class::factory()->create();

    Audit::record('billing_test_event', $model);

    expect(Relation::getMorphedModel($alias))->toBe($class)
        ->and(Activity::query()->latest('id')->first()->subject_type)->toBe($alias);
})->with([
    'contract' => [Contract::class, 'contract'],
    'instalment' => [Instalment::class, 'instalment'],
    'payment' => [Payment::class, 'payment'],
    'contract_amendment' => [ContractAmendment::class, 'contract_amendment'],
]);
