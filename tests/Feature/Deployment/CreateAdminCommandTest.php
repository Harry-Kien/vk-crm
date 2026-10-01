<?php

use App\Actions\User\CreateAdminFromConsole;
use App\Enums\Role;
use App\Enums\UserPosition;
use App\Exceptions\AdminCreationRefused;
use App\Filament\Admin\Pages\Auth\Login;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| `vkcrm:create-admin` — tài khoản quản trị viên ĐẦU TIÊN trên máy chủ thật (kế hoạch M8 Task 7)
|--------------------------------------------------------------------------
|
| M6.5 Task 19 bỏ tài khoản demo khỏi seed production: sau `migrate --force` + `db:seed --force`
| không ai đăng nhập được `/admin`. Lệnh này là con đường duy nhất được ghi trong
| `docs/CAI-DAT.md` (thay khối `tinker` cũ). Mọi test đi qua lệnh Artisan THẬT
| (`$this->artisan(...)->expectsQuestion(...)`), không gọi thẳng Action — cùng cách
| `PreflightCommandTest` làm với `vkcrm:preflight`.
|
| Phán quyết controller (T7): mật khẩu KHÔNG BAO GIỜ đi qua tham số dòng lệnh (lọt vào lịch sử
| shell và danh sách tiến trình), và ở `--no-interaction` lệnh từ chối có thông báo.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

const CREATE_ADMIN_PASSWORD = 'Mat-khau-that-12';

/**
 * Trả lời đủ bốn câu hỏi của một lượt chạy thành công.
 *
 * @param  array<string, mixed>  $parameters
 */
function createAdminRun(
    string $name = 'Trần Quản Trị',
    string $email = 'quantri@luatvukhang.com',
    string $password = CREATE_ADMIN_PASSWORD,
    ?string $confirmation = null,
    array $parameters = [],
): mixed {
    return test()->artisan('vkcrm:create-admin', $parameters)
        ->expectsQuestion(__('users.create_admin.ask_name'), $name)
        ->expectsQuestion(__('users.create_admin.ask_email'), $email)
        ->expectsQuestion(__('users.create_admin.ask_password'), $password)
        ->expectsQuestion(__('users.create_admin.ask_password_confirmation'), $confirmation ?? $password);
}

/** Một email HỢP LỆ dài đúng `$length` ký tự (nhãn tên miền ≤ 63 ký tự, phần trước @ 60 ký tự). */
function createAdminEmailOfLength(int $length): string
{
    $fixed = 60 + 1 + 40 + 1 + 3; // "<60>@<40>.<N>.vn"

    return str_repeat('a', 60).'@'.str_repeat('b', 40).'.'.str_repeat('c', $length - $fixed).'.vn';
}

/*
|--------------------------------------------------------------------------
| Đường thành công
|--------------------------------------------------------------------------
*/

it('tạo một quản trị viên đang hoạt động, đúng vai admin, mật khẩu đã băm, CHƯA có 2FA', function () {
    createAdminRun()
        ->expectsOutputToContain(__('users.create_admin.created', [
            'name' => 'Trần Quản Trị',
            'email' => 'quantri@luatvukhang.com',
            'url' => Filament::getPanel('admin')->getLoginUrl(),
        ]))
        ->assertSuccessful();

    $admin = User::query()->where('email', 'quantri@luatvukhang.com')->sole();

    expect($admin->name)->toBe('Trần Quản Trị')
        ->and($admin->position)->toBe(UserPosition::Admin)
        ->and($admin->is_active)->toBeTrue()
        ->and($admin->hasRole(Role::Admin->value))->toBeTrue()
        ->and($admin->getRoleNames()->all())->toBe([Role::Admin->value])
        ->and($admin->password)->not->toBe(CREATE_ADMIN_PASSWORD)
        ->and(Hash::check(CREATE_ADMIN_PASSWORD, $admin->password))->toBeTrue()
        // Lần đăng nhập đầu Filament buộc cài 2FA (Task 2) — chỉ đúng khi secret còn TRỐNG.
        ->and($admin->two_factor_secret)->toBeNull()
        ->and($admin->two_factor_recovery_codes)->toBeNull();
});

it('cắt khoảng trắng hai đầu họ tên và email trước khi lưu', function () {
    createAdminRun(name: '  Trần Quản Trị  ', email: '  quantri@luatvukhang.com ')->assertSuccessful();

    $admin = User::query()->sole();

    expect($admin->name)->toBe('Trần Quản Trị')
        ->and($admin->email)->toBe('quantri@luatvukhang.com');
});

it('ghi nhật ký admin_created_via_console: không người thực hiện, via console, không mang mật khẩu', function () {
    createAdminRun()
        ->doesntExpectOutputToContain(CREATE_ADMIN_PASSWORD)
        ->assertSuccessful();

    $admin = User::query()->sole();
    $row = Activity::query()->where('event', 'admin_created_via_console')->sole();

    expect($row->subject_type)->toBe($admin->getMorphClass())
        ->and($row->subject_id)->toBe($admin->id)
        ->and($row->causer_id)->toBeNull()
        ->and($row->properties->get('via'))->toBe('console')
        ->and($row->properties->get('additional'))->toBeFalse()
        ->and($row->properties->get('admins_before'))->toBe(0);

    // Không dòng nhật ký nào — kể cả dòng `created` của LogsActivity — chứa mật khẩu hay bản băm.
    $everything = Activity::query()->get()->toJson();
    expect($everything)->not->toContain(CREATE_ADMIN_PASSWORD)
        ->and($everything)->not->toContain($admin->password);
});

/**
 * Hành vi khách quan của "bắt cài 2FA ở lần đăng nhập đầu" (brief mục 1): admin vừa tạo đăng nhập
 * bằng ĐÚNG trang đăng nhập của panel (Livewire), rồi mọi trang `/admin` dồn về trang cài 2FA
 * bắt buộc — và trang đó mở được.
 */
it('admin vừa tạo đăng nhập xong bị dẫn thẳng tới trang cài 2FA bắt buộc', function () {
    createAdminRun()->assertSuccessful();

    Filament::setCurrentPanel('admin');

    $this->livewire(Login::class)
        ->set('data.email', 'quantri@luatvukhang.com')
        ->set('data.password', CREATE_ADMIN_PASSWORD)
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(auth('web')->user()?->email)->toBe('quantri@luatvukhang.com');

    $setupUrl = Filament::getPanel('admin')->getSetUpRequiredMultiFactorAuthenticationUrl();

    $this->get('/admin')->assertRedirect($setupUrl);
    $this->get($setupUrl)->assertOk();
});

/*
|--------------------------------------------------------------------------
| Đã có quản trị viên — từ chối, trừ khi có --additional
|--------------------------------------------------------------------------
*/

it('từ chối ngay, trước khi hỏi gì, khi hệ thống đã có một quản trị viên — nêu số lượng', function () {
    User::factory()->admin()->create();

    $this->artisan('vkcrm:create-admin')
        ->expectsOutputToContain(__('users.create_admin.admins_exist', ['count' => 1]))
        ->assertFailed();

    expect(User::query()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'admin_created_via_console')->exists())->toBeFalse();
});

it('đếm cả quản trị viên đang bị vô hiệu hoá (brief: "dù đang hoạt động hay không")', function () {
    User::factory()->admin()->create(['is_active' => false]);
    User::factory()->admin()->create();

    $this->artisan('vkcrm:create-admin')
        ->expectsOutputToContain(__('users.create_admin.admins_exist', ['count' => 2]))
        ->assertFailed();

    expect(User::query()->count())->toBe(2);
});

it('không đếm quản trị viên đã xoá mềm — một hệ thống chỉ còn admin đã xoá vẫn tạo được', function () {
    User::factory()->admin()->create()->delete();

    createAdminRun()->assertSuccessful();

    expect(User::query()->where('email', 'quantri@luatvukhang.com')->exists())->toBeTrue();
});

it('không đếm nhân sự không phải quản trị viên', function () {
    User::factory()->position(UserPosition::Manager)->withRole(Role::Manager)->create();
    User::factory()->withRole(Role::Lawyer)->create();

    createAdminRun()->assertSuccessful();

    expect(User::query()->where('position', UserPosition::Admin)->count())->toBe(1);
});

it('--additional tạo thêm khi đã có quản trị viên, và nhật ký ghi rõ cờ đó cùng số admin trước đó', function () {
    User::factory()->admin()->create();

    createAdminRun(parameters: ['--additional' => true])
        ->expectsOutputToContain(__('users.create_admin.additional_notice', ['count' => 1]))
        ->assertSuccessful();

    expect(User::query()->where('position', UserPosition::Admin)->count())->toBe(2);

    $row = Activity::query()->where('event', 'admin_created_via_console')->sole();
    expect($row->properties->get('additional'))->toBeTrue()
        ->and($row->properties->get('admins_before'))->toBe(1)
        ->and($row->causer_id)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Không tương tác, không mật khẩu qua tham số
|--------------------------------------------------------------------------
*/

it('từ chối ở --no-interaction, có thông báo, không tạo gì', function () {
    $this->artisan('vkcrm:create-admin', ['--no-interaction' => true])
        ->expectsOutputToContain(__('users.create_admin.non_interactive'))
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});

it('không có tham số hay tuỳ chọn nào nhận mật khẩu, email hay họ tên — chỉ --additional', function () {
    $definition = Artisan::all()['vkcrm:create-admin']->getDefinition();

    $ownOptions = collect($definition->getOptions())
        ->keys()
        ->reject(fn (string $name) => in_array($name, ['help', 'quiet', 'verbose', 'version', 'ansi', 'no-interaction', 'env', 'silent'], true))
        ->values()
        ->all();

    expect($definition->getArguments())->toBe([])
        ->and($ownOptions)->toBe(['additional']);
});

/*
|--------------------------------------------------------------------------
| Email — đúng dạng, ≤ 150 (độ dài cột), chưa thuộc ai kể cả tài khoản đã xoá mềm
|--------------------------------------------------------------------------
*/

it('từ chối email đã thuộc một nhân sự đang có, KHÔNG hỏi mật khẩu, không lỗi 500', function () {
    User::factory()->withRole(Role::Lawyer)->create(['email' => 'trung@luatvukhang.com']);

    $this->artisan('vkcrm:create-admin')
        ->expectsQuestion(__('users.create_admin.ask_name'), 'Trần Quản Trị')
        ->expectsQuestion(__('users.create_admin.ask_email'), 'trung@luatvukhang.com')
        ->expectsOutputToContain(__('users.create_admin.email_taken', ['email' => 'trung@luatvukhang.com']))
        ->assertFailed();

    expect(User::query()->count())->toBe(1);
});

it('từ chối email của một nhân sự đã xoá mềm bằng câu riêng, không lỗi unique 500', function () {
    User::factory()->withRole(Role::Lawyer)->create(['email' => 'cu@luatvukhang.com'])->delete();

    $this->artisan('vkcrm:create-admin')
        ->expectsQuestion(__('users.create_admin.ask_name'), 'Trần Quản Trị')
        ->expectsQuestion(__('users.create_admin.ask_email'), 'cu@luatvukhang.com')
        ->expectsOutputToContain(__('users.create_admin.email_taken_trashed', ['email' => 'cu@luatvukhang.com']))
        ->assertFailed();

    expect(User::withTrashed()->count())->toBe(1);
});

it('từ chối email sai dạng', function () {
    $this->artisan('vkcrm:create-admin')
        ->expectsQuestion(__('users.create_admin.ask_name'), 'Trần Quản Trị')
        ->expectsQuestion(__('users.create_admin.ask_email'), 'khong-phai-email')
        ->expectsOutputToContain(__('validation.email', ['attribute' => __('users.create_admin.attributes.email')]))
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});

it('nhận email dài đúng 150 ký tự (độ dài cột users.email)', function () {
    $email = createAdminEmailOfLength(150);
    expect(mb_strlen($email))->toBe(150);

    createAdminRun(email: $email)->assertSuccessful();

    expect(User::query()->where('email', $email)->exists())->toBeTrue();
});

it('từ chối email dài 151 ký tự — trước khi chạm cột 150 ký tự của MariaDB strict', function () {
    $email = createAdminEmailOfLength(151);
    expect(mb_strlen($email))->toBe(151);

    $this->artisan('vkcrm:create-admin')
        ->expectsQuestion(__('users.create_admin.ask_name'), 'Trần Quản Trị')
        ->expectsQuestion(__('users.create_admin.ask_email'), $email)
        ->expectsOutputToContain(__('validation.max.string', ['attribute' => __('users.create_admin.attributes.email'), 'max' => 150]))
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Họ tên — bắt buộc, ≤ 100 ký tự (độ dài cột users.name)
|--------------------------------------------------------------------------
*/

it('từ chối họ tên để trống', function () {
    $this->artisan('vkcrm:create-admin')
        ->expectsQuestion(__('users.create_admin.ask_name'), '   ')
        ->expectsOutputToContain(__('validation.required', ['attribute' => __('users.create_admin.attributes.name')]))
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});

it('nhận họ tên đúng 100 ký tự có dấu (đếm ký tự, không đếm byte)', function () {
    $name = str_repeat('Đ', 100);

    createAdminRun(name: $name)->assertSuccessful();

    expect(User::query()->sole()->name)->toBe($name);
});

it('từ chối họ tên 101 ký tự', function () {
    $this->artisan('vkcrm:create-admin')
        ->expectsQuestion(__('users.create_admin.ask_name'), str_repeat('Đ', 101))
        ->expectsOutputToContain(__('validation.max.string', ['attribute' => __('users.create_admin.attributes.name'), 'max' => 100]))
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Mật khẩu — PasswordRule::default(), nhập hai lần phải khớp
|--------------------------------------------------------------------------
*/

it('từ chối mật khẩu yếu theo PasswordRule::default() (hôm nay: dưới 8 ký tự)', function () {
    $this->artisan('vkcrm:create-admin')
        ->expectsQuestion(__('users.create_admin.ask_name'), 'Trần Quản Trị')
        ->expectsQuestion(__('users.create_admin.ask_email'), 'quantri@luatvukhang.com')
        ->expectsQuestion(__('users.create_admin.ask_password'), 'ngan7ky')
        ->expectsOutputToContain(__('validation.min.string', ['attribute' => __('users.create_admin.attributes.password'), 'min' => 8]))
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});

it('đọc luật mật khẩu từ PasswordRule::default() — siết luật mặc định là siết luôn lệnh này', function () {
    Password::defaults(fn () => Password::min(12));

    try {
        $this->artisan('vkcrm:create-admin')
            ->expectsQuestion(__('users.create_admin.ask_name'), 'Trần Quản Trị')
            ->expectsQuestion(__('users.create_admin.ask_email'), 'quantri@luatvukhang.com')
            ->expectsQuestion(__('users.create_admin.ask_password'), 'Muoi-mot-11')
            ->expectsOutputToContain(__('validation.min.string', ['attribute' => __('users.create_admin.attributes.password'), 'min' => 12]))
            ->assertFailed();
    } finally {
        Password::$defaultCallback = null;
    }

    expect(User::query()->count())->toBe(0);
});

it('từ chối khi hai lần nhập mật khẩu không khớp', function () {
    createAdminRun(confirmation: CREATE_ADMIN_PASSWORD.'x')
        ->expectsOutputToContain(__('users.create_admin.password_mismatch'))
        ->assertFailed();

    expect(User::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'admin_created_via_console')->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Chốt chặn của chính Action — không tin rằng người gọi đã kiểm
|--------------------------------------------------------------------------
| Lệnh kiểm từng ô và đếm admin TRƯỚC khi hỏi, nên qua lệnh không dựng được trường hợp "có admin
| xuất hiện giữa lúc hỏi và lúc tạo" (hai người cùng chạy lệnh). Hai test dưới gọi thẳng
| `CreateAdminFromConsole::handle()` — không phải để chứng minh hành vi màn hình nào, mà để đo
| chốt chặn của chính Action, thứ lệnh dựa vào.
*/

it('handle() tự đếm lại quản trị viên trong transaction: có admin chen vào giữa thì từ chối', function () {
    User::factory()->admin()->create();

    expect(fn () => app(CreateAdminFromConsole::class)
        ->handle('Trần Quản Trị', 'quantri@luatvukhang.com', CREATE_ADMIN_PASSWORD, false))
        ->toThrow(AdminCreationRefused::class, __('users.create_admin.admins_exist', ['count' => 1]));

    expect(User::query()->where('email', 'quantri@luatvukhang.com')->exists())->toBeFalse()
        ->and(Activity::query()->where('event', 'admin_created_via_console')->exists())->toBeFalse();
});

it('handle() tự kiểm lại cả ba ô — một người gọi bỏ qua bước kiểm không tạo được tài khoản sai luật', function (string $name, string $email, string $password) {
    expect(fn () => app(CreateAdminFromConsole::class)->handle($name, $email, $password, false))
        ->toThrow(AdminCreationRefused::class);

    expect(User::query()->count())->toBe(0);
})->with([
    'họ tên trống' => ['  ', 'quantri@luatvukhang.com', CREATE_ADMIN_PASSWORD],
    'email sai dạng' => ['Trần Quản Trị', 'khong-phai-email', CREATE_ADMIN_PASSWORD],
    'mật khẩu yếu' => ['Trần Quản Trị', 'quantri@luatvukhang.com', 'ngan7ky'],
]);
