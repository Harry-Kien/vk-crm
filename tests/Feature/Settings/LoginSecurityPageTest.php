<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\Settings\LoginSecurityPage;
use App\Models\SystemHealth;
use App\Models\User;
use App\Support\LoginThrottle;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Làn st Task 1, mục 4 — "Bảo mật đăng nhập": trạng thái THẬT, đọc-chỉ, mỗi dòng nói nó được đặt ở
 * đâu. Không ô nào sửa được (2FA bắt buộc, giới hạn IP, HTTPS/HSTS/CSP, ClamAV là luật/máy chủ).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->withRole(Role::Admin)->create();

    config([
        'vkcrm.security.admin_ip_allowlist' => '',
        'vkcrm.security.force_https' => null,
        'vkcrm.security.hsts_max_age' => null,
        'vkcrm.security.csp_mode' => null,
        'vkcrm.clamav.enabled' => false,
        'vkcrm.backup.rclone.remote' => null,
    ]);
});

function securityPage()
{
    return Livewire::test(LoginSecurityPage::class);
}

it('opens for an admin only', function (?Role $role) {
    $user = $role === null ? $this->admin : User::factory()->withRole($role)->create();

    $response = $this->actingAs($user, 'web')->get(LoginSecurityPage::getUrl(panel: 'admin'));

    $role === null ? $response->assertOk()->assertSee(__('settings.security.title')) : $response->assertNotFound();
})->with([
    'admin' => [null],
    'trưởng phòng' => [Role::Manager],
    'luật sư' => [Role::Lawyer],
    'trợ lý' => [Role::Assistant],
    'kế toán' => [Role::Accountant],
    'kinh doanh' => [Role::Sales],
]);

it('says two-factor sign-in is always required, and names the active staff who have not set it up', function () {
    User::factory()->withoutTwoFactor()->withRole(Role::Lawyer)->create(['name' => 'Chưa Cài Hai Lớp']);
    User::factory()->withoutTwoFactor()->withRole(Role::Lawyer)->create(['name' => 'Đã Nghỉ Việc', 'is_active' => false]);

    $this->actingAs($this->admin, 'web');

    $active = User::query()->where('is_active', true)->count();

    securityPage()
        ->assertSee(__('settings.security.rows.two_factor.value'))
        ->assertSee(__('settings.security.rows.two_factor_staff.value', ['done' => $active - 1, 'missing' => 1]))
        ->assertSee('Chưa Cài Hai Lớp')
        ->assertDontSee('Đã Nghỉ Việc');
});

/** Mutation probe: in thẳng `RestrictAdminIpAllowlist::entries()` thay vì đếm → dải IP lộ ra trang. */
it('reports the admin IP allowlist by count, never printing the ranges', function () {
    $this->actingAs($this->admin, 'web');

    securityPage()->assertSee(__('settings.security.rows.ip_allowlist.off'));

    config(['vkcrm.security.admin_ip_allowlist' => '10.20.0.0/16, 203.113.7.9']);

    securityPage()
        ->assertSee(__('settings.security.rows.ip_allowlist.on', ['count' => 2]))
        ->assertDontSee('10.20.0.0/16')
        ->assertDontSee('203.113.7.9')
        ->assertSee('ADMIN_IP_ALLOWLIST');
});

it('reads the login lockout from the code that enforces it', function () {
    $this->actingAs($this->admin, 'web');

    securityPage()->assertSee(__('settings.security.rows.lockout.value', [
        'attempts' => LoginThrottle::MAX_ATTEMPTS,
        'minutes' => intdiv(LoginThrottle::DECAY_SECONDS, 60),
    ]));
});

it('shows HTTPS, HSTS, CSP and virus scanning as they really run, with where each is set', function () {
    $this->actingAs($this->admin, 'web');

    // Môi trường `testing`: để trống = nới (không ép HTTPS, HSTS tắt, CSP chỉ báo cáo).
    securityPage()
        ->assertSee(__('settings.security.rows.https.off'))
        ->assertSee(__('settings.security.rows.hsts.off'))
        ->assertSee(__('settings.security.rows.csp.report'))
        ->assertSee(__('settings.security.rows.clamav.off'))
        ->assertSee('FORCE_HTTPS')
        ->assertSee('CLAMAV_ENABLED');

    config([
        'vkcrm.security.force_https' => 'true',
        'vkcrm.security.hsts_max_age' => '31536000',
        'vkcrm.security.csp_mode' => 'enforce',
        'vkcrm.clamav.enabled' => true,
    ]);

    securityPage()
        ->assertSee(__('settings.security.rows.https.on'))
        ->assertSee(__('settings.security.rows.hsts.on', ['days' => 365]))
        ->assertSee(__('settings.security.rows.csp.enforce'))
        ->assertSee(__('settings.security.rows.clamav.on'));
});

it('shows the last verified off-site backup', function () {
    $this->actingAs($this->admin, 'web');

    securityPage()->assertSee(__('settings.security.rows.backup.not_configured'));

    config(['vkcrm.backup.rclone.remote' => 'gdrive:vkcrm']);

    securityPage()->assertSee(__('settings.security.rows.backup.never'));

    SystemHealth::current()->forceFill(['last_offsite_backup_at' => now()->setTime(2, 15)])->save();

    securityPage()->assertSee(__('settings.security.rows.backup.last', ['at' => now()->setTime(2, 15)->format('d/m/Y H:i')]));
});

it('is read-only', function () {
    $this->actingAs($this->admin, 'web');

    securityPage()->assertDontSeeHtml('wire:submit')->assertSee(__('settings.security.read_only'));
});
