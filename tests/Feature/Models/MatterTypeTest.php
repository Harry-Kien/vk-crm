<?php

use App\Exceptions\DuplicateStageKey;
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

// Ghi chú (fix round 1, việc C): trên stack hiện tại (SQLite trong test, MariaDB khi chạy
// thật) hai dòng cùng sort_order đã quay lại theo thứ tự id ngay cả khi bỏ ->orderBy('id') —
// đã tự kiểm chứng bằng cách gỡ tạm dòng đó và chạy lại, test này vẫn xanh. Vì vậy test này
// không phải một tấm chắn hồi quy thật sự cho lỗi #2; nó chỉ ghi lại chủ đích "thứ tự tường
// minh, không dựa vào hành vi không xác định của storage engine/query planner".
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
 * Fix round 1 (việc A/B): `unique(matter_type_id, key)` ở DB tính cả dòng đã xoá mềm nên
 * không thể tạo lại cùng `key` sau khi xoá. MariaDB không có unique một phần, nên ràng buộc
 * DB đã được bỏ (xem migration 2026_09_15_000001). Ban đầu tính duy nhất chỉ được kiểm tra ở
 * form Filament (StagesRelationManager) — review chỉ ra hai đường ghi khác (MatterTypeFactory,
 * MatterTypeSeeder) đi thẳng qua quan hệ Eloquent, không qua form, nên hoàn toàn không bị chặn.
 * Chuyển bảo vệ vào `MatterTypeStage::booted()` (sự kiện `saving`) để MỌI đường ghi — form,
 * Action, artisan command, seeder, factory — đều đi qua cùng một chốt chặn.
 */
it('rejects a duplicate live stage key through the bare Eloquent relation, not just the form', function () {
    $type = MatterType::factory()->withStages()->create();

    expect(fn () => $type->stages()->create([
        'key' => 'intake', 'label' => 'x', 'client_label' => 'x', 'sort_order' => 99, 'allowed_next' => [],
    ]))->toThrow(DuplicateStageKey::class, $type->name);
});

it('allows recreating a stage key after the original row is soft-deleted, through the bare relation', function () {
    $type = MatterType::factory()->withStages()->create();
    $type->stage('intake')->delete();

    $recreated = $type->stages()->create([
        'key' => 'intake', 'label' => 'Tiếp nhận lại', 'client_label' => 'x', 'sort_order' => 1, 'allowed_next' => [],
    ]);

    expect($recreated->exists)->toBeTrue()
        ->and($type->fresh()->stages()->withTrashed()->where('key', 'intake')->count())->toBe(2);
});

it('lets a stage save again without changing its key without tripping the duplicate guard', function () {
    $type = MatterType::factory()->withStages()->create();
    $intake = $type->stage('intake');

    $intake->update(['label' => 'Tiếp nhận (đổi nhãn)']);

    expect($intake->fresh()->label)->toBe('Tiếp nhận (đổi nhãn)');
});
