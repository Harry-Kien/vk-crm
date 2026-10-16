<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Admin\Pages\AiConnections;
use App\Filament\Admin\Pages\ClientPaymentSettingsPage;
use App\Filament\Admin\Pages\DocumentStorePage;
use App\Filament\Admin\Pages\OfficeProfilePage;
use App\Filament\Admin\Pages\Settings\BusinessSettingsPage;
use App\Filament\Admin\Pages\Settings\LoginSecurityPage;
use App\Filament\Admin\Pages\Settings\MailSettingsPage;
use App\Filament\Admin\Pages\Settings\RolePermissionsPage;
use App\Filament\Admin\Pages\Settings\SettingsHub;
use App\Filament\Admin\Resources\MatterTypes\MatterTypeResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\Setting;
use App\Models\User;
use App\Support\Billing\ClientPaymentSettings;
use App\Support\Mcp\McpSwitches;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\BusinessSettingsScreen;

/**
 * Làn st Task 1, mục 1 — trang tổng "Cài đặt": chỉ quản trị viên; các nhóm thẻ; mỗi thẻ trạng thái
 * THẬT và đường dẫn tới trang cài đặt của nó; ô tìm lọc thẻ (không phân biệt dấu).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->withRole(Role::Admin)->create();
});

function settingsHub()
{
    return Livewire::test(SettingsHub::class);
}

it('opens for an admin with every group and card, each linking to its page', function () {
    $this->actingAs($this->admin, 'web');

    $response = $this->get(SettingsHub::getUrl(panel: 'admin'))->assertOk()->assertSee(__('settings.hub.title'));

    foreach ((array) __('settings.hub.groups') as $group) {
        $response->assertSee($group);
    }

    foreach ([
        OfficeProfilePage::getUrl(),
        MatterTypeResource::getUrl(),
        UserResource::getUrl(),
        RolePermissionsPage::getUrl(),
        LoginSecurityPage::getUrl(),
        BusinessSettingsPage::getUrl(),
        MailSettingsPage::getUrl(),
        ClientPaymentSettingsPage::getUrl(),
        DocumentStorePage::getUrl(),
        AiConnections::getUrl(),
    ] as $url) {
        $response->assertSee($url, escape: false);
    }
});

it('is in the admin menu, and answers 404 to every other role without showing in their menu', function (Role $role) {
    $this->actingAs($this->admin, 'web');
    expect(SettingsHub::shouldRegisterNavigation())->toBeTrue();

    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user, 'web')->get(SettingsHub::getUrl(panel: 'admin'))->assertNotFound();

    expect(SettingsHub::shouldRegisterNavigation())->toBeFalse();
})->with([Role::Manager, Role::Lawyer, Role::Assistant, Role::Accountant, Role::Sales]);

/** Mutation probe: bỏ `boot()` khỏi trang → request cập nhật của người đã mất quyền nhận 403. */
it('answers 404 to a Livewire update from someone who lost the admin role', function () {
    $this->actingAs($this->admin, 'web');

    $page = settingsHub();

    $this->admin->syncRoles([Role::Lawyer->value]);
    $this->actingAs($this->admin->fresh(), 'web');

    $page->set('search', 'thư')->assertNotFound();
});

it('shows the real state of each switch, not a decoration', function () {
    $this->actingAs($this->admin, 'web');

    settingsHub()
        ->assertSee(__('settings.hub.cards.client_payments.status', ['state' => __('settings.state.off')]))
        ->assertSee(__('settings.hub.cards.ai.status', ['state' => __('settings.state.off')]))
        ->assertSee(__('settings.hub.cards.document_store.status_local'))
        ->assertSee(__('settings.hub.cards.portal_rules.status', ['requests' => __('settings.state.on'), 'mb' => (int) config('vkcrm.upload_max_mb')]));

    Setting::query()->create(['key' => ClientPaymentSettings::ENABLED, 'value' => ClientPaymentSettings::ON]);
    Setting::query()->create(['key' => McpSwitches::ENABLED, 'value' => '1']);
    BusinessSettingsScreen::save(['client_requests' => false, 'stale_matter_mail' => false], $this->admin);

    settingsHub()
        ->assertSee(__('settings.hub.cards.client_payments.status', ['state' => __('settings.state.on')]))
        ->assertSee(__('settings.hub.cards.ai.status', ['state' => McpSwitches::enabled() ? __('settings.state.on') : __('settings.state.off')]))
        ->assertSee(__('settings.hub.cards.portal_rules.status', ['requests' => __('settings.state.off'), 'mb' => (int) config('vkcrm.upload_max_mb')]))
        ->assertSee(__('settings.hub.cards.matter_rules.status', ['days' => (int) config('vkcrm.client_access_days'), 'mail' => __('settings.state.off')]));
});

it('counts the staff who have and have not set up two-factor sign-in', function () {
    User::factory()->withRole(Role::Lawyer)->create();
    User::factory()->withoutTwoFactor()->withRole(Role::Assistant)->create();

    $this->actingAs($this->admin, 'web');

    $active = User::query()->where('is_active', true)->count();
    $done = User::query()->where('is_active', true)->get()->filter->hasAppAuthenticationSecret()->count();

    expect($done)->toBe($active - 1);

    settingsHub()->assertSee(__('settings.hub.cards.security.status', ['done' => $done, 'total' => $active]));
});

it('filters the cards by name or description, ignoring Vietnamese accents', function () {
    $this->actingAs($this->admin, 'web');

    settingsHub()
        ->set('search', 'thu dien tu')
        ->assertSee(__('settings.hub.cards.mail.title'))
        ->assertDontSee(__('settings.hub.cards.matter_types.title'))
        ->set('search', 'Google Drive')
        ->assertSee(__('settings.hub.cards.document_store.title'))
        ->assertDontSee(__('settings.hub.cards.roles.title'))
        ->set('search', 'không có cài đặt này')
        ->assertSee(__('settings.hub.search.empty', ['term' => 'không có cài đặt này']))
        ->set('search', '')
        ->assertSee(__('settings.hub.cards.roles.title'))
        ->assertSee(__('settings.hub.cards.mail.title'));
});

/**
 * "Nhật ký hệ thống" đi theo `auditLog.view`, không theo `settings.manage`: người có `settings.manage`
 * mà không có `auditLog.view` (cấp quyền lẻ) không thấy thẻ dẫn tới một trang 404. Mutation probe: bỏ
 * bộ lọc `$card->url !== null` của `SettingsCatalog::groups()` → thẻ hiện (không có liên kết).
 */
it('only lists cards whose page the viewer can open', function () {
    $this->actingAs($this->admin, 'web');
    settingsHub()->assertSee(__('settings.hub.cards.activity_log.title'));

    $settingsOnly = User::factory()->create();
    $settingsOnly->givePermissionTo(Permission::SettingsManage->value);

    expect($settingsOnly->can(Permission::AuditLogView->value))->toBeFalse();

    $this->actingAs($settingsOnly, 'web');

    settingsHub()
        ->assertSee(__('settings.hub.cards.mail.title'))
        ->assertDontSee(__('settings.hub.cards.activity_log.title'));
});
