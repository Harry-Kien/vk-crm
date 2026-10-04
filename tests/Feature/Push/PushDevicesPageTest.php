<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\PushDevices as AdminPushDevices;
use App\Filament\Portal\Pages\PushDevices as PortalPushDevices;
use App\Models\ClientUser;
use App\Models\User;
use App\Support\Push\PushSession;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\WebPushTestKeys;

/*
|--------------------------------------------------------------------------
| M12 Task 5 — trang "Thông báo trên điện thoại" của hai panel (R8, R14)
|--------------------------------------------------------------------------
|
| Trang chỉ liệt kê và gỡ máy của CHÍNH người đang xem (`$viewer->pushSubscriptions()`), không bao
| giờ in endpoint hay khoá (model của gói không có `$hidden`). Mọi ca đi qua HTTP (tải trang) hoặc
| Livewire (bấm nút) — không gọi thẳng Action.
*/

beforeEach(function () {
    config(WebPushTestKeys::config());
});

function pushPageEndpoint(string $suffix): string
{
    return 'https://web.push.apple.com/QOx7Hk-'.$suffix;
}

/** Một máy đã bật cho `$owner`, với nhãn và khoá thật như trình duyệt gửi. */
function pushPageDevice(User|ClientUser $owner, string $suffix, string $label): int
{
    $keys = WebPushTestKeys::subscription();
    $owner->updatePushSubscription(pushPageEndpoint($suffix), $keys['p256dh'], $keys['auth'], 'aes128gcm');

    $row = DB::table('push_subscriptions')->where('endpoint', pushPageEndpoint($suffix));
    $row->update(['device_label' => $label, 'last_seen_at' => '2026-10-02 08:30:00']);

    return (int) $row->value('id');
}

it('lists only the devices of the client viewing it, never another account of the same client', function () {
    $mine = ClientUser::factory()->activated()->create();
    $sibling = ClientUser::factory()->activated()->create(['client_id' => $mine->client_id]);
    pushPageDevice($mine, 'cua-a', 'Máy của A · iPhone');
    pushPageDevice($sibling, 'cua-b', 'Máy của B · Android');

    $html = $this->actingAs($mine, 'client')->get('/portal/thong-bao-dien-thoai')->assertOk()->getContent();

    expect($html)->toContain('Máy của A · iPhone')
        ->not->toContain('Máy của B')
        ->toContain('02/10/2026 08:30');
});

it('never prints an endpoint or a key, neither in the HTML nor in the Livewire snapshot', function () {
    $client = ClientUser::factory()->activated()->create();
    pushPageDevice($client, 'bi-mat', 'iPhone · Ứng dụng đã cài');
    $row = DB::table('push_subscriptions')->first();

    $html = $this->actingAs($client, 'client')->get('/portal/thong-bao-dien-thoai')->assertOk()->getContent();

    expect($html)->not->toContain('web.push.apple.com')
        ->not->toContain('QOx7Hk')
        ->not->toContain($row->public_key)
        ->not->toContain($row->auth_token);
});

it('opens the internal page for every staff role, an accountant included, with only their own devices', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    pushPageDevice($accountant, 'ke-toan', 'Windows · Edge');
    pushPageDevice($lawyer, 'luat-su', 'Mac · Safari');

    $html = $this->actingAs($accountant, 'web')->get('/admin/thong-bao-dien-thoai')->assertOk()->getContent();

    expect($html)->toContain('Windows · Edge')->not->toContain('Mac · Safari');
});

it('does not open the internal page for a signed-in client', function () {
    $client = ClientUser::factory()->activated()->create();

    $this->actingAs($client, 'client')->get('/admin/thong-bao-dien-thoai')->assertRedirect('/admin/login');
});

it('does not open the portal page for a signed-in staff member', function () {
    $staff = User::factory()->withRole(Role::Admin)->create();

    $this->actingAs($staff, 'web')->get('/portal/thong-bao-dien-thoai')->assertRedirect('/portal/login');
});

/**
 * Cổng của CHÍNH trang (`canAccess()` theo kiểu tài khoản, 404 ở mount VÀ ở mọi lần hydrate), không chỉ
 * cổng đăng nhập của panel: một tài khoản sai kiểu trên guard của panel (không xảy ra qua đăng nhập
 * thật, nhưng là thứ duy nhất trang tự nói) nhận 404, không phải 403.
 */
it('answers 404 from the page itself to an account of the wrong kind', function () {
    $staff = User::factory()->withRole(Role::Admin)->create();
    $client = ClientUser::factory()->activated()->create();

    Filament::setCurrentPanel('portal');
    $this->actingAs($staff, 'client');
    expect(PortalPushDevices::canAccess())->toBeFalse();
    Livewire::test(PortalPushDevices::class)->assertNotFound();

    Filament::setCurrentPanel('admin');
    $this->actingAs($client, 'web');
    expect(AdminPushDevices::canAccess())->toBeFalse();
    Livewire::test(AdminPushDevices::class)->assertNotFound();
});

it('sends a signed-out visitor to the sign-in page of each panel', function (string $panel) {
    $this->get("/{$panel}/thong-bao-dien-thoai")->assertRedirect("/{$panel}/login");
})->with(['admin', 'portal']);

it('removes my own device from the page and audits only its label', function () {
    Filament::setCurrentPanel('portal');
    $client = ClientUser::factory()->activated()->create();
    $id = pushPageDevice($client, 'go', 'Android · Chrome');

    $this->actingAs($client, 'client');
    Livewire::test(PortalPushDevices::class)
        ->assertSee('Android · Chrome')
        ->call('removeDevice', $id)
        ->assertNotified(__('push.devices.removed'))
        ->assertDontSee('Android · Chrome')
        ->assertSee(__('push.devices.empty'));

    $removed = Activity::query()->where('event', 'push_device_removed')->sole();
    expect(DB::table('push_subscriptions')->count())->toBe(0)
        ->and($removed->properties->toArray())->toBe(['device_label' => 'Android · Chrome'])
        ->and($removed->subject_id)->toBe($client->id);
});

it('answers 404 to removing a device id of someone else, and leaves that row alone', function () {
    Filament::setCurrentPanel('portal');
    $client = ClientUser::factory()->activated()->create();
    $sibling = ClientUser::factory()->activated()->create(['client_id' => $client->client_id]);
    $theirs = pushPageDevice($sibling, 'cua-nguoi-khac', 'iPhone · Safari');

    $this->actingAs($client, 'client');
    Livewire::test(PortalPushDevices::class)
        ->call('removeDevice', $theirs)
        ->assertNotFound();

    expect(DB::table('push_subscriptions')->where('id', $theirs)->exists())->toBeTrue()
        ->and(Activity::query()->where('event', 'push_device_removed')->count())->toBe(0);
});

it('removes every device of mine with the remove-all button, and nobody else\'s', function () {
    Filament::setCurrentPanel('admin');
    $staff = User::factory()->withRole(Role::Lawyer)->create();
    $other = User::factory()->withRole(Role::Lawyer)->create();
    pushPageDevice($staff, 'mot', 'iPhone · Ứng dụng đã cài');
    pushPageDevice($staff, 'hai', 'Windows · Chrome');
    $kept = pushPageDevice($other, 'ba', 'Android · Chrome');

    $this->actingAs($staff, 'web');
    Livewire::test(AdminPushDevices::class)
        ->assertSee(__('push.devices.remove_all'))
        ->call('removeAllDevices')
        ->assertNotified(__('push.devices.removed_all', ['count' => 2]));

    expect($staff->pushSubscriptions()->count())->toBe(0)
        ->and(DB::table('push_subscriptions')->pluck('id')->all())->toBe([$kept])
        ->and(Activity::query()->where('event', 'push_device_removed')->count())->toBe(2);
});

it('marks the device of this browser and forgets it from the session once it is removed', function () {
    Filament::setCurrentPanel('portal');
    $client = ClientUser::factory()->activated()->create();
    pushPageDevice($client, 'khac', 'Windows · Edge');
    $current = pushPageDevice($client, 'nay', 'iPhone · Ứng dụng đã cài');

    $this->actingAs($client, 'client')->withSession([PushSession::endpointKey('client') => pushPageEndpoint('nay')]);

    $html = $this->get('/portal/thong-bao-dien-thoai')->assertOk()->getContent();
    expect(substr_count($html, 'data-vk-push-current'))->toBe(1);
    preg_match('/data-vk-push-row="(\d+)"(?:(?!data-vk-push-row).)*data-vk-push-current/s', $html, $match);
    expect((int) ($match[1] ?? 0))->toBe($current);

    Livewire::test(PortalPushDevices::class)->call('removeDevice', $current);

    expect(session()->has(PushSession::endpointKey('client')))->toBeFalse();
});

/**
 * Các lần phát `vk-push-device-removed` của request Livewire vừa rồi. Phải là sự kiện TOÀN CỤC (không
 * `self()`, `to()`, `ref`, `el`): chỉ bản đó nổi bọt từ phần tử component lên `window`, nơi
 * `public/pwa/register.js` nghe.
 *
 * @return list<array<string, mixed>>
 */
function pushDeviceRemovedDispatches(mixed $component): array
{
    return collect($component->effects['dispatches'] ?? [])
        ->where('name', 'vk-push-device-removed')
        ->values()
        ->all();
}

/**
 * Vòng sửa 1 (I1) — khối "Máy này" nằm trong `wire:ignore`, chỉ `register.js` đổi nó. Gỡ dòng "Máy
 * đang dùng" thì máy chủ thôi gửi tới máy này, nên trang phải báo cho script để câu "Máy này đang
 * nhận thông báo." nhường chỗ cho nút Bật; gỡ một máy KHÁC thì không báo — máy này vẫn nhận.
 */
it('tells the script on the page when the device of this browser is removed, and not when another one is', function () {
    Filament::setCurrentPanel('portal');
    $client = ClientUser::factory()->activated()->create();
    $other = pushPageDevice($client, 'khac', 'Windows · Edge');
    $current = pushPageDevice($client, 'nay', 'iPhone · Ứng dụng đã cài');

    $this->actingAs($client, 'client')->withSession([PushSession::endpointKey('client') => pushPageEndpoint('nay')]);

    $page = Livewire::test(PortalPushDevices::class)->call('removeDevice', $other);
    expect(pushDeviceRemovedDispatches($page))->toBe([])
        ->and(session(PushSession::endpointKey('client')))->toBe(pushPageEndpoint('nay'));

    $page->call('removeDevice', $current);
    expect(pushDeviceRemovedDispatches($page))->toBe([['name' => 'vk-push-device-removed', 'params' => []]])
        ->and(DB::table('push_subscriptions')->count())->toBe(0);
});

/**
 * "Gỡ mọi thiết bị" luôn gỡ cả máy này (nếu nó đang nhận), nên luôn báo cho script — kể cả khi phiên
 * không còn nhớ endpoint của máy này; script chỉ đổi khối khi nó đang nói "đang nhận".
 */
it('tells the script on the page when every device is removed at once', function (?string $sessionEndpoint) {
    Filament::setCurrentPanel('admin');
    $staff = User::factory()->withRole(Role::Lawyer)->create();
    pushPageDevice($staff, 'mot', 'iPhone · Ứng dụng đã cài');
    pushPageDevice($staff, 'hai', 'Windows · Chrome');

    $this->actingAs($staff, 'web');
    if ($sessionEndpoint !== null) {
        $this->withSession([PushSession::endpointKey('web') => $sessionEndpoint]);
    }

    $page = Livewire::test(AdminPushDevices::class);
    expect(pushDeviceRemovedDispatches($page))->toBe([]);

    $page->call('removeAllDevices');
    expect(pushDeviceRemovedDispatches($page))->toBe([['name' => 'vk-push-device-removed', 'params' => []]])
        ->and($staff->pushSubscriptions()->count())->toBe(0);
})->with([
    'this browser is one of them' => [pushPageEndpoint('mot')],
    'the session no longer remembers this browser' => [null],
]);

it('shows the enable button, the iPhone install hint and the logout note when push is configured', function (string $panel, Closure $viewer) {
    $user = $viewer();
    $guard = $panel === 'admin' ? 'web' : 'client';

    $html = $this->actingAs($user, $guard)->get("/{$panel}/thong-bao-dien-thoai")->assertOk()->getContent();

    expect($html)
        ->toContain('data-vk-push-enable')
        ->toContain(e(__('push.devices.state.ios_install', ['app' => __("pwa.{$panel}.short_name", ['firm' => config('vkcrm.brand.short_name')])])))
        ->toContain(e(__('push.devices.logout_note')))
        ->toContain(e(__("push.devices.lead.{$panel}")))
        ->not->toContain('data-vk-push-off');

    // Câu mặc định là "chưa nhận được"; mọi câu trạng thái khác in sẵn nhưng ẩn.
    preg_match_all('/<[a-z]+ data-vk-push-state="([a-z-]+)"([^>]*)>/', $html, $states, PREG_SET_ORDER);
    $hidden = collect($states)->mapWithKeys(fn (array $m): array => [$m[1] => str_contains($m[2], 'hidden')])->all();
    expect($hidden)->toBe([
        'unsupported' => false,
        'ios-install' => true,
        'denied' => true,
        'ready' => true,
        'enabled' => true,
        'failed' => true,
    ]);
})->with([
    'admin' => ['admin', fn () => User::factory()->withRole(Role::Lawyer)->create()],
    'portal' => ['portal', fn () => ClientUser::factory()->activated()->create()],
]);

it('keeps push quiet without VAPID keys: no enable button, a plain note, no menu item, no invite strip', function (string $panel, Closure $viewer) {
    config(['webpush.vapid.private_key' => '']);
    $user = $viewer();
    $guard = $panel === 'admin' ? 'web' : 'client';
    pushPageDevice($user, 'cu', 'Android · Chrome');

    $page = $this->actingAs($user, $guard)->get("/{$panel}/thong-bao-dien-thoai")->assertOk()->getContent();
    expect($page)->not->toContain('data-vk-push-enable')
        ->not->toContain('data-vk-push-state')
        ->toContain('data-vk-push-off')
        ->toContain(e(__('push.devices.off')))
        // Danh sách và nút Gỡ vẫn còn — gỡ luôn được.
        ->toContain('Android · Chrome')
        ->toContain('removeDevice(');

    $home = $this->get("/{$panel}")->assertOk()->getContent();
    expect($home)->not->toContain('/thong-bao-dien-thoai')
        ->not->toContain('data-vk-push-invite');
})->with([
    'admin' => ['admin', fn () => User::factory()->withRole(Role::Lawyer)->create()],
    'portal' => ['portal', fn () => ClientUser::factory()->activated()->create()],
]);

it('puts the page in the user menu of each panel when push is configured', function (string $panel, Closure $viewer) {
    $user = $viewer();
    $guard = $panel === 'admin' ? 'web' : 'client';

    $html = $this->actingAs($user, $guard)->get("/{$panel}")->assertOk()->getContent();

    expect($html)->toContain(e(__('push.devices.menu')))
        ->toContain("/{$panel}/thong-bao-dien-thoai");
})->with([
    'admin' => ['admin', fn () => User::factory()->withRole(Role::Accountant)->create()],
    'portal' => ['portal', fn () => ClientUser::factory()->activated()->create()],
]);

/**
 * R8: dải mời in sẵn, ẩn, trên trang đã đăng nhập; `register.js` quyết khi nào hiện. Không có ở
 * trang đăng nhập.
 */
it('prints the hidden invite strip on signed-in pages only', function (string $panel, Closure $viewer) {
    $user = $viewer();
    $guard = $panel === 'admin' ? 'web' : 'client';

    expect($this->get("/{$panel}/login")->assertOk()->getContent())->not->toContain('data-vk-push-invite');

    $html = $this->actingAs($user, $guard)->get("/{$panel}")->assertOk()->getContent();

    expect(substr_count($html, 'data-vk-push-invite'))->toBe(1)
        ->and($html)->toMatch('/<div data-vk-push-invite hidden>/')
        ->toContain(e(__('push.invite.text')))
        ->toContain('data-vk-push-dismiss');
})->with([
    'admin' => ['admin', fn () => User::factory()->withRole(Role::Lawyer)->create()],
    'portal' => ['portal', fn () => ClientUser::factory()->activated()->create()],
]);
