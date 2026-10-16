<?php

use App\Actions\Settings\UpdateBusinessSettings;
use App\Enums\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings\BusinessSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Làn st — luật của chính Action `UpdateBusinessSettings`, độc lập với form (form có `minValue`/
 * `maxValue` riêng, nên test màn hình không phân biệt được lớp nào chặn). Hành vi màn hình đo ở
 * `BusinessSettingsPageTest`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->withRole(Role::Admin)->create();

    config([
        'vkcrm.client_access_days' => 90,
        'livewire.temporary_file_upload.rules' => ['required', 'file', 'max:'.(20 * 1024)],
    ]);
});

/** Mutation probe: bỏ luật `between` của Action → các hàng này lưu được. */
it('refuses a number outside its limits even without the form', function (string $field, mixed $value) {
    expect(fn () => app(UpdateBusinessSettings::class)->handle($this->admin, [$field => $value]))
        ->toThrow(ValidationException::class);

    expect(Setting::query()->where('key', 'like', 'business.%')->count())->toBe(0);
})->with([
    'số ngày 0' => ['client_access_days', 0],
    'số ngày 731' => ['client_access_days', 731],
    'số giờ 41' => ['intake_response_hours', 41],
    'tệp quá trần' => ['upload_max_mb', 21],
    'chữ' => ['client_access_days', 'chín mươi'],
    'công tắc lạ' => ['client_requests', 'có lẽ'],
]);

/** Mutation probe: bỏ lần hỏi `Gate` đầu `handle()`/`reset()` → không còn `AuthorizationException`. */
it('refuses anyone without settings.manage, for both saving and resetting', function (Role $role) {
    $user = User::factory()->withRole($role)->create();

    expect(fn () => app(UpdateBusinessSettings::class)->handle($user, ['client_access_days' => 120]))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateBusinessSettings::class)->reset($user))
        ->toThrow(AuthorizationException::class);

    expect(BusinessSettings::clientAccessDays())->toBe(90);
})->with([Role::Manager, Role::Lawyer, Role::Assistant, Role::Accountant, Role::Sales]);

it('ignores keys it does not own', function () {
    app(UpdateBusinessSettings::class)->handle($this->admin, ['office.hotline' => '0900000000', 'retention_years' => 1]);

    expect(Setting::query()->count())->toBe(0);
});

/**
 * Giá trị lưu hỏng (sửa tay trong CSDL) rơi về mặc định của máy chủ, không thành "0 ngày" hay "tắt".
 * Mutation probe: bỏ điều kiện khoảng trong `BusinessSettings::number()` → 0 ngày đi qua.
 */
it('reads a broken stored value as the server default', function (string $key, string $value) {
    Setting::query()->create(['key' => $key, 'value' => $value]);

    expect(BusinessSettings::clientAccessDays())->toBe(90)
        ->and(BusinessSettings::clientRequestsEnabled())->toBeTrue();
})->with([
    'số ngày 0' => [BusinessSettings::CLIENT_ACCESS_DAYS, '0'],
    'số ngày chữ' => [BusinessSettings::CLIENT_ACCESS_DAYS, 'abc'],
    'số ngày quá trần' => [BusinessSettings::CLIENT_ACCESS_DAYS, '9999'],
    'công tắc lạ' => [BusinessSettings::CLIENT_REQUESTS, 'false'],
]);
