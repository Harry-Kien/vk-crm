<?php

use App\Enums\Role;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Xoá một loại vụ việc CÒN AI DÙNG là một cú bấm ở màn hình thiết lập làm vỡ trang chi tiết hồ sơ
 * của mọi khách hàng đang đứng trong loại ấy — `Matter::currentStage()` và nhãn giai đoạn ở khối 3
 * của SPEC §8.3 đều đọc qua `matterType`, và một quan hệ trỏ vào một dòng đã xoá mềm trả về
 * `null`.
 *
 * Vòng sửa này vá hai đầu, và CẢ HAI đều cần: phía đọc được viết lại cho an toàn với `null` (test
 * ở `MatterProgressTest`), còn phía ghi — tệp này — không để tình huống ấy xảy ra ngay từ đầu.
 * Chỉ vá phía đọc thì khách vẫn mất nhãn giai đoạn của mình mà không ai biết; chỉ vá phía ghi thì
 * dữ liệu cũ (và mọi đường ghi không đi qua `Gate`) vẫn làm vỡ trang.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->client = Client::factory()->create();
});

it('lets settings.manage delete a matter type that nothing uses', function () {
    $unused = MatterType::factory()->withStages()->create();

    expect($unused->matters()->withTrashed()->exists())->toBeFalse()
        ->and($this->admin->can('delete', $unused))->toBeTrue();
});

it('refuses to delete a matter type a matter still points at', function () {
    $inUse = MatterType::factory()->withStages()->create();
    Matter::factory()->for($this->client)->for($inUse)->create();

    expect($this->admin->can('delete', $inUse))->toBeFalse()
        // Vế dương trong cùng một test: quyền của người này không đổi, chỉ có hồ sơ chắn đường.
        ->and($this->admin->can('update', $inUse))->toBeTrue()
        ->and($this->admin->can('delete', MatterType::factory()->withStages()->create()))->toBeTrue();
});

/**
 * Một hồ sơ đã xoá mềm vẫn KHÔI PHỤC ĐƯỢC — đó là toàn bộ ý nghĩa của xoá mềm ở đây — nên nó vẫn
 * là một người dùng của loại vụ việc này. Xoá loại đi rồi khôi phục hồ sơ là dựng lại đúng cái
 * trang vỡ mà vòng này đang đóng.
 */
it('counts a soft deleted matter as still using the type, because it can be restored', function () {
    $inUse = MatterType::factory()->withStages()->create();
    $matter = Matter::factory()->for($this->client)->for($inUse)->create();

    $matter->delete();

    expect($matter->fresh()->trashed())->toBeTrue()
        ->and($inUse->matters()->exists())->toBeFalse()
        ->and($this->admin->can('delete', $inUse))->toBeFalse();
});

/** Luật cũ không bị lần vá này nới ra: không có `settings.manage` thì vẫn không xoá được gì. */
it('still refuses anyone without settings.manage, even for an unused type', function () {
    $unused = MatterType::factory()->withStages()->create();

    expect($this->lawyer->can('delete', $unused))->toBeFalse();
});
