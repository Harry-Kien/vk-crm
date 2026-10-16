<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\PushDevices as AdminPushDevices;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\WebPushTestKeys;

/*
| M15 Task 2, vòng sửa 1 — trang "Thông báo trên điện thoại" liệt kê cả ứng dụng iPhone, gỡ từng máy,
| và "Gỡ mọi thiết bị" báo đúng số máy trang đang hiện.
*/

beforeEach(function () {
    config(WebPushTestKeys::config());
});

function pageIphone(User $owner, string $seed, string $label = 'iPhone'): int
{
    return (int) DB::table('native_push_devices')->insertGetId([
        'user_id' => $owner->id,
        'token' => hash('sha256', $seed),
        'environment' => 'sandbox',
        'device_label' => $label,
        'app_version' => '1.2.0',
        'last_seen_at' => '2026-10-02 08:30:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('shows the iPhone app section with label, version and last seen, never the token', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();
    pageIphone($staff, 'a', 'iPhone của luật sư');

    $html = $this->actingAs($staff, 'web')->get('/admin/thong-bao-dien-thoai')->assertOk()->getContent();

    expect($html)->toContain('data-vk-native-list')
        ->toContain(__('push.devices.native_heading'))
        ->toContain('iPhone của luật sư')
        ->toContain('1.2.0')
        ->toContain('02/10/2026 08:30')
        ->toContain('removeNativeDevice(')
        ->not->toContain(hash('sha256', 'a'));
});

it('hides the section when the person has no iPhone', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();

    $this->actingAs($staff, 'web')->get('/admin/thong-bao-dien-thoai')->assertOk()
        ->assertDontSee('data-vk-native-list', false);
});

it('removes only the viewer\'s own iPhone; another person\'s id is 404 and stays', function () {
    $mine = User::factory()->withRole(Role::Lawyer)->create();
    $theirs = User::factory()->withRole(Role::Accountant)->create();
    $myId = pageIphone($mine, 'a');
    $theirId = pageIphone($theirs, 'b');

    $this->actingAs($mine, 'web');
    Filament::setCurrentPanel('admin');
    $page = Livewire::test(AdminPushDevices::class);

    Livewire::test(AdminPushDevices::class)->call('removeNativeDevice', $theirId)->assertNotFound();
    expect(DB::table('native_push_devices')->where('id', $theirId)->exists())->toBeTrue();

    $page->call('removeNativeDevice', $myId);
    expect(DB::table('native_push_devices')->where('id', $myId)->exists())->toBeFalse()
        ->and(DB::table('native_push_devices')->where('id', $theirId)->exists())->toBeTrue();
});

it('"Gỡ mọi thiết bị" removes web and iPhone devices and reports the matching count', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();
    $keys = WebPushTestKeys::subscription();
    $staff->updatePushSubscription('https://fcm.googleapis.com/fcm/send/pg:APA91bPage', $keys['p256dh'], $keys['auth'], 'aes128gcm');
    pageIphone($staff, 'a');
    pageIphone($staff, 'b');

    $this->actingAs($staff, 'web');
    Filament::setCurrentPanel('admin');
    $page = Livewire::test(AdminPushDevices::class);

    expect($page->instance()->devices())->toHaveCount(1)
        ->and($page->instance()->nativeDevices())->toHaveCount(2);

    $page->call('removeAllDevices')->assertNotified(__('push.devices.removed_all', ['count' => 3]));

    expect(DB::table('native_push_devices')->count())->toBe(0)
        ->and($staff->pushSubscriptions()->count())->toBe(0);
});

it('does not say "no device" when the person has only an iPhone', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();
    pageIphone($staff, 'a');

    $html = $this->actingAs($staff, 'web')->get('/admin/thong-bao-dien-thoai')->assertOk()->getContent();

    expect($html)->toContain('data-vk-native-list')
        ->not->toContain('data-vk-push-empty')
        ->not->toContain(__('push.devices.empty'))
        ->not->toContain('removeAllDevices');
});

it('still says "no device" when there is neither a browser nor an iPhone', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();

    $this->actingAs($staff, 'web')->get('/admin/thong-bao-dien-thoai')->assertOk()
        ->assertSee('data-vk-push-empty', false);
});
