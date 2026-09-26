<?php

use App\Enums\Role;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\MatterTypeStage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;

/**
 * Task 19 (rà soát cuối, "Cấu hình không phá dữ liệu đang chạy"): trước bản vá này, xoá mềm một
 * giai đoạn mà hồ sơ đang đứng ở đó làm hồ sơ ấy ĐÓNG BĂNG vĩnh viễn trên giao diện — cả "Chuyển
 * giai đoạn" lẫn "Thêm cập nhật" đều ném `InvalidStageTransition`, kể cả với admin. Mirror hoàn
 * toàn `MatterTypePolicyTest.php` cho `MatterTypeStagePolicy::delete()`, cộng hai điều kiện riêng
 * của giai đoạn (allowed_next) và bộ cổng thô `deleteAny`/`restoreAny`/`forceDeleteAny`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->client = Client::factory()->create();
});

/**
 * Ba giai đoạn TỰ kiểm soát `allowed_next` — `StagePresets::civil()` (bộ mà `withStages()` gắn)
 * không có giai đoạn nào KHÔNG bị một giai đoạn khác trỏ tới trong `allowed_next` (mọi giai đoạn
 * của bộ dân sự đều được ít nhất một giai đoạn khác mời tới, kể cả 'on_hold' — bị 'intake' VÀ
 * 'collecting_documents' trỏ tới), nên không tách được hai điều kiện "còn hồ sơ đứng" và "còn
 * trong allowed_next của giai đoạn khác" một cách độc lập. Ba giai đoạn tối giản dưới đây thì có:
 * 'a' trỏ tới 'b' (nên xoá 'b' bị chặn), 'c' không ai trỏ tới và không hồ sơ nào đứng — "không ai
 * dùng" theo đúng nghĩa của cả hai điều kiện.
 *
 * @return array{0: MatterType, 1: MatterTypeStage, 2: MatterTypeStage, 3: MatterTypeStage}
 */
function threeStagesForDeleteGuardTests(): array
{
    $type = MatterType::factory()->create();

    $a = $type->stages()->create(['key' => 'a', 'label' => 'Giai đoạn A', 'client_label' => 'A', 'sort_order' => 1, 'allowed_next' => ['b']]);
    $b = $type->stages()->create(['key' => 'b', 'label' => 'Giai đoạn B', 'client_label' => 'B', 'sort_order' => 2, 'allowed_next' => []]);
    $c = $type->stages()->create(['key' => 'c', 'label' => 'Giai đoạn C', 'client_label' => 'C', 'sort_order' => 3, 'allowed_next' => []]);

    return [$type->fresh(), $a, $b, $c];
}

it('lets settings.manage delete a stage that nothing uses', function () {
    [, , , $unused] = threeStagesForDeleteGuardTests();

    expect($this->admin->can('delete', $unused))->toBeTrue();
});

/** Vế âm: một hồ sơ đang đứng ĐÚNG ở giai đoạn này chặn xoá, và thông điệp nêu đúng số lượng. */
it('refuses to delete a stage a matter is currently standing at, and says how many', function () {
    [$type, $stage, , $unused] = threeStagesForDeleteGuardTests();

    Matter::factory()->for($this->client)->for($type)->atStage('a')->create();
    Matter::factory()->for($this->client)->for($type)->atStage('a')->create();

    $response = Gate::forUser($this->admin)->inspect('delete', $stage);

    expect($response->allowed())->toBeFalse()
        ->and($response->message())->toBe(__('matter_types.stages.delete_blocked_in_use', ['count' => 2]))
        // Vế dương trong cùng test: một giai đoạn KHÁC của cùng loại, không hồ sơ nào đứng ở đó
        // và không ai trỏ tới, vẫn xoá được bình thường — chốt chặn không lan ra ngoài đúng giai
        // đoạn bị chiếm.
        ->and($this->admin->can('delete', $unused))->toBeTrue();
});

/** Một hồ sơ đã xoá mềm vẫn tính là đang dùng (vẫn khôi phục được) — cùng luật MatterTypePolicy. */
it('counts a soft deleted matter as still using its stage, because it can be restored', function () {
    [$type, $stage] = threeStagesForDeleteGuardTests();
    $matter = Matter::factory()->for($this->client)->for($type)->atStage('a')->create();

    $matter->delete();

    expect($this->admin->can('delete', $stage))->toBeFalse();
});

/**
 * Vế âm riêng của giai đoạn (không có ở MatterTypePolicy): `key` của giai đoạn này còn nằm trong
 * `allowed_next` của một giai đoạn KHÁC. Xoá nó đi thì màn hình "Chuyển giai đoạn" của giai đoạn
 * kia mời một đích không còn cấu hình.
 */
it('refuses to delete a stage still listed in another stage\'s allowed_next', function () {
    [$type, $a, $b, $unused] = threeStagesForDeleteGuardTests();

    // Đặt một hồ sơ ở một giai đoạn KHÁC hẳn ('c') để tách hai điều kiện: nếu test này đỏ vì lý
    // do "còn hồ sơ đứng ở đó" thay vì "còn nằm trong allowed_next", phép đo trên đã sai mục tiêu.
    Matter::factory()->for($this->client)->for($type)->atStage('c')->create();

    $response = Gate::forUser($this->admin)->inspect('delete', $b);

    expect($response->allowed())->toBeFalse()
        ->and($response->message())->toBe(__('matter_types.stages.delete_blocked_allowed_next', ['labels' => $a->label]))
        // Vế dương: xoá đúng cái giai đoạn ĐANG trỏ tới ('a') không bị chặn — không hồ sơ nào
        // đứng ở đó và không ai trỏ tới NÓ.
        ->and($this->admin->can('delete', $a))->toBeTrue();
});

it('still refuses anyone without settings.manage, even for an unused stage', function () {
    [, , , $unused] = threeStagesForDeleteGuardTests();

    expect($this->lawyer->can('delete', $unused))->toBeFalse();
});

it('opens the bulk delete and restore gates to settings.manage and closes them to everyone else', function () {
    expect($this->admin->can('deleteAny', MatterTypeStage::class))->toBeTrue()
        ->and($this->admin->can('restoreAny', MatterTypeStage::class))->toBeTrue()
        ->and($this->lawyer->can('deleteAny', MatterTypeStage::class))->toBeFalse()
        ->and($this->lawyer->can('restoreAny', MatterTypeStage::class))->toBeFalse();
});

/** Không ai xoá vĩnh viễn một giai đoạn được, kể cả admin: dòng tiến độ cũ có thể vẫn trỏ vào nó. */
it('never allows permanent deletion of a stage, even for the admin', function () {
    [, , , $unused] = threeStagesForDeleteGuardTests();

    expect($this->admin->can('forceDelete', $unused))->toBeFalse()
        ->and($this->admin->can('forceDeleteAny', MatterTypeStage::class))->toBeFalse();
});
