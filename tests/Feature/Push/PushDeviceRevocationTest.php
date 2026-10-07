<?php

use App\Actions\Push\ForgetPushDevice;
use App\Enums\Role;
use App\Filament\Admin\Resources\ClientUsers\ClientUserResource;
use App\Filament\Admin\Resources\ClientUsers\Pages\EditClientUser;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\StageLogsRelationManager;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Portal\Pages\Auth\ChangePassword;
use App\Jobs\SendPortalActivationMail;
use App\Mail\Client\StageUpdate;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakePushServer;
use Tests\Support\WebPushTestKeys;

/*
|--------------------------------------------------------------------------
| Việc sau gộp M12 (làn fu4) — ba lối văn phòng gỡ máy nhận thông báo đẩy của MỘT người
|--------------------------------------------------------------------------
|
| R10: push chỉ tới người mà đường thư vừa chọn. Ba việc của văn phòng đổi "người cầm máy" mà không ai
| đăng xuất trên máy đó, nên đăng ký cũ phải đi theo:
|  - mục 1: đổi email cổng — địa chỉ mới là một người giữ MỚI, chưa xác minh; máy của người giữ cũ
|    không được nhận lại push khi người mới kích hoạt (`UpdatePortalAccount`);
|  - mục 2: "Đặt lại 2FA" — điện thoại mất vẫn đang đổ chuông (`ResetStaffTwoFactor`);
|  - mục 5: nút "Gỡ mọi máy nhận thông báo" trên trang tài khoản cổng — khách gọi văn phòng báo mất
|    máy (`docs/QUY-TRINH.md`).
| Cả ba gỡ qua `ForgetPushDevice::all()`: mỗi máy một dòng `push_device_removed` mang nhãn máy, người
| bấm và lý do — không bao giờ endpoint (R8).
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    config(WebPushTestKeys::config());
});

/**
 * Một vụ đã công bố cổng của khách `$account`, luật sư phụ trách trong đội.
 *
 * @return array{0: Matter, 1: User, 2: ClientUser}
 */
function revocationScenario(): array
{
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create();
    $account = ClientUser::factory()->activated()->create([
        'client_id' => $client->id,
        'is_active' => true,
        'email' => 'nguoi-giu-cu@vidu.test',
    ]);
    $matter = Matter::factory()->atStage('intake')->create([
        'client_id' => $client->id,
        'lead_lawyer_id' => $lawyer->id,
        'is_published_to_portal' => true,
    ]);
    $matter->team()->syncWithoutDetaching([$lawyer->id]);

    return [$matter, $lawyer, $account];
}

/** Luật sư chuyển giai đoạn có công bố trên tab Tiến độ — đường `client.stage_update` của khách. */
function revocationPublishStage(Matter $matter, User $lawyer, string $toStage): void
{
    Filament::setCurrentPanel('admin');
    test()->actingAs($lawyer, 'web');

    test()->livewire(StageLogsRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class])
        ->callTableAction('transitionStage', data: [
            'to_stage' => $toStage,
            'occurred_at' => today()->toDateString(),
            'internal_note' => null,
            'public_content' => 'Văn phòng đã nộp hồ sơ và đang chờ cơ quan có thẩm quyền xem xét.',
            'next_step' => null,
            'client_action' => null,
            'expected_next_update_at' => null,
            'publish' => true,
        ])
        ->assertHasNoTableActionErrors();
}

/** Người giữ địa chỉ mới đăng nhập bằng mật khẩu tạm và tự đặt mật khẩu — lần kích hoạt (R12). */
function revocationActivate(ClientUser $account): void
{
    Filament::setCurrentPanel('portal');
    test()->actingAs($account->fresh(), 'client');

    test()->livewire(ChangePassword::class)
        ->set('data.password', 'mat-khau-cua-nguoi-moi-2026')
        ->set('data.passwordConfirmation', 'mat-khau-cua-nguoi-moi-2026')
        ->call('changePassword')
        ->assertHasNoErrors();

    auth('client')->logout();
    Filament::setCurrentPanel('admin');
}

/** @return list<array<string, mixed>> các dòng `push_device_removed`, theo id */
function revocationRemovedRows(): array
{
    return Activity::query()->where('event', 'push_device_removed')->orderBy('id')->get()
        ->map(fn (Activity $row): array => [
            'subject' => $row->subject_type.':'.$row->subject_id,
            'causer' => $row->causer_type === null ? null : $row->causer_type.':'.$row->causer_id,
            'properties' => $row->properties->toArray(),
        ])->all();
}

// ---------------------------------------------------------------------------------------------
// Mục 1 — đổi email cổng gỡ máy của người giữ cũ
// ---------------------------------------------------------------------------------------------

/**
 * Nhân sự đổi email trên trang sửa tài khoản cổng; người giữ địa chỉ MỚI kích hoạt và bật máy của
 * mình; luật sư công bố một bước tiến độ. Đường thật (máy chủ push giả ở tầng HTTP, hàng `sync`): máy
 * của người giữ cũ không nhận request nào, máy mới nhận đúng một; thư đi tới địa chỉ mới.
 *
 * Mutation probe: bỏ lời gọi `ForgetPushDevice::all()` ở `UpdatePortalAccount` → máy cũ nhận request,
 * ĐỎ.
 */
it('stops pushing the previous holder once a changed portal email is activated by its new holder', function () {
    $server = FakePushServer::start();
    Mail::fake();
    [$matter, $lawyer, $account] = revocationScenario();
    $oldPhone = FakePushServer::device($account, 'may-nguoi-giu-cu');

    $this->actingAs($lawyer, 'web');
    $this->livewire(EditClientUser::class, ['record' => $account->getKey()])
        ->fillForm(['name' => $account->name, 'email' => 'nguoi-giu-moi@vidu.test'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($account->pushSubscriptions()->count())->toBe(0)
        ->and(revocationRemovedRows())->toBe([[
            'subject' => 'client_user:'.$account->id,
            'causer' => 'user:'.$lawyer->id,
            'properties' => ['device_label' => null, 'reason' => ForgetPushDevice::REASON_EMAIL_CHANGED],
        ]]);

    revocationActivate($account);
    $newPhone = FakePushServer::device($account->fresh(), 'may-nguoi-giu-moi');

    revocationPublishStage($matter, $lawyer, 'collecting_documents');

    Mail::assertSent(StageUpdate::class, fn (StageUpdate $mail): bool => $mail->hasTo('nguoi-giu-moi@vidu.test'));
    Mail::assertNotSent(StageUpdate::class, fn (StageUpdate $mail): bool => $mail->hasTo('nguoi-giu-cu@vidu.test'));
    expect($server->endpoints())->toBe([$newPhone])
        ->and($server->endpoints())->not->toContain($oldPhone);
});

/**
 * Hai vế âm: lưu không đổi email, và đổi CHỈ hoa/thường (cùng hộp thư — cùng luật gấp chữ của
 * `EditClientUser`, không đặt lại `activated_at`), đều để nguyên máy và không ghi dòng gỡ nào.
 *
 * Mutation probe: so email thô (`!==`) thay vì gấp chữ ở `UpdatePortalAccount::changesEmail()` →
 * dòng "chỉ đổi hoa/thường" ĐỎ; gỡ vô điều kiện → cả hai dòng ĐỎ.
 */
it('keeps the devices when a save does not change the portal mailbox', function (string $email) {
    [, $lawyer, $account] = revocationScenario();
    FakePushServer::device($account, 'may-cua-khach');

    $this->actingAs($lawyer, 'web');
    $this->livewire(EditClientUser::class, ['record' => $account->getKey()])
        ->fillForm(['name' => 'Tên đã sửa', 'email' => $email])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($account->fresh()->name)->toBe('Tên đã sửa')
        ->and($account->pushSubscriptions()->count())->toBe(1)
        ->and(revocationRemovedRows())->toBe([]);
})->with([
    'email giữ nguyên' => 'nguoi-giu-cu@vidu.test',
    'chỉ đổi hoa/thường' => 'Nguoi-Giu-Cu@VIDU.test',
]);

/**
 * Gỡ máy chạy SAU commit của lần lưu, ngoài transaction của `UpdatePortalAccount` (lần lưu không giữ
 * khoá dòng tài khoản trong lúc dọn máy; lưu rollback thì không có gì để dọn). Đo bằng mức
 * transaction lúc `ForgetPushDevice::all()` được gọi: đúng bằng mức của chính test (RefreshDatabase
 * bọc test trong một transaction — Laravel chạy `afterCommit` khi transaction của ỨNG DỤNG đóng).
 *
 * Mutation probe: gọi `ForgetPushDevice::all()` thẳng trong transaction (bỏ `DB::afterCommit`) → mức
 * cao hơn một, ĐỎ.
 */
it('forgets the devices only after the email change has committed', function () {
    [, $lawyer, $account] = revocationScenario();
    $baseLevel = DB::transactionLevel();
    $levelAtForget = null;

    $this->mock(ForgetPushDevice::class)
        ->shouldReceive('all')
        ->once()
        ->andReturnUsing(function () use (&$levelAtForget): int {
            $levelAtForget = DB::transactionLevel();

            return 0;
        });

    $this->actingAs($lawyer, 'web');
    $this->livewire(EditClientUser::class, ['record' => $account->getKey()])
        ->fillForm(['name' => $account->name, 'email' => 'nguoi-giu-moi@vidu.test'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($account->fresh()->email)->toBe('nguoi-giu-moi@vidu.test')
        ->and($levelAtForget)->toBe($baseLevel);
});

/**
 * Fix round 1 — phiên cổng của người giữ CŨ đang mở lúc văn phòng đổi email. Dựng đúng khoảng hở
 * thật: thư kích hoạt (`SendPortalActivationMail`, nơi mật khẩu tạm mới được ghi) còn nằm trong hàng
 * đợi (`Bus::fake`), và phiên cũ mang băm mật khẩu mà lần tải trang đầy đủ trước đó đã cất
 * (`AuthenticateSession`; client test không giữ cookie nên phải đặt tay — xem `LoginTest`).
 *
 * @return array{0: User, 1: ClientUser} luật sư, tài khoản (đã đổi email; mọi máy cũ đã gỡ)
 */
function revocationEmailChangedUnderAnOpenSession(): array
{
    Mail::fake();
    Bus::fake([SendPortalActivationMail::class]);
    [, $lawyer, $account] = revocationScenario();
    FakePushServer::device($account, 'may-nguoi-giu-cu');

    session()->put('password_hash_client', $account->getAuthPassword());

    Filament::setCurrentPanel('admin');
    test()->actingAs($lawyer, 'web');
    test()->livewire(EditClientUser::class, ['record' => $account->getKey()])
        ->fillForm(['name' => $account->name, 'email' => 'nguoi-giu-moi@vidu.test'])
        ->call('save')
        ->assertHasNoFormErrors();

    Bus::assertDispatched(SendPortalActivationMail::class);

    // Mỗi request thật nạp lại người dùng từ CSDL — `fresh()` là đúng điều đó.
    test()->actingAs($account->fresh(), 'client');

    return [$lawyer, $account];
}

/**
 * Người giữ cũ đang mở cổng: request đầy đủ kế tiếp của phiên đó — mở trang đổi mật khẩu (nơi
 * `RequirePortalPasswordChange` đẩy mọi trang khác tới) hay bấm "Bật trên máy này" — bị đăng xuất
 * ngay, không chờ hàng đợi xoay mật khẩu tạm. Không máy nào còn lại của tài khoản.
 *
 * Mutation probe: bỏ dòng đặt mật khẩu ngẫu nhiên ở `UpdatePortalAccount` → phiên cũ đi qua
 * `AuthenticateSession` (băm vẫn khớp): trang đổi mật khẩu mở ra 200, máy mới đăng ký 201, ĐỎ.
 */
it('signs the previous holder\'s open portal session out on its next request once the email changes', function (string $next) {
    [, $account] = revocationEmailChangedUnderAnOpenSession();

    $response = match ($next) {
        'open-change-password' => test()->get(ChangePassword::getUrl(panel: 'portal')),
        'enable-this-device' => test()->postJson('/portal/push/subscriptions', [
            'endpoint' => FakePushServer::endpoint('may-moi-cua-nguoi-giu-cu'),
            'keys' => WebPushTestKeys::subscription(),
            'contentEncoding' => 'aes128gcm',
        ]),
    };

    if ($next === 'enable-this-device') {
        $response->assertUnauthorized();
    } else {
        $response->assertRedirect(Filament::getPanel('portal')->getLoginUrl());
    }

    expect(auth('client')->check())->toBeFalse()
        ->and($account->pushSubscriptions()->count())->toBe(0)
        ->and($account->fresh()->activated_at)->toBeNull();
})->with([
    'mở trang đổi mật khẩu' => 'open-change-password',
    'bật thông báo trên máy này' => 'enable-this-device',
]);

/**
 * Người giữ cũ đã mở SẴN form đổi mật khẩu (request cập nhật Livewire không chạy `AuthenticateSession`
 * — middleware đó không bền): bấm lưu sau khi email đổi không "kích hoạt" được địa chỉ mới chưa ai
 * xác minh — mật khẩu không đổi thành của người đó, `activated_at` vẫn trống, phiên bị đăng xuất về
 * màn hình đăng nhập.
 *
 * Mutation probe: bỏ lần kiểm phiên ở `ChangePassword::changePassword()` → lưu thành công,
 * `activated_at` có giá trị, ĐỎ. Bỏ dòng mật khẩu ngẫu nhiên ở `UpdatePortalAccount` → băm trong
 * phiên vẫn khớp, lưu thành công, ĐỎ.
 */
it('refuses the first-password form to the previous holder once the email has changed under it', function () {
    [, $account] = revocationEmailChangedUnderAnOpenSession();
    $passwordAfterChange = $account->fresh()->getAuthPassword();

    Filament::setCurrentPanel('portal');
    test()->livewire(ChangePassword::class)
        ->set('data.password', 'mat-khau-cua-nguoi-giu-cu-2026')
        ->set('data.passwordConfirmation', 'mat-khau-cua-nguoi-giu-cu-2026')
        ->call('changePassword')
        ->assertRedirect(Filament::getPanel('portal')->getLoginUrl());

    $fresh = $account->fresh();
    expect($fresh->activated_at)->toBeNull()
        ->and($fresh->must_change_password)->toBeTrue()
        ->and($fresh->getAuthPassword())->toBe($passwordAfterChange)
        ->and(auth('client')->check())->toBeFalse()
        ->and($account->pushSubscriptions()->count())->toBe(0);
});

/**
 * Vế dương của lần kiểm phiên trên: khách mở trang đổi mật khẩu bằng một request đầy đủ THẬT
 * (`AuthenticateSession` cất băm dạng HMAC vào phiên), rồi bấm lưu — mật khẩu không đổi ở nơi khác
 * giữa hai lần, nên lưu thành công và đây là lần kích hoạt. Dạng băm THÔ (cái `changePassword()` tự
 * cất) đã có `LoginTest` "keeps the client signed in after they set their very first password".
 *
 * Mutation probe: bỏ nhánh so dạng HMAC ở `ChangePassword::sessionOutlivedItsPassword()` → phiên
 * hợp lệ bị đăng xuất, ĐỎ.
 */
it('lets a session whose page load stored the current password hash set its first password', function () {
    $account = ClientUser::factory()->create(['is_active' => true, 'must_change_password' => true]);

    test()->actingAs($account, 'client');
    test()->get(ChangePassword::getUrl(panel: 'portal'))->assertOk();

    $stored = session('password_hash_client');
    expect($stored)->toBeString()->not->toBe($account->getAuthPassword());

    Filament::setCurrentPanel('portal');
    test()->livewire(ChangePassword::class)
        ->set('data.password', 'mat-khau-dau-tien-2026')
        ->set('data.passwordConfirmation', 'mat-khau-dau-tien-2026')
        ->call('changePassword')
        ->assertHasNoErrors()
        ->assertRedirect(Filament::getPanel('portal')->getUrl());

    expect($account->fresh()->activated_at)->not->toBeNull()
        ->and(auth('client')->check())->toBeTrue();
});

// ---------------------------------------------------------------------------------------------
// Mục 2a — "Đặt lại 2FA" gỡ mọi máy của nhân sự
// ---------------------------------------------------------------------------------------------

/**
 * Admin bấm "Đặt lại 2FA" trên trang nhân sự: mọi máy của người bị đặt lại bị gỡ, mỗi máy một dòng
 * `push_device_removed` (người bấm là admin, lý do `two_factor_reset`); máy của người khác đứng
 * nguyên.
 *
 * Mutation probe: bỏ lời gọi `ForgetPushDevice::all()` ở `ResetStaffTwoFactor` → ĐỎ.
 */
it('forgets every device of a staff member whose two-factor setup the admin resets', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $target = User::factory()->withRole(Role::Lawyer)->create();
    $colleague = User::factory()->withRole(Role::Lawyer)->create();
    FakePushServer::device($target, 'dien-thoai-mat');
    FakePushServer::device($target, 'may-tinh-van-phong');
    $kept = FakePushServer::device($colleague, 'may-dong-nghiep');

    $this->actingAs($admin, 'web');
    $this->livewire(EditUser::class, ['record' => $target->getRouteKey()])
        ->callAction('resetTwoFactor')
        ->assertHasNoActionErrors();

    $removed = [
        'subject' => 'user:'.$target->id,
        'causer' => 'user:'.$admin->id,
        'properties' => ['device_label' => null, 'reason' => ForgetPushDevice::REASON_TWO_FACTOR_RESET],
    ];

    expect($target->fresh()->two_factor_secret)->toBeNull()
        ->and($target->pushSubscriptions()->count())->toBe(0)
        ->and(DB::table('push_subscriptions')->pluck('endpoint')->all())->toBe([$kept])
        ->and(revocationRemovedRows())->toBe([$removed, $removed]);
});

/** Lệnh console `vkcrm:reset-2fa` (admin duy nhất mất máy) đi cùng Action, nên cũng gỡ máy. */
it('forgets the devices on the console two-factor reset too', function () {
    $target = User::factory()->withRole(Role::Admin)->create();
    FakePushServer::device($target, 'dien-thoai-mat');

    $this->artisan('vkcrm:reset-2fa', ['email' => $target->email])->assertSuccessful();

    expect($target->pushSubscriptions()->count())->toBe(0)
        ->and(revocationRemovedRows())->toBe([[
            'subject' => 'user:'.$target->id,
            'causer' => null,
            'properties' => ['device_label' => null, 'reason' => ForgetPushDevice::REASON_TWO_FACTOR_RESET],
        ]]);
});

// ---------------------------------------------------------------------------------------------
// Mục 5 — nút "Gỡ mọi máy nhận thông báo" trên trang tài khoản cổng
// ---------------------------------------------------------------------------------------------

/**
 * Khách gọi văn phòng báo mất máy: luật sư bấm nút trên trang tài khoản cổng — mọi máy của ĐÚNG tài
 * khoản đó bị gỡ (tài khoản khác của cùng khách đứng nguyên), mỗi máy một dòng nhật ký mang người
 * bấm, và toast nói số máy.
 */
it('lets staff forget every push device of one portal account from its page', function () {
    [, $lawyer, $account] = revocationScenario();
    $spouse = ClientUser::factory()->activated()->create(['client_id' => $account->client_id]);
    FakePushServer::device($account, 'may-mat');
    FakePushServer::device($account, 'may-tinh-bang');
    $kept = FakePushServer::device($spouse, 'may-cua-chong');

    $this->actingAs($lawyer, 'web');
    $this->livewire(EditClientUser::class, ['record' => $account->getKey()])
        ->assertActionVisible('forgetPushDevices')
        ->callAction('forgetPushDevices')
        ->assertHasNoActionErrors()
        ->assertNotified(__('client_users.actions.forget_push_devices_success', ['count' => 2]));

    $removed = [
        'subject' => 'client_user:'.$account->id,
        'causer' => 'user:'.$lawyer->id,
        'properties' => ['device_label' => null, 'reason' => ForgetPushDevice::REASON_OFFICE],
    ];

    expect($account->pushSubscriptions()->count())->toBe(0)
        ->and(DB::table('push_subscriptions')->pluck('endpoint')->all())->toBe([$kept])
        ->and(revocationRemovedRows())->toBe([$removed, $removed]);
});

/** Không còn máy nào: nút vẫn chạy, nói thật là không có gì để gỡ, không ghi dòng nào. */
it('tells staff when the portal account has no device to forget', function () {
    [, $lawyer, $account] = revocationScenario();

    $this->actingAs($lawyer, 'web');
    $this->livewire(EditClientUser::class, ['record' => $account->getKey()])
        ->callAction('forgetPushDevices')
        ->assertNotified(__('client_users.actions.forget_push_devices_none'));

    expect(revocationRemovedRows())->toBe([]);
});

/**
 * Ai không sửa được tài khoản thì không thấy và không gọi được nút: ability riêng
 * `forgetPushDevices` trên `ClientUserPolicy` (cùng biên giới `update`). Gate từ chối đúng ability
 * này (trang vẫn mở được) → nút ẩn (Filament không gắn một nút ẩn, và `action()` hỏi lại Gate), máy
 * còn nguyên; luật sư ngoài tầm với và kế toán không mở được trang (404).
 *
 * Mutation probe: bỏ `->visible()` của nút → vế `assertActionHidden` ĐỎ; `ClientUserPolicy::
 * forgetPushDevices()` trả `true` → vế ability của luật sư ngoài tầm với ĐỎ.
 */
it('hides the forget-devices action from anyone the policy refuses', function () {
    [, $lawyer, $account] = revocationScenario();
    FakePushServer::device($account, 'may-cua-khach');
    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    expect($lawyer->can('forgetPushDevices', $account))->toBeTrue()
        ->and($outsider->can('forgetPushDevices', $account))->toBeFalse()
        ->and($accountant->can('forgetPushDevices', $account))->toBeFalse();

    $edit = ClientUserResource::getUrl('edit', ['record' => $account], panel: 'admin');
    $this->actingAs($outsider, 'web')->get($edit)->assertNotFound();
    $this->actingAs($accountant, 'web')->get($edit)->assertNotFound();

    Gate::before(fn (User $user, string $ability) => $ability === 'forgetPushDevices' ? false : null);

    $this->actingAs($lawyer, 'web');
    $this->livewire(EditClientUser::class, ['record' => $account->getKey()])
        ->assertActionHidden('forgetPushDevices');

    expect($account->pushSubscriptions()->count())->toBe(1)
        ->and(revocationRemovedRows())->toBe([]);
});
