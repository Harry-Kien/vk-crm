<?php

use App\Enums\Role;
use App\Filament\Admin\Auth\StaffAppAuthentication;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Models\Client;
use App\Models\User;
use Database\Seeders\DemoAccountsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\StaffSeeder;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FAQRCode\Google2FA;

/*
|--------------------------------------------------------------------------
| R2, §10.7 — 2FA ứng dụng bắt buộc cho toàn bộ tài khoản nội bộ
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

/** Mã TOTP hợp lệ tại thời điểm gọi, tính từ đúng secret (đã giải mã qua cast `encrypted`). */
function twoFactorRequiredTotpCode(User $user): string
{
    return app(Google2FA::class)->getCurrentOtp($user->two_factor_secret);
}

it('§10.7 panel admin bật 2FA bắt buộc, dùng bộ xác thực ứng dụng của Filament', function () {
    $panel = Filament::getPanel('admin');

    expect($panel->hasMultiFactorAuthentication())->toBeTrue()
        ->and($panel->isMultiFactorAuthenticationRequired())->toBeTrue()
        ->and($panel->getMultiFactorAuthenticationProviders())->toHaveKey('app')
        ->and($panel->getMultiFactorAuthenticationProviders()['app'])->toBeInstanceOf(StaffAppAuthentication::class);
});

/**
 * `UserFactory` mặc định có secret (kế hoạch, "sẽ cắn" #3) — tiền đề mà ~2.400 test khác của panel
 * admin dựa vào. Đo trực tiếp, không suy luận từ việc "các test khác đang xanh".
 */
it('§10.7 UserFactory sinh secret 2FA hợp lệ (base32, đọc được bởi Google2FA) mặc định', function () {
    $staff = User::factory()->create();

    expect($staff->two_factor_secret)->not->toBeNull()
        ->and(preg_match('/^[A-Z2-7]+$/', $staff->two_factor_secret))->toBe(1);

    // Secret hợp lệ thật sự — sinh được một mã 6 số mà chính thư viện xác nhận đúng.
    $code = twoFactorRequiredTotpCode($staff);

    expect($code)->toMatch('/^\d{6}$/')
        ->and(app(Google2FA::class)->verifyKey($staff->two_factor_secret, $code))->toBeTrue();
});

/** Đường "chưa cài" — dùng cho các test dưới cần một admin KHÔNG có secret. */
it('§10.7 withoutTwoFactor() trả về một nhân sự không có secret cũng như mã khôi phục', function () {
    $staff = User::factory()->withoutTwoFactor()->create();

    expect($staff->two_factor_secret)->toBeNull()
        ->and($staff->two_factor_recovery_codes)->toBeNull();
});

/**
 * Cột `two_factor_secret`/`two_factor_recovery_codes` mã hoá khi lưu — đọc thẳng CSDL (bỏ qua
 * cast của Eloquent) phải KHÁC bản rõ. `isEnabled()`/`getAppAuthenticationSecret()` vẫn đọc đúng
 * qua Model như bình thường (cast tự giải mã) — hai test này đo hai tầng khác nhau.
 */
it('§10.7 two_factor_secret nằm trong CSDL dưới dạng đã mã hoá, không phải văn bản thô', function () {
    $staff = User::factory()->create(['two_factor_secret' => 'JBSWY3DPEHPK3PXP']);

    $raw = DB::table('users')->where('id', $staff->id)->value('two_factor_secret');

    expect($raw)->not->toBe('JBSWY3DPEHPK3PXP')
        ->and($staff->fresh()->two_factor_secret)->toBe('JBSWY3DPEHPK3PXP')
        ->and($staff->fresh()->getAppAuthenticationSecret())->toBe('JBSWY3DPEHPK3PXP');
});

/*
|--------------------------------------------------------------------------
| Chưa cài 2FA → mọi route panel admin chỉ dẫn tới trang cài đặt bắt buộc
|--------------------------------------------------------------------------
*/

it('§10.7 admin chưa cài 2FA bị chuyển hướng sang trang cài đặt bắt buộc, kể cả trên trang sâu', function () {
    $staff = User::factory()->withoutTwoFactor()->withRole(Role::Admin)->create();
    $client = Client::factory()->create();

    $this->actingAs($staff, 'web');

    $setupUrl = Filament::getPanel('admin')->getSetUpRequiredMultiFactorAuthenticationUrl();

    $this->get('/admin')->assertRedirect($setupUrl);
    $this->get(ClientResource::getUrl('edit', ['record' => $client], panel: 'admin'))->assertRedirect($setupUrl);

    // Vẫn còn đăng nhập — bị dồn về trang cài đặt, không phải bị đăng xuất.
    expect(auth('web')->check())->toBeTrue();
});

/**
 * Trang cài đặt bắt buộc tự nó KHÔNG kẹt trong vòng lặp chuyển hướng — nó được đăng ký RIÊNG,
 * không qua `Filament\Pages\Concerns\HasRoutes::routes()` (đọc `vendor/filament/filament/routes/web.php`
 * để xác nhận: `Route::get($panel->getSetUpRequiredMultiFactorAuthenticationRouteSlug(), ...)` gọi
 * trần, không có `getRouteMiddleware()` của TỪNG trang), nên nó không tự chuyển hướng về CHÍNH nó.
 */
it('§10.7 trang cài đặt 2FA bắt buộc tự nó mở được cho người chưa cài, không lặp vô hạn', function () {
    $staff = User::factory()->withoutTwoFactor()->withRole(Role::Admin)->create();

    $this->actingAs($staff, 'web');

    $setupUrl = Filament::getPanel('admin')->getSetUpRequiredMultiFactorAuthenticationUrl();

    $this->get($setupUrl)->assertOk();
});

/*
|--------------------------------------------------------------------------
| M11 cần: đăng nhập → bước mã → quay lại đúng URL sâu ban đầu
|--------------------------------------------------------------------------
*/

/**
 * Một khách vãng lai mở thẳng một URL sâu của panel admin → bị đưa về trang đăng nhập
 * (`Authenticate` middleware cất URL vào `session('url.intended')`) → nhập mật khẩu (mở bước mã,
 * CHƯA đăng nhập) → nhập mã đúng (đăng nhập thật, `LoginResponse::toResponse()` gọi
 * `redirect()->intended(...)`) → về ĐÚNG URL sâu ban đầu, không phải trang chủ panel.
 *
 * `$this->get(...)` và `$this->livewire(Login::class)` dùng CHUNG một phiên (cùng một test case),
 * nên `url.intended` mà middleware cất ở bước đầu còn nguyên khi `Login::authenticate()` đọc nó ở
 * bước cuối — đúng đường một trình duyệt thật đi qua.
 */
it('§10.7 login → mã 2FA → quay lại đúng URL sâu ban đầu (redirect()->intended)', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create(['password' => 'mat-khau-dung']);
    $client = Client::factory()->create();

    $deepUrl = ClientResource::getUrl('edit', ['record' => $client], panel: 'admin');

    $this->get($deepUrl)->assertRedirect('/admin/login');

    $component = $this->livewire(Login::class)
        ->set('data.email', $staff->email)
        ->set('data.password', 'mat-khau-dung')
        ->call('authenticate')
        ->assertHasNoFormErrors();

    // Vẫn chỉ đang ở bước mã — chưa đăng nhập thật, redirect() ở trên chưa xảy ra.
    expect(auth('web')->check())->toBeFalse();

    $component
        ->set('data.multiFactor.app.code', twoFactorRequiredTotpCode($staff))
        ->call('authenticate')
        ->assertHasNoFormErrors()
        ->assertRedirect($deepUrl);

    expect(auth('web')->id())->toBe($staff->id);
});

/*
|--------------------------------------------------------------------------
| Tên hiển thị trong app xác thực = tên thương hiệu (phán quyết controller, mục 3)
|--------------------------------------------------------------------------
*/

it('§10.7 tên hiển thị trong app xác thực là tên thương hiệu ngắn, và tên chủ tài khoản là email', function () {
    /** @var StaffAppAuthentication $provider */
    $provider = Filament::getPanel('admin')->getMultiFactorAuthenticationProviders()['app'];
    $staff = User::factory()->create(['email' => 'ls@luatvukhang.com']);

    expect($provider->getBrandName())->toBe(config('vkcrm.brand.short_name'))
        ->and($provider->isRecoverable())->toBeTrue()
        ->and($provider->getHolderName($staff))->toBe('ls@luatvukhang.com');
});

/*
|--------------------------------------------------------------------------
| Secret demo cố định — chỉ ở local/testing (mục 6, phán quyết controller)
|--------------------------------------------------------------------------
*/

it('§10.7 secret demo cố định là base32 hợp lệ, dùng chung cho mọi tài khoản demo', function () {
    expect(preg_match('/^[A-Z2-7]+$/', DemoAccountsSeeder::DEMO_TWO_FACTOR_SECRET))->toBe(1);

    // Chính thư viện xác nhận đọc được — không chỉ đúng ký tự base32 mà còn sinh mã hợp lệ.
    $code = app(Google2FA::class)->getCurrentOtp(DemoAccountsSeeder::DEMO_TWO_FACTOR_SECRET);

    expect(app(Google2FA::class)->verifyKey(DemoAccountsSeeder::DEMO_TWO_FACTOR_SECRET, $code))->toBeTrue();
});

it('§10.7 seeder demo gán secret cố định ở testing, và cả StaffSeeder dùng chung đúng secret đó', function () {
    // `phpunit.xml` ghim APP_ENV=testing — nhánh gán chạy thật ở test này, không cần giả lập môi trường.
    $this->seed(DemoAccountsSeeder::class);
    $this->seed(StaffSeeder::class);

    $admin = User::query()->where('email', 'admin@luatvukhang.com')->firstOrFail();
    $manager = User::query()->where('email', 'quanly@luatvukhang.com')->firstOrFail();

    expect($admin->two_factor_secret)->toBe(DemoAccountsSeeder::DEMO_TWO_FACTOR_SECRET)
        ->and($manager->two_factor_secret)->toBe(DemoAccountsSeeder::DEMO_TWO_FACTOR_SECRET);
});

/**
 * Ép chạy seeder demo NGOÀI local/testing (văn phòng demo cho khách trên production, xem
 * docblock `DemoDataSeeder`) phải để trống secret — một secret CÔNG KHAI trong mã nguồn mà vẫn có
 * mặt trên production là một cửa sau 2FA thật, đúng thứ R2 cấm.
 *
 * `$this->seed()` không truyền `--force`, và `db:seed` trên `production` hỏi xác nhận qua
 * `ConfirmableTrait` — chạy trong test thì `OutputStyle` giả không có kỳ vọng nào ném lỗi thay vì
 * hỏi (đã đo, xem `tests/Feature/Seeders/DatabaseSeederEnvironmentTest.php::seedForced()`). Gọi
 * thẳng `Artisan` với `--force`, đúng cách vận hành thật (`docs/CAI-DAT.md`).
 */
it('§10.7 seeder demo KHÔNG gán secret cố định khi APP_ENV không phải local/testing', function () {
    app()->detectEnvironment(fn () => 'production');

    try {
        $this->artisan('db:seed', ['--class' => DemoAccountsSeeder::class, '--force' => true])->assertSuccessful();

        $admin = User::query()->where('email', 'admin@luatvukhang.com')->firstOrFail();

        expect($admin->two_factor_secret)->toBeNull();
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});
