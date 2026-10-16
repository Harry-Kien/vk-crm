<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\Settings\LoginSecurityPage;
use App\Filament\Admin\Pages\Settings\SettingsHub;
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

/** Tông và câu của huy hiệu đầu tiên sau một mốc `data-*` trong HTML trang thật. */
function backupPill(string $html, string $anchor): array
{
    expect(preg_match('/'.preg_quote($anchor, '/').'.*?data-settings-pill="(\w+)"[^>]*>([^<]*)</s', $html, $m))->toBe(1);

    return ['tone' => $m[1], 'text' => html_entity_decode(trim($m[2]))];
}

/**
 * Rà soát cuối I2: một luật "bản sao lưu ngoài máy chủ quá hạn" — `SystemHealth::offsiteBackupIsStale()`
 * (ngưỡng `vkcrm.backup.rclone.max_age_hours`, cùng đồng hồ với lượt giám sát 08:00). Trang "Bảo mật đăng
 * nhập" và thẻ "Sao lưu" của trang tổng Cài đặt phải nói CÙNG một điều về cùng một bản: quá ngưỡng thì
 * cả hai đỏ và trang nói "quá hạn"; trong ngưỡng thì cả hai xanh; nới ngưỡng thì cả hai theo.
 *
 * Mutation probe: trang bỏ nhánh `offsiteBackupIsStale()` → vế 37 giờ đỏ (trang xanh, thẻ đỏ); thẻ bỏ nhánh
 * → vế 37 giờ đỏ ở thẻ; một trong hai viết cứng 36 thay vì đọc ngưỡng → vế ngưỡng 48 giờ đỏ.
 */
it('calls an off-site backup older than the max age overdue, on the page and on the hub card alike', function () {
    $this->actingAs($this->admin, 'web');
    config(['vkcrm.backup.rclone.remote' => 'gdrive:vkcrm', 'vkcrm.backup.rclone.max_age_hours' => 36]);

    $read = function (): array {
        $page = test()->get(LoginSecurityPage::getUrl(panel: 'admin'))->assertOk()->getContent();
        $hub = test()->get(SettingsHub::getUrl(panel: 'admin'))->assertOk()->getContent();

        return [backupPill($page, 'data-security-row="backup"'), backupPill($hub, 'data-settings-card="backup"')];
    };

    $old = now()->subHours(37)->startOfMinute();
    SystemHealth::current()->forceFill(['last_offsite_backup_at' => $old])->save();
    $at = $old->copy()->timezone(config('app.timezone'))->format('d/m/Y H:i');

    [$page, $card] = $read();

    expect($page['tone'])->toBe('danger')
        ->and($card['tone'])->toBe('danger')
        ->and($page['text'])->toBe("Quá hạn — bản đã xác minh gần nhất: {$at} (ngưỡng 36 giờ)")
        ->and($card['text'])->toBe("Quá hạn — bản đã xác minh gần nhất: {$at}");

    // Trong ngưỡng: cả hai xanh, câu "gần nhất" bình thường.
    $fresh = now()->subHours(35)->startOfMinute();
    SystemHealth::current()->forceFill(['last_offsite_backup_at' => $fresh])->save();
    $freshAt = $fresh->copy()->timezone(config('app.timezone'))->format('d/m/Y H:i');

    [$page, $card] = $read();

    expect($page['tone'])->toBe('success')
        ->and($card['tone'])->toBe('success')
        ->and($page['text'])->toBe(__('settings.security.rows.backup.last', ['at' => $freshAt]))
        ->and($card['text'])->toBe(__('settings.hub.cards.backup.status_last', ['at' => $freshAt]));

    // Nới ngưỡng lên 48 giờ: bản 37 giờ lại trong hạn ở CẢ HAI nơi.
    config(['vkcrm.backup.rclone.max_age_hours' => 48]);
    SystemHealth::current()->forceFill(['last_offsite_backup_at' => $old])->save();

    [$page, $card] = $read();

    expect($page['tone'])->toBe('success')->and($card['tone'])->toBe('success');
});
