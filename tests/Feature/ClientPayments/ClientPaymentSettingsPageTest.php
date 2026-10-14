<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\ClientPaymentSettingsPage;
use App\Models\Setting;
use App\Models\User;
use App\Support\Billing\ClientPaymentSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
|--------------------------------------------------------------------------
| Làn nt Task 1 — trang "Nhắc thanh toán cho khách": MỘT công tắc, mặc định TẮT
|--------------------------------------------------------------------------
|
| Chủ văn phòng, 2026-10-10: "Nhắc lịch thanh toán thì tôi nghĩ nên làm có công tắc bật tắt, nếu cần
| thì sau này sẽ bật lên, còn không cần thì tắt đi để dễ sử dụng." Trang chỉ admin (`settings.manage`)
| mở được; mọi hành vi đo qua Livewire/HTTP, không gọi thẳng Action.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->withRole(Role::Admin)->create();
});

/** @return array<string, mixed> thông tin chuyển khoản hợp lệ, rõ là giả */
function cpsBankValues(): array
{
    return [
        'bank_bin' => '970436',
        'account_number' => '0071 0001 23456',
        'account_holder' => 'CONG TY LUAT TNHH THU NGHIEM',
        'bank_branch' => 'Chi nhánh Thử nghiệm',
    ];
}

function cpsPage()
{
    return Livewire::test(ClientPaymentSettingsPage::class);
}

// ---------------------------------------------------------------------------------------------
// Mặc định và cổng
// ---------------------------------------------------------------------------------------------

it('is OFF by default with nothing stored, and the reminder lead time defaults to 3 days', function () {
    expect(Setting::query()->count())->toBe(0)
        ->and(ClientPaymentSettings::enabled())->toBeFalse()
        ->and(ClientPaymentSettings::current()->daysBeforeDue())->toBe(3)
        ->and(ClientPaymentSettings::current()->transferAccount())->toBeNull();

    $this->actingAs($this->admin, 'web');

    cpsPage()->assertFormSet(['enabled' => false, 'days_before_due' => 3]);
});

it('treats any stored value other than exactly "1" as OFF', function (?string $value) {
    Setting::query()->create(['key' => ClientPaymentSettings::ENABLED, 'value' => $value]);

    expect(ClientPaymentSettings::enabled())->toBeFalse();
})->with([null, '0', 'true', ' 1', 'yes']);

it('opens for an admin and shows the switch, the lead time and the four bank fields', function () {
    $this->actingAs($this->admin, 'web')
        ->get(ClientPaymentSettingsPage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(__('client_payments.settings.page_title'))
        ->assertSee(__('client_payments.settings.fields.enabled.label'))
        ->assertSee(__('client_payments.settings.fields.days_before_due.label'))
        ->assertSee(__('client_payments.settings.fields.bank_bin.label'))
        ->assertSee(__('client_payments.settings.fields.account_number.label'))
        ->assertSee(__('client_payments.settings.fields.account_holder.label'))
        ->assertSee(__('client_payments.settings.fields.bank_branch.label'));
});

it('answers 404 to every non-admin and hides itself from their navigation', function (Role $role) {
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user, 'web')
        ->get(ClientPaymentSettingsPage::getUrl(panel: 'admin'))
        ->assertNotFound();

    expect(ClientPaymentSettingsPage::shouldRegisterNavigation())->toBeFalse();
})->with([Role::Manager, Role::Lawyer, Role::Assistant, Role::Accountant]);

/** Mutation probe: xoá `boot()` → lần gọi `save` sau khi hạ quyền trả 403 thay vì 404. */
it('answers 404 to a Livewire update from someone who lost the permission, and stores nothing', function () {
    $this->actingAs($this->admin, 'web');

    $page = cpsPage()->fillForm(['enabled' => true, ...cpsBankValues()]);

    $this->admin->syncRoles([Role::Manager->value]);
    $this->actingAs($this->admin->fresh(), 'web');

    $page->call('save')->assertNotFound();

    expect(Setting::query()->count())->toBe(0);
});

/** Mutation probe: bỏ `abort_unless` đầu `save()` → đỏ. */
it('re-asks the gate inside save() even outside the Livewire lifecycle', function () {
    $this->actingAs(User::factory()->withRole(Role::Manager)->create(), 'web');

    expect(fn () => (new ClientPaymentSettingsPage)->save())->toThrow(NotFoundHttpException::class);
});

// ---------------------------------------------------------------------------------------------
// Form
// ---------------------------------------------------------------------------------------------

it('limits each text field to exactly the length the action accepts', function () {
    $this->actingAs($this->admin, 'web');

    $page = cpsPage();

    foreach (ClientPaymentSettings::TEXT_LIMITS as $field => $limit) {
        $page->assertFormFieldExists($field, fn (Field $component): bool => $component->getMaxLength() === $limit);
    }
});

it('stores the bank details normalised, keeps the switch OFF, and shows a preview QR to scan', function () {
    $this->actingAs($this->admin, 'web');

    cpsPage()
        ->assertDontSee('data:image/svg+xml;base64,', escape: false)
        ->fillForm([...cpsBankValues(), 'days_before_due' => 5])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertFormSet(['account_number' => '0071000123456', 'days_before_due' => 5, 'enabled' => false])
        ->assertSee('data:image/svg+xml;base64,', escape: false)
        ->assertSee(__('client_payments.settings.preview.heading'));

    $settings = ClientPaymentSettings::current();

    expect(ClientPaymentSettings::enabled())->toBeFalse()
        ->and($settings->daysBeforeDue())->toBe(5)
        ->and($settings->transferAccount())->toBe([
            'bank_bin' => '970436',
            'bank_name' => 'Vietcombank — Ngân hàng TMCP Ngoại thương Việt Nam',
            'account_number' => '0071000123456',
            'account_holder' => 'CONG TY LUAT TNHH THU NGHIEM',
            'bank_branch' => 'Chi nhánh Thử nghiệm',
        ]);
});

/** Mutation probe: bỏ luật "bật thì phải đủ thông tin chuyển khoản" → đỏ (công tắc được lưu bật). */
it('refuses to turn the switch ON while the bank details are incomplete', function (string $missing) {
    $this->actingAs($this->admin, 'web');

    $values = cpsBankValues();
    $values[$missing] = null;

    cpsPage()
        ->fillForm(['enabled' => true, ...$values])
        ->call('save')
        ->assertHasFormErrors([$missing]);

    expect(ClientPaymentSettings::enabled())->toBeFalse()
        ->and(Setting::query()->whereNotNull('value')->count())->toBe(0);
})->with(['bank_bin', 'account_number', 'account_holder']);

it('rejects an unknown bank, a malformed account number and an out-of-range lead time', function (string $field, mixed $value) {
    $this->actingAs($this->admin, 'web');

    cpsPage()
        ->fillForm([...cpsBankValues(), $field => $value])
        ->call('save')
        ->assertHasFormErrors([$field]);

    expect(Setting::query()->whereNotNull('value')->count())->toBe(0);
})->with([
    'bank not in the NAPAS list' => ['bank_bin', '999999'],
    'account number with letters and symbols' => ['account_number', '0071-ABC/9'],
    'account number too short' => ['account_number', '12345'],
    'lead time zero' => ['days_before_due', 0],
    'lead time above 30' => ['days_before_due', 31],
]);

// ---------------------------------------------------------------------------------------------
// Bật / tắt: có hiệu lực ngay, ghi nhật ký ai và lúc nào
// ---------------------------------------------------------------------------------------------

it('turns the switch ON then OFF, each change one audit row naming who did it', function () {
    $this->actingAs($this->admin, 'web');

    cpsPage()->fillForm(['enabled' => true, ...cpsBankValues()])->call('save')->assertHasNoFormErrors();

    expect(ClientPaymentSettings::enabled())->toBeTrue()
        ->and(Setting::query()->where('key', ClientPaymentSettings::ENABLED)->value('value'))->toBe(ClientPaymentSettings::ON);

    $this->travel(5)->minutes();

    cpsPage()->assertFormSet(['enabled' => true])->fillForm(['enabled' => false])->call('save')->assertHasNoFormErrors();

    expect(ClientPaymentSettings::enabled())->toBeFalse();

    $rows = Activity::query()->where('event', 'client_payment_settings_updated')->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->causer_id)->toBe($this->admin->id)
        ->and($rows[0]->properties['enabled'])->toBeTrue()
        ->and($rows[0]->properties['changed_fields'])->toContain('enabled', 'bank_bin', 'account_number', 'account_holder')
        ->and($rows[1]->causer_id)->toBe($this->admin->id)
        ->and($rows[1]->properties['enabled'])->toBeFalse()
        ->and($rows[1]->properties['changed_fields'])->toBe(['enabled'])
        ->and($rows[1]->created_at->gt($rows[0]->created_at))->toBeTrue()
        ->and(__('activity.events.client_payment_settings_updated'))->not->toBe('activity.events.client_payment_settings_updated');

    // Không chép số tài khoản vào nhật ký: chỉ tên trường.
    expect(json_encode($rows->pluck('properties')))->not->toContain('0071000123456');
});

it('writes no audit row when saved without any change', function () {
    $this->actingAs($this->admin, 'web');

    cpsPage()->call('save')->assertHasNoFormErrors()->assertNotified(__('client_payments.settings.notifications.unchanged'));

    expect(Activity::query()->where('event', 'client_payment_settings_updated')->count())->toBe(0);
});
