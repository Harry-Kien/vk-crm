<?php

use App\Actions\Matter\SyncMatterArchive;
use App\Actions\Schedule\CheckDeadlines;
use App\Actions\Settings\UpdateBusinessSettings;
use App\Enums\DeadlineSeverity;
use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Admin\Pages\ContractEntry;
use App\Filament\Admin\Pages\DocumentLibrary;
use App\Filament\Admin\Pages\Settings\BusinessSettingsPage;
use App\Filament\Admin\Pages\Settings\SettingsHub;
use App\Http\Middleware\EnforceHttps;
use App\Models\Matter;
use App\Models\Setting;
use App\Models\User;
use App\Support\BusinessHours;
use App\Support\Settings\BusinessSettings;
use App\Support\Settings\LoginSecurityStatus;
use App\Support\Settings\SettingsCatalog;
use App\Support\Tasks\AcceptanceClock;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BusinessSettingsScreen;

/**
 * Gộp integ-4 — trung tâm "Cài đặt" (làn st) sau khi gộp với làn tk, kt, tl (mục C), và các chỗ nhỏ của
 * rà soát làn st (mục E: M1–M5, thẻ/docblock, một hằng số HSTS).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->withRole(Role::Admin)->create();
});

/** @return array<string, string> khoá thẻ => trạng thái, của mọi thẻ trong nhóm `$group` */
function integ4HubCards(string $group): array
{
    return collect(SettingsCatalog::groups()[$group] ?? [])
        ->mapWithKeys(fn ($card): array => [$card->key => (string) $card->status])
        ->all();
}

// ---------------------------------------------------------------------------------------------
// E · M2 — dung lượng tệp CHƯA lưu cũng không vượt trần thật của máy chủ
// ---------------------------------------------------------------------------------------------

/**
 * `UPLOAD_MAX_MB` (cấu hình) lớn hơn trần thật của máy chủ (luật `max:` của Livewire, giới hạn PHP): giá trị
 * đang dùng, ô của form và thẻ trên trang tổng đều là trần đó — form không bao giờ mở với số trên `maxValue`.
 * Mutation probe: bỏ bước chặn trần ở nhánh "chưa lưu" của `BusinessSettings::number()` → đỏ.
 */
it('caps an unstored upload limit at the real ceiling of the server, on the form and on the hub card', function () {
    config([
        'vkcrm.upload_max_mb' => 40,
        'livewire.temporary_file_upload.rules' => ['required', 'file', 'max:'.(15 * 1024)],
    ]);

    $ceiling = BusinessSettings::uploadCeilingMegabytes();

    expect($ceiling)->toBeLessThan(40)
        ->and(BusinessSettings::uploadMaxMegabytes())->toBe($ceiling)
        ->and(BusinessSettings::current()->number('upload_max_mb'))->toBe($ceiling);

    $this->actingAs($this->admin, 'web');

    Livewire::test(BusinessSettingsPage::class)
        ->assertSchemaStateSet(['upload_max_mb' => $ceiling])
        ->assertFormFieldExists('upload_max_mb', fn (TextInput $input): bool => $input->getMaxValue() === $ceiling)
        ->assertSee(__('settings.business.source.default', ['value' => $ceiling]));

    expect(integ4HubCards('portal')['portal_rules'])
        ->toBe(__('settings.hub.cards.portal_rules.status', ['requests' => __('settings.state.on'), 'mb' => $ceiling]));

    // Mở rồi lưu không đổi gì: không dòng nào (giá trị đang dùng đã là trần).
    Livewire::test(BusinessSettingsPage::class)->call('save');

    expect(Setting::query()->where('key', BusinessSettings::UPLOAD_MAX_MB)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------------------------
// E · M5 — "Đưa tất cả về mặc định" luôn ghi nhật ký khi xoá dòng đã lưu
// ---------------------------------------------------------------------------------------------

/**
 * Hộp xác nhận hứa "Lần đổi này được ghi vào nhật ký": một dòng đã lưu trùng mặc định vẫn bị xoá, nên vẫn
 * có một dòng nhật ký (không có `changes` nào, `cleared` nêu trường đã xoá). Mutation probe: chỉ ghi khi
 * `changes` khác rỗng (như trước) → đỏ.
 */
it('audits a reset that only clears stored rows equal to the default', function () {
    config(['vkcrm.client_access_days' => 90]);
    Setting::query()->create(['key' => BusinessSettings::CLIENT_ACCESS_DAYS, 'value' => '90']);

    expect(app(UpdateBusinessSettings::class)->reset($this->admin))->toBe([])
        ->and(Setting::query()->where('key', BusinessSettings::CLIENT_ACCESS_DAYS)->value('value'))->toBeNull();

    $row = Activity::query()->where('event', 'business_settings_updated')->sole();

    expect($row->causer_id)->toBe($this->admin->id)
        ->and($row->properties['reset'])->toBeTrue()
        ->and($row->properties['changes'])->toBe([])
        ->and($row->properties['cleared'])->toBe(['client_access_days']);
});

it('writes no audit row for a reset when nothing was stored', function () {
    app(UpdateBusinessSettings::class)->reset($this->admin);

    expect(Activity::query()->where('event', 'business_settings_updated')->count())->toBe(0);
});

it('lists the cleared fields next to the changes on an ordinary reset', function () {
    config(['vkcrm.client_access_days' => 90]);
    BusinessSettingsScreen::save(['client_access_days' => 200, 'client_requests' => false], $this->admin);

    app(UpdateBusinessSettings::class)->reset($this->admin);

    $row = Activity::query()->where('event', 'business_settings_updated')->orderByDesc('id')->first();

    expect($row->properties['cleared'])->toBe(['client_access_days', 'client_requests'])
        ->and(array_keys($row->properties['changes']))->toBe(['client_access_days', 'client_requests']);
});

// ---------------------------------------------------------------------------------------------
// E · M1 — câu gợi ý "số ngày khách còn xem" nói đúng SyncMatterArchive::syncClosed()
// ---------------------------------------------------------------------------------------------

/**
 * Lần đồng bộ lại một vụ ĐÃ kết thúc (chuyển giữa hai giai đoạn kết thúc) tính lại hạn theo số ngày mới:
 * tăng số ngày kéo dài hạn của vụ đó, giảm thì không rút ngắn hạn đã có. Câu gợi ý trên form nói đúng điều
 * đó, không còn "vụ đã kết thúc giữ hạn cũ".
 */
it('extends an already-closed matter on its next sync when the days are raised, never shortens it, and says so', function () {
    config(['vkcrm.client_access_days' => 90]);

    $closedAt = now()->subDays(10)->startOfDay();
    $matter = Matter::factory()->create(['closed_at' => $closedAt->toDateString()]);
    $sync = fn (): string => app(SyncMatterArchive::class)->handle($matter->id, null)->client_access_until->toDateString();

    expect($sync())->toBe($closedAt->copy()->addDays(90)->toDateString());

    BusinessSettingsScreen::save(['client_access_days' => 200], $this->admin);
    expect($sync())->toBe($closedAt->copy()->addDays(200)->toDateString());

    BusinessSettingsScreen::save(['client_access_days' => 30], $this->admin);
    expect($sync())->toBe($closedAt->copy()->addDays(200)->toDateString());

    $hint = __('settings.business.fields.client_access_days.hint', ['min' => 1, 'max' => 730]);

    expect($hint)->not->toContain('giữ hạn cũ')
        ->toContain('kéo dài')
        ->toContain('không rút ngắn');
});

// ---------------------------------------------------------------------------------------------
// E · M3 — câu gợi ý "thời hạn phản hồi" nói đúng ResolveStaffRecipients::forIntake()
// ---------------------------------------------------------------------------------------------

it('names in the intake hint exactly who is reminded: the assignee, else everyone who sees every contact, else the admins', function () {
    $hint = __('settings.business.fields.intake_response_hours.hint', ['min' => 1, 'max' => 40]);

    $viewers = array_values(array_filter(
        Role::cases(),
        fn (Role $role): bool => in_array(Permission::IntakeViewAny, $role->permissions(), true),
    ));

    expect($viewers)->toBe([Role::Admin, Role::Manager])
        ->and($hint)->toContain('người được giao')
        ->toContain(mb_strtolower(Role::Manager->label()))
        ->toContain(mb_strtolower(Role::Admin->label()))
        ->not->toContain('(hoặc trưởng phòng)');
});

// ---------------------------------------------------------------------------------------------
// E · M4 — bậc nhắc mốc thời hạn hiện đọc-chỉ; khoá ngôn ngữ không dùng đã bỏ
// ---------------------------------------------------------------------------------------------

it('shows the deadline reminder tiers read-only, read from CheckDeadlines', function () {
    $this->actingAs($this->admin, 'web');

    $common = array_values(array_filter(CheckDeadlines::TIERS, fn (int $tier): bool => $tier !== CheckDeadlines::CRITICAL_ONLY_TIER));

    expect(CheckDeadlines::TIERS)->toBe([14, 7, 3, 1])
        ->and(CheckDeadlines::CRITICAL_ONLY_TIER)->toBe(14);

    Livewire::test(BusinessSettingsPage::class)->assertSee(__('settings.business.fixed.deadlines', [
        'tiers' => implode(', ', $common),
        'critical' => DeadlineSeverity::Critical->label(),
        'extra' => CheckDeadlines::CRITICAL_ONLY_TIER,
    ]));
});

it('carries no unused hub strings', function () {
    expect(__('settings.hub.server_only'))->toBe('settings.hub.server_only')
        ->and(__('settings.hub.open'))->toBe('settings.hub.open');
});

// ---------------------------------------------------------------------------------------------
// E — HSTS: trang "Bảo mật đăng nhập" và middleware đọc MỘT hằng số
// ---------------------------------------------------------------------------------------------

it('reads the default HSTS age from the one constant the middleware sends', function () {
    expect((new ReflectionClass(LoginSecurityStatus::class))->hasConstant('STRICT_HSTS_SECONDS'))->toBeFalse()
        ->and(EnforceHttps::STRICT_HSTS_SECONDS)->toBe(31536000);

    config(['vkcrm.security.force_https' => false, 'vkcrm.security.hsts_max_age' => null]);
    app()->detectEnvironment(fn () => 'production');

    $header = $this->withServerVariables(['HTTPS' => 'on'])->get('https://vk-crm.test/portal/login')
        ->headers->get('Strict-Transport-Security');

    expect($header)->toBe('max-age='.LoginSecurityStatus::hstsSeconds())
        ->and(LoginSecurityStatus::hstsSeconds())->toBe(EnforceHttps::STRICT_HSTS_SECONDS);
});

// ---------------------------------------------------------------------------------------------
// C — thẻ của làn tk (giờ làm việc, nhắc việc), làn kt ("Hợp đồng") và làn tl ("Tài liệu")
// ---------------------------------------------------------------------------------------------

/**
 * Giờ làm việc chỉ đặt trong `config/vkcrm.php` (không `.env`, không bảng `settings`) và lịch `tasks.remind`
 * không có công tắc: thẻ chỉ ĐỌC trạng thái thật và dẫn tới mục đọc-chỉ của "Cài đặt nghiệp vụ" — không ô
 * nào để đổi. Mutation probe: thẻ đọc giờ cố định "08:00" thay vì `BusinessHours::fromConfig()` → đỏ khi
 * cấu hình đổi.
 */
it('shows the working hours and task reminders read-only, from BusinessHours and AcceptanceClock', function () {
    config(['vkcrm.business_hours' => ['days' => [1, 2, 3, 4, 5, 6], 'opens_at' => '07:30', 'closes_at' => '17:00']]);

    $this->actingAs($this->admin, 'web');

    $hours = BusinessHours::fromConfig();
    $days = __('settings.days.'.min($hours->days)).'–'.__('settings.days.'.max($hours->days));

    expect($days)->toBe('Thứ Hai–Thứ Bảy')
        ->and(integ4HubCards('matters')['working_hours'])
        ->toBe(__('settings.hub.cards.working_hours.status', ['days' => $days, 'opens' => '07:30', 'closes' => '17:00']));

    $card = collect(SettingsCatalog::groups()['matters'])->firstWhere('key', 'working_hours');

    expect($card->url)->toBe(BusinessSettingsPage::getUrl().'#fixed');

    Livewire::test(BusinessSettingsPage::class)
        ->assertSeeHtml('id="fixed"')
        ->assertSee(__('settings.business.fixed.working_hours', [
            'days' => $days, 'opens' => '07:30', 'closes' => '17:00', 'timezone' => config('app.timezone'),
        ]))
        ->assertSee(__('settings.business.fixed.task_reminders', [
            'remind' => AcceptanceClock::REMIND_AFTER_HOURS,
            'escalate' => AcceptanceClock::ESCALATE_AFTER_HOURS,
        ]))
        ->assertFormFieldDoesNotExist('business_hours')
        ->assertFormFieldDoesNotExist('opens_at');
});

it('lists working days that are not a run one by one', function () {
    config(['vkcrm.business_hours' => ['days' => [1, 3, 5], 'opens_at' => '08:00', 'closes_at' => '17:30']]);

    $this->actingAs($this->admin, 'web');

    expect(integ4HubCards('matters')['working_hours'])->toBe(__('settings.hub.cards.working_hours.status', [
        'days' => 'Thứ Hai, Thứ Tư, Thứ Sáu', 'opens' => '08:00', 'closes' => '17:30',
    ]));
});

/**
 * Thẻ "Hợp đồng" (làn kt) đếm người ĐANG hoạt động có quyền nhập hợp đồng (`contract.enter`) và dẫn tới trang
 * "Hợp đồng"; thẻ "Tài liệu" (làn tl) đếm người đang hoạt động mở được trang "Tài liệu" — qua chính
 * `DocumentLibrary::canBeOpenedBy()`, không định nghĩa thứ hai.
 */
it('adds a card for the accountant contracts page and one for the documents page, with real counts', function () {
    User::factory()->count(2)->withRole(Role::Accountant)->create();
    User::factory()->withRole(Role::Accountant)->create(['is_active' => false]);
    User::factory()->withRole(Role::Lawyer)->create();
    User::factory()->withRole(Role::Sales)->create();

    $this->actingAs($this->admin, 'web');

    $enterers = User::query()->where('is_active', true)->get()->filter->can(Permission::ContractEnter->value)->count();
    $readers = User::query()->where('is_active', true)->get()->filter(fn (User $user): bool => DocumentLibrary::canBeOpenedBy($user))->count();

    expect($enterers)->toBe(3)
        ->and($readers)->toBe(2)
        ->and(integ4HubCards('finance')['contract_entry'])->toBe(__('settings.hub.cards.contract_entry.status', ['count' => 3]))
        ->and(integ4HubCards('documents')['document_library'])->toBe(__('settings.hub.cards.document_library.status', ['count' => 2]));

    Livewire::test(SettingsHub::class)
        ->assertSeeHtml('data-settings-card="contract_entry"')
        ->assertSeeHtml('data-settings-card="document_library"')
        ->assertSeeHtml('data-settings-card="working_hours"')
        ->assertSee(ContractEntry::getUrl(), escape: false)
        ->assertSee(DocumentLibrary::getUrl(), escape: false);
});

it('asks DocumentLibrary::canBeOpenedBy for the page gate, the same as the card', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    expect(DocumentLibrary::canBeOpenedBy($lawyer))->toBeTrue()
        ->and(DocumentLibrary::canBeOpenedBy($accountant))->toBeFalse();

    $this->actingAs($accountant, 'web');
    expect(DocumentLibrary::canAccess())->toBeFalse();

    $this->actingAs($lawyer, 'web');
    expect(DocumentLibrary::canAccess())->toBeTrue();
});
