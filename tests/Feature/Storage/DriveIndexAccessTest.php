<?php

use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\DriveFolder;
use App\Models\DriveObject;
use App\Models\User;
use App\Policies\DriveFolderPolicy;
use App\Policies\DriveObjectPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;

/*
|--------------------------------------------------------------------------
| M14 Task 1 — `drive_objects`, `drive_folders`: không ai đọc qua giao diện, cổng khách không thấy
|--------------------------------------------------------------------------
|
| Hai bảng của hạ tầng (kế hoạch M14, "Mô hình dữ liệu"): `file_id` không bao giờ rời máy chủ (R3),
| nên không màn hình nào liệt kê chúng. Model dùng `RestrictedToClientPortal` (`1 = 0`) và policy
| từ chối mọi thao tác với mọi người, kể cả admin. `PortalCoverageTest` xanh KHÔNG cần thêm dòng
| miễn trừ nào.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    DriveObject::query()->create([
        'drive_id' => '0AbCdEfGhIjKlUk9PVA',
        'object_key' => '18/01k6xq0f9m2y7c4w8r3t5v6n1b.pdf',
        'file_id' => '1aBcDeFgHiJkLmNoPqRsTuVwXyZ012345',
        'parent_id' => 'thu-muc-2026-10',
        'size' => 832,
        'md5' => str_repeat('a', 32),
    ]);

    DriveFolder::query()->create([
        'drive_id' => '0AbCdEfGhIjKlUk9PVA',
        'root_folder_id' => 'goc',
        'name' => '2026-10',
        'folder_id' => 'thu-muc-2026-10',
    ]);
});

it('khách đăng nhập cổng không đọc được dòng nào của drive_objects và drive_folders', function () {
    $clientUser = ClientUser::factory()->create(['client_id' => Client::factory()->create()->id]);

    // Tự kiểm: ngoài ngữ cảnh cổng, hai dòng có thật.
    expect(DriveObject::query()->count())->toBe(1)
        ->and(DriveFolder::query()->count())->toBe(1);

    $this->actingAs($clientUser, 'client');

    expect(DriveObject::query()->count())->toBe(0)
        ->and(DriveFolder::query()->count())->toBe(0)
        ->and(DriveObject::query()->where('object_key', '18/01k6xq0f9m2y7c4w8r3t5v6n1b.pdf')->first())->toBeNull();
});

it('policy từ chối mọi thao tác với mọi người, kể cả admin và khách', function (string $ability) {
    // Tự kiểm: admin THẬT SỰ là admin (cùng câu hỏi trên một model khác trả `true`), không thì test
    // xanh vì người hỏi không có quyền gì, không vì policy.
    expect(Gate::forUser(User::factory()->withRole(Role::Admin)->create())->allows('viewAny', User::class))->toBeTrue();

    $people = [
        'admin' => User::factory()->withRole(Role::Admin)->create(),
        'luật sư' => User::factory()->withRole(Role::Lawyer)->create(),
        'khách' => ClientUser::factory()->create(['client_id' => Client::factory()->create()->id]),
    ];

    $object = DriveObject::query()->firstOrFail();
    $folder = DriveFolder::query()->firstOrFail();

    foreach ($people as $who => $person) {
        $gate = Gate::forUser($person);

        $arguments = in_array($ability, ['viewAny', 'create'], true);

        expect($gate->allows($ability, $arguments ? DriveObject::class : $object))->toBeFalse("{$who} {$ability} DriveObject")
            ->and($gate->allows($ability, $arguments ? DriveFolder::class : $folder))->toBeFalse("{$who} {$ability} DriveFolder");
    }
})->with(['viewAny', 'view', 'create', 'update', 'delete', 'restore', 'forceDelete']);

/**
 * R3: không mã tệp Drive nào rời máy chủ — kể cả qua một `toArray()`/`toJson()` vô tình (thuộc tính
 * Livewire, ngữ cảnh log). Cặp dương: mã của kho vẫn đọc được mã đó như thuộc tính.
 */
it('mã Drive không bao giờ có trong toArray/toJson của hai model, nhưng mã của kho vẫn đọc được', function () {
    $object = DriveObject::query()->firstOrFail();
    $folder = DriveFolder::query()->firstOrFail();

    $serialised = $object->toJson().$folder->toJson();

    expect($serialised)->not->toContain('1aBcDeFgHiJkLmNoPqRsTuVwXyZ012345')
        ->and($serialised)->not->toContain('0AbCdEfGhIjKlUk9PVA')
        ->and($serialised)->not->toContain('thu-muc-2026-10')
        ->and($serialised)->not->toContain('"goc"')
        ->and($object->toArray())->not->toHaveKeys(['file_id', 'parent_id', 'drive_id'])
        ->and($folder->toArray())->not->toHaveKeys(['folder_id', 'root_folder_id', 'drive_id'])
        ->and($object->toArray())->toHaveKey('object_key', '18/01k6xq0f9m2y7c4w8r3t5v6n1b.pdf')
        ->and($object->file_id)->toBe('1aBcDeFgHiJkLmNoPqRsTuVwXyZ012345')
        ->and($folder->folder_id)->toBe('thu-muc-2026-10');
});

/**
 * R10: chỉ lượt nhập biên nhận văn phòng ghi `office_copied_at`, bằng một câu UPDATE có chủ đích.
 * Một `create()`/`fill()` mang theo cột đó (ví dụ chép mảng từ một dòng khác) không đặt được nó.
 */
it('office_copied_at không gán hàng loạt được qua create/fill', function () {
    $object = DriveObject::query()->create([
        'drive_id' => '0AbCdEfGhIjKlUk9PVA',
        'object_key' => '19/01k6xq0f9m2y7c4w8r3t5v6n1c.pdf',
        'file_id' => 'tep-khac',
        'parent_id' => 'thu-muc-2026-10',
        'size' => 10,
        'md5' => str_repeat('b', 32),
        'office_copied_at' => now(),
    ]);

    expect($object->fresh()->office_copied_at)->toBeNull()
        ->and((new DriveObject)->isFillable('office_copied_at'))->toBeFalse()
        ->and((new DriveObject)->isFillable('md5'))->toBeTrue();
});

/**
 * Cặp dương của test trên: Gate thật sự tìm ra HAI policy này (không phải "không có policy nên mọi
 * câu hỏi đều sai"). Thiếu policy thì `Gate::getPolicyFor()` trả null, và test trên vẫn xanh vì một
 * lý do khác hẳn lý do cần đo.
 */
it('Gate tìm đúng DriveObjectPolicy và DriveFolderPolicy', function () {
    expect(Gate::getPolicyFor(DriveObject::class))->toBeInstanceOf(DriveObjectPolicy::class)
        ->and(Gate::getPolicyFor(DriveFolder::class))->toBeInstanceOf(DriveFolderPolicy::class);
});
