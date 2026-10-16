<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\Settings\BusinessSettingsPage;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings\BusinessSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Làn st Task 1, mục 2 — trang "Cài đặt nghiệp vụ" (chỉ quản trị viên): cổng, mặc định = hôm nay,
 * giới hạn từng ô (lỗi trên đúng ô), lưu + nhật ký giá trị cũ → mới, đưa về mặc định. Hành vi ở nơi
 * đọc (cổng khách, tác vụ lịch, tệp tải lên) đo ở `BusinessSettingsWiringTest`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->withRole(Role::Admin)->create();

    // Trạng thái hôm nay của `.env` trong test — đặt tường minh để "mặc định" là con số đọc được.
    config([
        'vkcrm.client_access_days' => 90,
        'vkcrm.intake_response_hours' => 4,
        'vkcrm.upload_max_mb' => 20,
        'livewire.temporary_file_upload.rules' => ['required', 'file', 'max:'.(20 * 1024)],
    ]);
});

function businessPage()
{
    return Livewire::test(BusinessSettingsPage::class);
}

// ---------------------------------------------------------------------------------------------
// Cổng
// ---------------------------------------------------------------------------------------------

it('opens for an admin with every setting of the page', function () {
    $this->actingAs($this->admin, 'web')
        ->get(BusinessSettingsPage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(__('settings.business.title'))
        ->assertSee(__('settings.business.fields.client_access_days.label'))
        ->assertSee(__('settings.business.fields.client_requests.label'))
        ->assertSee(__('settings.business.fields.upload_max_mb.label'));
});

it('answers 404 to every role but admin, and is never in their menu', function (Role $role) {
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user, 'web')
        ->get(BusinessSettingsPage::getUrl(panel: 'admin'))
        ->assertNotFound();

    expect(BusinessSettingsPage::canAccess())->toBeFalse();
})->with([Role::Manager, Role::Lawyer, Role::Assistant, Role::Accountant, Role::Sales]);

it('is not a menu item of its own even for an admin — the Settings hub is the way in', function () {
    $this->actingAs($this->admin, 'web');

    expect(BusinessSettingsPage::shouldRegisterNavigation())->toBeFalse();
});

/** Mutation probe: bỏ `boot()` của trang → lần gọi `save` sau khi mất quyền nhận 403, không 404. */
it('answers 404 to a save from someone who lost the admin role while the page was open', function () {
    $this->actingAs($this->admin, 'web');

    $page = businessPage()->fillForm(['client_access_days' => 120]);

    $this->admin->syncRoles([Role::Manager->value]);
    $this->actingAs($this->admin->fresh(), 'web');

    $page->call('save')->assertNotFound();

    expect(Setting::query()->where('key', 'like', 'business.%')->count())->toBe(0);
});

/** Mutation probe: bỏ `abort_unless` đầu `save()` → không còn 404 khi vòng đời Livewire bị bỏ qua. */
it('save() asks the gate again by itself', function () {
    $this->actingAs(User::factory()->withRole(Role::Manager)->create(), 'web');

    expect(fn () => (new BusinessSettingsPage)->save())->toThrow(NotFoundHttpException::class);
});

// ---------------------------------------------------------------------------------------------
// Mặc định = hôm nay; lưu không đổi gì thì không ghi gì
// ---------------------------------------------------------------------------------------------

it('opens with today\'s behaviour when nothing has been saved', function () {
    config(['vkcrm.client_access_days' => 45, 'vkcrm.intake_response_hours' => 6, 'vkcrm.upload_max_mb' => 12]);

    $this->actingAs($this->admin, 'web');

    businessPage()->assertSchemaStateSet([
        'client_access_days' => 45,
        'stale_matter_mail' => true,
        'intake_response_hours' => 6,
        'client_requests' => true,
        'upload_max_mb' => 12,
        'missing_documents_mail' => true,
    ]);
});

it('writes no setting and no audit row when saved unchanged, so .env keeps steering', function () {
    $this->actingAs($this->admin, 'web');

    businessPage()->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified(__('settings.business.notifications.unchanged'));

    expect(Setting::query()->where('key', 'like', 'business.%')->whereNotNull('value')->count())->toBe(0)
        ->and(Activity::query()->where('event', 'business_settings_updated')->count())->toBe(0);

    // Còn theo .env: đổi .env sau đó thì giá trị đang dùng đổi theo.
    config(['vkcrm.client_access_days' => 30]);

    expect(BusinessSettings::clientAccessDays())->toBe(30);
});

// ---------------------------------------------------------------------------------------------
// Lưu + nhật ký
// ---------------------------------------------------------------------------------------------

it('stores what changed, wins over .env, and audits old and new values with who did it', function () {
    $this->actingAs($this->admin, 'web');

    businessPage()
        ->fillForm([
            'client_access_days' => 180,
            'stale_matter_mail' => false,
            'intake_response_hours' => 8,
            'client_requests' => false,
            'upload_max_mb' => 10,
            'missing_documents_mail' => false,
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified(__('settings.business.notifications.saved'));

    config(['vkcrm.client_access_days' => 30, 'vkcrm.intake_response_hours' => 2]);

    expect(BusinessSettings::clientAccessDays())->toBe(180)
        ->and(BusinessSettings::intakeResponseHours())->toBe(8)
        ->and(BusinessSettings::uploadMaxMegabytes())->toBe(10)
        ->and(BusinessSettings::staleMatterMailEnabled())->toBeFalse()
        ->and(BusinessSettings::clientRequestsEnabled())->toBeFalse()
        ->and(BusinessSettings::missingDocumentsMailEnabled())->toBeFalse();

    $row = Activity::query()->where('event', 'business_settings_updated')->sole();

    expect($row->causer_id)->toBe($this->admin->id)
        ->and($row->properties['changes'])->toBe([
            'client_access_days' => ['old' => 90, 'new' => 180],
            'stale_matter_mail' => ['old' => true, 'new' => false],
            'intake_response_hours' => ['old' => 4, 'new' => 8],
            'client_requests' => ['old' => true, 'new' => false],
            'upload_max_mb' => ['old' => 20, 'new' => 10],
            'missing_documents_mail' => ['old' => true, 'new' => false],
        ])
        ->and(__('activity.events.business_settings_updated'))->not->toBe('activity.events.business_settings_updated');
});

it('audits only the field that changed on a second save', function () {
    $this->actingAs($this->admin, 'web');

    businessPage()->fillForm(['client_access_days' => 120])->call('save')->assertHasNoFormErrors();
    businessPage()->fillForm(['client_requests' => false])->call('save')->assertHasNoFormErrors();

    $rows = Activity::query()->where('event', 'business_settings_updated')->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->properties['changes'])->toBe(['client_access_days' => ['old' => 90, 'new' => 120]])
        ->and($rows[1]->properties['changes'])->toBe(['client_requests' => ['old' => true, 'new' => false]]);
});

it('shows which values follow the server and which were set on screen', function () {
    $this->actingAs($this->admin, 'web');

    businessPage()->assertSee(__('settings.business.source.server'));

    businessPage()->fillForm(['client_access_days' => 120])->call('save');

    businessPage()->assertSee(__('settings.business.source.screen'));
});

// ---------------------------------------------------------------------------------------------
// Giới hạn — lỗi trên đúng ô, cả lô không ghi gì
// ---------------------------------------------------------------------------------------------

/** Mutation probe: bỏ luật `between` của Action → các hàng này không còn lỗi trên ô. */
it('refuses a number outside its limits on the right field and stores nothing', function (string $field, int $value) {
    $this->actingAs($this->admin, 'web');

    businessPage()
        ->fillForm(['client_access_days' => 100, $field => $value])
        ->call('save')
        ->assertHasFormErrors([$field]);

    expect(Setting::query()->where('key', 'like', 'business.%')->whereNotNull('value')->count())->toBe(0);
})->with([
    'số ngày 0' => ['client_access_days', 0],
    'số ngày quá 730' => ['client_access_days', 731],
    'số giờ 0' => ['intake_response_hours', 0],
    'số giờ quá 40' => ['intake_response_hours', 41],
    'tệp 0 MB' => ['upload_max_mb', 0],
    'tệp quá trần máy chủ' => ['upload_max_mb', 21],
]);

it('caps the upload field at the real ceiling of the server and says so', function () {
    config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:'.(15 * 1024)]]);

    $this->actingAs($this->admin, 'web');

    expect(BusinessSettings::uploadCeilingMegabytes())->toBe(min(15, BusinessSettings::phpLimitMegabytes() ?? 15));

    businessPage()
        ->assertFormFieldExists('upload_max_mb', fn (TextInput $input): bool => $input->getMaxValue() === BusinessSettings::uploadCeilingMegabytes())
        ->assertFormFieldExists('client_access_days', fn (TextInput $input): bool => $input->getMinValue() === 1 && $input->getMaxValue() === 730)
        ->assertFormFieldExists('intake_response_hours', fn (TextInput $input): bool => $input->getMinValue() === 1 && $input->getMaxValue() === 40)
        ->assertSee(__('settings.business.fields.upload_max_mb.ceiling', ['max' => BusinessSettings::uploadCeilingMegabytes()]));
});

// ---------------------------------------------------------------------------------------------
// Đưa về mặc định
// ---------------------------------------------------------------------------------------------

it('puts every setting back to the server default and audits it', function () {
    $this->actingAs($this->admin, 'web');

    businessPage()->fillForm(['client_access_days' => 200, 'client_requests' => false])->call('save');

    businessPage()
        ->callAction('resetDefaults')
        ->assertNotified(__('settings.business.notifications.reset'))
        ->assertSchemaStateSet(['client_access_days' => 90, 'client_requests' => true]);

    config(['vkcrm.client_access_days' => 33]);

    expect(BusinessSettings::clientAccessDays())->toBe(33)
        ->and(BusinessSettings::clientRequestsEnabled())->toBeTrue();

    $row = Activity::query()->where('event', 'business_settings_updated')->orderByDesc('id')->first();

    expect($row->properties['reset'])->toBeTrue()
        ->and($row->properties['changes'])->toBe([
            'client_access_days' => ['old' => 200, 'new' => 90],
            'client_requests' => ['old' => false, 'new' => true],
        ]);
});

it('lists the legal and server-bound values as read-only, with where they are set', function () {
    $this->actingAs($this->admin, 'web');

    businessPage()
        ->assertSee(__('settings.business.fixed.heading'))
        ->assertSee(__('settings.business.fixed.retention_years', ['years' => (int) config('vkcrm.retention_years')]))
        ->assertSee(__('settings.business.fixed.where'))
        ->assertFormFieldDoesNotExist('retention_years')
        ->assertFormFieldDoesNotExist('prospect_retention_months');
});
