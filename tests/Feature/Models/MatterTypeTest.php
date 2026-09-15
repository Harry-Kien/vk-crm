<?php

use App\Models\MatterType;
use App\Support\StagePresets;

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

it('breaks a tie in sort_order deterministically using id', function () {
    $type = MatterType::factory()->create();
    $older = $type->stages()->create([
        'key' => 'b', 'label' => 'B', 'client_label' => 'B', 'sort_order' => 5, 'allowed_next' => [],
    ]);
    $newer = $type->stages()->create([
        'key' => 'a', 'label' => 'A', 'client_label' => 'A', 'sort_order' => 5, 'allowed_next' => [],
    ]);

    expect($type->fresh()->stages->pluck('key')->all())->toBe([$older->key, $newer->key]);
});

/**
 * M1 mang sang: `unique(matter_type_id, key)` ở DB tính cả dòng đã xoá mềm nên không thể tạo
 * lại cùng `key` sau khi xoá. MariaDB không có unique một phần, nên ràng buộc DB đã được bỏ
 * (xem migration 2026_09_15_000001) và tính duy nhất chuyển sang StagesRelationManager (kiểm
 * tra bằng test Feature/Filament/MatterTypeResourceTest). Ở tầng model/DB, việc tạo trực tiếp
 * qua Eloquent không còn bị DB chặn — kể cả khi key còn sống, không chỉ khi đã xoá mềm.
 */
it('no longer rejects a duplicate stage key at the database layer', function () {
    $type = MatterType::factory()->withStages()->create();

    $duplicate = $type->stages()->create([
        'key' => 'intake', 'label' => 'x', 'client_label' => 'x', 'sort_order' => 99, 'allowed_next' => [],
    ]);

    expect($duplicate->exists)->toBeTrue();
});

it('allows recreating a stage key after the original row is soft-deleted', function () {
    $type = MatterType::factory()->withStages()->create();
    $type->stage('intake')->delete();

    $recreated = $type->stages()->create([
        'key' => 'intake', 'label' => 'Tiếp nhận lại', 'client_label' => 'x', 'sort_order' => 1, 'allowed_next' => [],
    ]);

    expect($recreated->exists)->toBeTrue()
        ->and($type->fresh()->stages()->withTrashed()->where('key', 'intake')->count())->toBe(2);
});
