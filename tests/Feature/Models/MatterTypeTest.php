<?php

use App\Models\MatterType;
use App\Support\StagePresets;
use Illuminate\Database\QueryException;

it('orders stages and exposes allowed transitions', function () {
    $type = MatterType::factory()->withStages()->create(['code' => 'DS']);

    $keys = $type->stages->pluck('key')->all();

    expect($keys)->toBe(array_column(StagePresets::civil(), 'key'))
        ->and($type->firstStage()->key)->toBe('intake')
        ->and($type->stage('intake')->allows('collecting_documents'))->toBeTrue()
        ->and($type->stage('intake')->allows('closed'))->toBeFalse()
        ->and($type->stage('closed')->is_terminal)->toBeTrue()
        ->and($type->stage('on_hold')->allowed_next)->toBe(['intake', 'collecting_documents']);
});

it('has a full civil preset matching the spec sequence', function () {
    $keys = array_column(StagePresets::civil(), 'key');

    expect($keys)->toBe([
        'intake', 'collecting_documents', 'drafting', 'filed', 'court_accepted',
        'mediation', 'first_instance', 'appeal', 'enforcement', 'closed', 'on_hold',
    ]);
});

it('maps every seeded type code to a preset', function () {
    foreach (['DD', 'DS', 'HS', 'DN', 'LD', 'HN'] as $code) {
        expect(StagePresets::for($code))->not->toBeEmpty();
    }
});

it('rejects a duplicate stage key inside one type', function () {
    $type = MatterType::factory()->withStages()->create();

    expect(fn () => $type->stages()->create([
        'key' => 'intake', 'label' => 'x', 'client_label' => 'x', 'sort_order' => 99, 'allowed_next' => [],
    ]))->toThrow(QueryException::class);
});
