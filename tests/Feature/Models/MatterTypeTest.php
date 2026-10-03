<?php

use App\Exceptions\DuplicateMatterTypeCode;
use App\Exceptions\DuplicateStageKey;
use App\Exceptions\StageKeyInUse;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\StageLog;
use App\Support\StagePresets;
use Database\Seeders\MatterTypeSeeder;

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
    foreach (array_column(MatterTypeSeeder::types(), 'code') as $code) {
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

/**
 * Task 19 (rà soát cuối, "Cấu hình không phá dữ liệu đang chạy") — CHỐT CHẶN THỨ HAI của
 * `MatterTypeStage::booted()`: `StagesRelationManager` đã chặn đường đổi `key` ở tầng FORM (một
 * lỗi gắn vào ô `key`, xem `MatterTypeResourceTest`); test này đi qua Eloquent trần, không qua
 * form đó, cùng đúng lý do `DuplicateStageKey` có test riêng cho đường bare-Eloquent.
 */
it('rejects changing the key of a stage a matter is standing at, through the bare Eloquent relation, not just the form', function () {
    $type = MatterType::factory()->withStages()->create();
    $stage = $type->stage('intake');
    Matter::factory()->create(['matter_type_id' => $type->id, 'stage' => 'intake']);

    expect(fn () => $stage->update(['key' => 'tiep_nhan']))->toThrow(StageKeyInUse::class, $type->name);

    expect($stage->fresh()->key)->toBe('intake');
});

/** Cùng luật, qua dòng tiến độ (`stage_logs.from_stage`/`to_stage`) thay vì `matters.stage` trực tiếp. */
it('rejects changing the key of a stage a stage log points at, through the bare Eloquent relation', function () {
    $type = MatterType::factory()->withStages()->create();
    $stage = $type->stage('intake');
    $matter = Matter::factory()->create(['matter_type_id' => $type->id, 'stage' => 'collecting_documents']);
    StageLog::factory()->for($matter)->transition('intake', 'collecting_documents')->create();

    expect(fn () => $stage->update(['key' => 'tiep_nhan']))->toThrow(StageKeyInUse::class, $type->name);

    expect($stage->fresh()->key)->toBe('intake');
});

/**
 * Vế dương: một giai đoạn không hồ sơ hay dòng tiến độ nào dùng, và KHÔNG ai trỏ tới trong
 * `allowed_next`, vẫn đổi `key` được qua Eloquent trần.
 *
 * KHÔNG dùng `StagePresets::civil()` (bộ `withStages()` gắn) ở đây: bộ đó không có giai đoạn nào
 * mà KHÔNG bị một giai đoạn khác trỏ tới trong `allowed_next` — kể cả `'on_hold'`, bị `intake` VÀ
 * `collecting_documents` cùng trỏ tới — nên bản test trước fix round 1 dùng `'on_hold'` ở đây
 * thực ra đang chứng minh sai điều nó nói: nó xanh không phải vì "không ai dùng", mà vì
 * `keyInUse()` khi đó CHƯA kiểm `allowed_next` (đúng lỗ hổng Critical của vòng sửa 1). Hai giai
 * đoạn tối giản dưới đây tách bạch điều kiện.
 */
it('lets a stage change its key through the bare Eloquent relation when nothing uses it', function () {
    $type = MatterType::factory()->create();
    $stage = $type->stages()->create([
        'key' => 'a', 'label' => 'Giai đoạn A', 'client_label' => 'A', 'sort_order' => 1, 'allowed_next' => [],
    ]);

    $stage->update(['key' => 'tam_dung']);

    expect($stage->fresh()->key)->toBe('tam_dung');
});

/**
 * Task 19, vòng sửa 1 (Critical): đổi `key` của một giai đoạn còn nằm trong `allowed_next` của
 * một giai đoạn KHÁC bỏ lại một tham chiếu TREO — một hồ sơ ở giai đoạn kia mở "Chuyển giai đoạn"
 * sẽ thấy `key` cũ (không còn tồn tại) trong danh sách, và chọn nó ném `InvalidStageTransition`.
 * Trước bản vá này, `keyInUse()` chỉ kiểm `matters.stage`/`stage_logs`, nên đường này lọt qua.
 */
it('rejects changing the key of a stage still listed in another stage\'s allowed_next, through the bare Eloquent relation', function () {
    $type = MatterType::factory()->create();
    $a = $type->stages()->create([
        'key' => 'a', 'label' => 'Giai đoạn A', 'client_label' => 'A', 'sort_order' => 1, 'allowed_next' => ['b'],
    ]);
    $b = $type->stages()->create([
        'key' => 'b', 'label' => 'Giai đoạn B', 'client_label' => 'B', 'sort_order' => 2, 'allowed_next' => [],
    ]);

    expect(fn () => $b->update(['key' => 'c']))->toThrow(StageKeyInUse::class, $type->name);

    expect($b->fresh()->key)->toBe('b');
});

/**
 * `MatterTypeStagePolicy::delete()` (luật xoá) và `MatterTypeStage::keyInUse()` (luật đổi `key`)
 * phải trả lời GIỐNG NHAU cho câu hỏi "còn giai đoạn nào khác trỏ `allowed_next` vào key này
 * không" — cả hai dùng chung `stagesReferencing()`, không lặp lại truy vấn ở hai nơi (đúng yêu
 * cầu vòng rà soát: "share one helper between the two paths").
 */
it('shares the allowed_next reference check between the delete guard and the rename guard', function () {
    $type = MatterType::factory()->create();
    $a = $type->stages()->create([
        'key' => 'a', 'label' => 'Giai đoạn A', 'client_label' => 'A', 'sort_order' => 1, 'allowed_next' => ['b'],
    ]);
    $b = $type->stages()->create([
        'key' => 'b', 'label' => 'Giai đoạn B', 'client_label' => 'B', 'sort_order' => 2, 'allowed_next' => [],
    ]);

    expect($b->referencingStageLabels()->all())->toBe([$a->label])
        ->and($b->isKeyInUse())->toBeTrue()
        ->and($a->referencingStageLabels()->all())->toBe([])
        ->and($a->isKeyInUse())->toBeFalse();
});

/**
 * Task 19, vòng sửa 1 (Important): `MatterType::stageIncludingTrashed()` — xoá mềm một giai đoạn
 * mà chỉ LỊCH SỬ (`stage_logs`) còn dùng là hành vi ĐƯỢC PHÉP, nên `stage($key)` (chỉ đọc dòng còn
 * sống) trả `null` không có nghĩa là dòng lịch sử đó phải mất nhãn tiếng Việt của nó.
 */
it('resolves a stage label including a soft-deleted one that only history still references', function () {
    $type = MatterType::factory()->withStages()->create();
    $label = $type->stage('intake')->label;

    $type->stage('intake')->delete();

    $fresh = $type->fresh();

    expect($fresh->stage('intake'))->toBeNull()
        ->and($fresh->stageIncludingTrashed('intake')?->key)->toBe('intake')
        ->and($fresh->stageIncludingTrashed('intake')?->label)->toBe($label);
});

/** Vế dương: một `key` còn SỐNG vẫn được ưu tiên (trường hợp xoá mềm rồi tạo lại cùng `key`). */
it('prefers the live stage over a soft-deleted one with the same key', function () {
    $type = MatterType::factory()->withStages()->create();
    $type->stage('intake')->delete();

    $recreated = $type->stages()->create([
        'key' => 'intake', 'label' => 'Tiếp nhận lại', 'client_label' => 'x', 'sort_order' => 1, 'allowed_next' => [],
    ]);

    expect($type->fresh()->stageIncludingTrashed('intake')?->getKey())->toBe($recreated->getKey());
});

/**
 * Cùng lỗ hổng như `matter_type_stages.key` từng có trước M3, ghi lại ở rà soát M3 vòng 2 và đóng
 * ở M4 Task 7: `matter_types.code` mang một `unique` ở DB, mà MariaDB không có unique một phần,
 * nên ràng buộc đó tính cả các dòng đã xoá mềm. Hệ quả rất cụ thể: xoá một loại vụ việc đặt nhầm
 * rồi tạo lại đúng loại ấy với cùng mã — việc bình thường nhất sau một lần gõ sai — bị chặn, mà
 * người dùng không có cách nào nhìn thấy dòng đang chặn mình. Mã hồ sơ (SPEC §6.1) nhúng mã loại
 * vụ việc, nên "dùng mã khác đi" không phải một lối thoát: nó đổi cách đánh số hồ sơ của văn
 * phòng vĩnh viễn.
 */
it('allows recreating a matter type code after the original row is soft-deleted', function () {
    $original = MatterType::factory()->create(['code' => 'DS']);
    $original->delete();

    $recreated = MatterType::factory()->create(['code' => 'DS']);

    expect($recreated->exists)->toBeTrue()
        ->and(MatterType::withTrashed()->where('code', 'DS')->count())->toBe(2);
});

/**
 * Cặp âm: bỏ ràng buộc ở DB không được biến thành "không còn ràng buộc nào". Chốt chặn chuyển
 * vào model (`saving`) để MỌI đường ghi đi qua — form, seeder, factory, Action, artisan.
 */
it('rejects a duplicate live matter type code through bare Eloquent, not just the form', function () {
    MatterType::factory()->create(['code' => 'DS']);

    expect(fn () => MatterType::factory()->create(['code' => 'DS']))
        ->toThrow(DuplicateMatterTypeCode::class, 'DS');
});

it('lets a matter type save again without changing its code without tripping the duplicate guard', function () {
    $type = MatterType::factory()->create(['code' => 'DS']);

    $type->update(['name' => 'Dân sự (đổi tên)']);

    expect($type->fresh()->name)->toBe('Dân sự (đổi tên)');
});
