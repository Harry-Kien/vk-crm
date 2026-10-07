<?php

use App\Actions\Settings\WriteSettings;
use App\Enums\Role;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * M7 Task 10 — bước ghi CHUNG của bảng `settings`. `UpdateOfficeProfile` dựng trên nó; M11 sẽ lưu
 * `mcp.enabled`, `mcp.write_enabled` qua CHÍNH bước này (kế hoạch M11, R2), với Action và audit
 * riêng của M11. Bước này không hỏi quyền và không ghi audit: đó là việc của Action gọi nó, nơi
 * biết lần ghi này có nghĩa gì.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withRole(Role::Admin)->create();
});

it('ghi khoá bất kỳ, trả về đúng những khoá có giá trị đổi, ghi người lưu', function () {
    $write = app(WriteSettings::class);

    expect($write->handle(['mcp.enabled' => '1', 'mcp.write_enabled' => '0'], $this->admin))
        ->toBe(['mcp.enabled', 'mcp.write_enabled']);

    expect($write->handle(['mcp.enabled' => '1', 'mcp.write_enabled' => '1'], $this->admin))
        ->toBe(['mcp.write_enabled']);

    expect(Setting::query()->where('key', 'mcp.enabled')->value('value'))->toBe('1')
        ->and(Setting::query()->where('key', 'mcp.write_enabled')->value('value'))->toBe('1')
        ->and(Setting::query()->where('key', 'mcp.write_enabled')->value('updated_by'))->toBe($this->admin->id);
});

/** `'0'` là một giá trị (công tắc tắt của M11), không phải "trống". */
it('coi chuỗi rỗng và khoảng trắng là null, nhưng giữ "0"', function () {
    $write = app(WriteSettings::class);

    $write->handle(['a.zero' => '0', 'a.empty' => '', 'a.space' => '  '], $this->admin);

    expect(Setting::query()->where('key', 'a.zero')->value('value'))->toBe('0')
        ->and(Setting::query()->where('key', 'a.empty')->value('value'))->toBeNull()
        ->and(Setting::query()->where('key', 'a.space')->value('value'))->toBeNull();
});

it('không đụng tới khoá không có trong lần ghi', function () {
    $write = app(WriteSettings::class);

    $write->handle(['office.hotline' => '0909123456', 'mcp.enabled' => '1'], $this->admin);
    $write->handle(['mcp.enabled' => '0'], $this->admin);

    expect(Setting::query()->where('key', 'office.hotline')->value('value'))->toBe('0909123456');
});

it('ghi null vào một khoá chưa từng có không tính là đổi', function () {
    expect(app(WriteSettings::class)->handle(['mcp.enabled' => null], $this->admin))->toBe([]);
});

/**
 * Cột `settings.key` dài 100 ký tự; MariaDB strict ném lỗi 1406 cho khoá dài hơn. Đây là lỗi lập
 * trình (khoá do mã đặt, không do người gõ), nên ném `InvalidArgumentException` trước khi chạm DB.
 * Mutation probe: bỏ lần kiểm làm test đỏ.
 */
it('từ chối khoá rỗng hoặc dài hơn cột settings.key', function (string $key) {
    expect(fn () => app(WriteSettings::class)->handle([$key => 'x'], $this->admin))
        ->toThrow(InvalidArgumentException::class)
        ->and(Setting::query()->count())->toBe(0);
})->with([
    'rỗng' => [''],
    '101 ký tự' => [str_repeat('k', 101)],
]);

/**
 * `(string) false` là `''`, tức "chưa đặt" — ngược hẳn ý người gọi muốn TẮT một công tắc.
 * Mutation probe: bỏ lần kiểm làm test đỏ (không ném).
 */
it('từ chối giá trị không phải chuỗi hay null', function (mixed $value) {
    expect(fn () => app(WriteSettings::class)->handle(['mcp.enabled' => $value], $this->admin))
        ->toThrow(InvalidArgumentException::class)
        ->and(Setting::query()->count())->toBe(0);
})->with([
    'false' => [false],
    'true' => [true],
    'số' => [1],
    'mảng' => [['1']],
]);

it('nhận khoá dài đúng 100 ký tự', function () {
    app(WriteSettings::class)->handle([str_repeat('k', 100) => 'x'], $this->admin);

    expect(Setting::query()->where('key', str_repeat('k', 100))->value('value'))->toBe('x');
});
