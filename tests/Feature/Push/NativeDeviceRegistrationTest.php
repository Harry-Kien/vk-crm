<?php

use App\Actions\Push\RegisterNativeDevice;
use App\Enums\ApnsEnvironment;
use App\Enums\Role;
use App\Http\Controllers\Pwa\PushSubscriptionController;
use App\Models\ClientUser;
use App\Models\NativePushDevice;
use App\Models\User;
use App\Support\Push\PushSession;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PDOException;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| M15 Task 2 — sổ máy iPhone nhận thông báo (`native_push_devices`)
|--------------------------------------------------------------------------
|
| `POST /admin/native/devices` — route của `authenticatedRoutes()` panel admin, nên đi sau cùng chồng
| middleware với `/admin/push/subscriptions` (danh sách IP, đăng nhập, 2FA, phiên, CSRF).
| Luật cốt lõi: token APNs là MỘT dòng duy nhất (unique); người đăng ký sau lấy dòng từ người trước
| trong cùng một câu upsert — không bao giờ hai dòng cho một token.
*/

const NATIVE_UA = 'VKCRM/1.0 (iPhone; iOS 18.0) CFNetwork/1568 Darwin/24.0';

function nativeToken(string $seed = 'a'): string
{
    return hash('sha256', $seed);
}

/** @return array<string, mixed> */
function nativePayload(string $token, array $extra = []): array
{
    return ['token' => $token, 'environment' => 'sandbox', 'app_version' => '1.0.3', ...$extra];
}

function nativeRows(): Collection
{
    return DB::table('native_push_devices')->orderBy('id')->get();
}

function nativeStaff(Role $role = Role::Lawyer): User
{
    return User::factory()->withRole($role)->create();
}

it('registers a device for signed-in staff: 204, one row, session key, audit with label only', function () {
    $staff = nativeStaff();
    $token = nativeToken();

    $this->actingAs($staff, 'web')->withHeader('User-Agent', NATIVE_UA)
        ->postJson('/admin/native/devices', nativePayload($token))
        ->assertNoContent();

    $rows = nativeRows();
    expect($rows)->toHaveCount(1)
        ->and((int) $rows[0]->user_id)->toBe($staff->id)
        ->and($rows[0]->token)->toBe($token)
        ->and($rows[0]->environment)->toBe('sandbox')
        ->and($rows[0]->app_version)->toBe('1.0.3')
        ->and($rows[0]->device_label)->toBe('iPhone')
        ->and($rows[0]->last_seen_at)->not->toBeNull()
        ->and(session(PushSession::nativeTokenKey()))->toBe($token);

    $added = Activity::query()->where('event', 'push_device_added')->get();
    expect($added)->toHaveCount(1)
        ->and($added[0]->subject_id)->toBe($staff->id)
        ->and($added[0]->properties->toArray())->toBe(['device_label' => 'iPhone'])
        ->and(Activity::query()->get()->toJson())->not->toContain($token);

    expect($staff->nativePushDevices()->count())->toBe(1)
        ->and(NativePushDevice::first()->environment)->toBe(ApnsEnvironment::Sandbox);
});

it('registering the same token again keeps one row and refreshes last_seen_at and version', function () {
    $staff = nativeStaff();
    $token = nativeToken();

    $this->travelTo(now()->subDays(3));
    $this->actingAs($staff, 'web')->postJson('/admin/native/devices', nativePayload($token))->assertNoContent();
    $first = nativeRows()[0];

    $this->travelBack();
    $this->actingAs($staff, 'web')
        ->postJson('/admin/native/devices', nativePayload($token, ['app_version' => '1.1', 'environment' => 'production']))
        ->assertNoContent();

    $rows = nativeRows();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe($first->id)
        ->and($rows[0]->last_seen_at > $first->last_seen_at)->toBeTrue()
        ->and($rows[0]->app_version)->toBe('1.1')
        ->and($rows[0]->environment)->toBe('production');

    // Cùng chủ: không ghi lại "bật" lần hai.
    expect(Activity::query()->where('event', 'push_device_added')->count())->toBe(1);
});

it('moves a token to the staff member who registers it last: one row, A loses it (shared phone)', function () {
    $a = nativeStaff();
    $b = nativeStaff(Role::Accountant);
    $token = nativeToken();

    $this->actingAs($a, 'web')->postJson('/admin/native/devices', nativePayload($token))->assertNoContent();
    $this->actingAs($b, 'web')->postJson('/admin/native/devices', nativePayload($token))->assertNoContent();

    $rows = nativeRows();
    expect($rows)->toHaveCount(1)
        ->and((int) $rows[0]->user_id)->toBe($b->id)
        ->and($a->nativePushDevices()->count())->toBe(0);

    $removed = Activity::query()->where('event', 'push_device_removed')->get();
    expect($removed)->toHaveCount(1)
        ->and($removed[0]->subject_id)->toBe($a->id)
        ->and($removed[0]->causer_id)->toBe($b->id);
});

it('rejects a malformed token, environment or version with 422 and writes nothing', function (array $override) {
    $this->actingAs(nativeStaff(), 'web')
        ->postJson('/admin/native/devices', nativePayload(nativeToken(), $override))
        ->assertUnprocessable();

    expect(nativeRows())->toHaveCount(0);
})->with([
    'xyz' => [['token' => 'xyz']],
    'đường dẫn' => [['token' => '../../etc/passwd']],
    'chữ hoa' => [['token' => strtoupper(str_repeat('ab', 40))]],
    '63 ký tự' => [['token' => str_repeat('a', 63)]],
    '301 ký tự' => [['token' => str_repeat('a', 301)]],
    'không phải chuỗi' => [['token' => ['a']]],
    'môi trường lạ' => [['environment' => 'staging']],
    'thiếu môi trường' => [['environment' => null]],
    'phiên bản lạ' => [['app_version' => '1.0-beta; drop']],
    'thiếu phiên bản' => [['app_version' => null]],
]);

it('accepts the 200-character upper bound', function () {
    $this->actingAs(nativeStaff(), 'web')
        ->postJson('/admin/native/devices', nativePayload(str_repeat('ab', 100)))
        ->assertNoContent();

    expect(nativeRows())->toHaveCount(1);
});

it('does not serve a portal client: no row', function () {
    $client = ClientUser::factory()->activated()->create();

    $response = $this->actingAs($client, 'client')->postJson('/admin/native/devices', nativePayload(nativeToken()));

    expect($response->getStatusCode())->toBeIn([401, 403, 404])
        ->and(nativeRows())->toHaveCount(0);
});

it('sends a signed-out caller to sign-in and writes nothing', function () {
    $this->post('/admin/native/devices', nativePayload(nativeToken()))->assertRedirect('/admin/login');
    $this->postJson('/admin/native/devices', nativePayload(nativeToken()))->assertUnauthorized();
    $this->deleteJson('/admin/native/devices')->assertUnauthorized();

    expect(nativeRows())->toHaveCount(0);
});

it('answers 419 without a CSRF token and writes nothing', function () {
    app()->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
    {
        protected function runningUnitTests()
        {
            return false;
        }
    });

    $this->actingAs(nativeStaff(), 'web')
        ->post('/admin/native/devices', nativePayload(nativeToken()), ['Accept' => 'application/json'])
        ->assertStatus(419);

    expect(nativeRows())->toHaveCount(0);
});

it('shares the admin push route stack plus throttle:30,1', function () {
    $routes = app('router')->getRoutes();
    $store = $routes->getByName('filament.admin.native.devices.store');
    $destroy = $routes->getByName('filament.admin.native.devices.destroy');
    $webPush = $routes->getByName('filament.admin.push.subscriptions.store');

    expect($store)->not->toBeNull()
        ->and($store->uri())->toBe('admin/native/devices')
        ->and($destroy->uri())->toBe('admin/native/devices')
        ->and($store->gatherMiddleware())->toContain('throttle:30,1')
        ->and(array_values(array_diff($store->gatherMiddleware(), ['throttle:30,1'])))
        ->toEqual(array_values(array_diff($webPush->gatherMiddleware(), ['throttle:'.PushSubscriptionController::RATE_LIMITER])));
});

it('moves ownership through the action as well (second owner wins, one row)', function () {
    $a = nativeStaff();
    $b = nativeStaff(Role::Accountant);
    $token = nativeToken();

    app(RegisterNativeDevice::class)->handle($a, nativePayload($token), NATIVE_UA);
    app(RegisterNativeDevice::class)->handle($b, nativePayload($token), NATIVE_UA);

    expect(nativeRows())->toHaveCount(1)
        ->and((int) nativeRows()[0]->user_id)->toBe($b->id);
});

it('retries a deadlock on first registration (MariaDB gap lock) instead of answering 500', function () {
    $thrown = 0;
    NativePushDevice::creating(function () use (&$thrown): void {
        if ($thrown++ === 0) {
            throw new QueryException('mariadb', 'insert into native_push_devices', [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
        }
    });

    $staff = nativeStaff();

    $this->actingAs($staff, 'web')->postJson('/admin/native/devices', nativePayload(nativeToken()))->assertNoContent();

    expect($thrown)->toBe(2)
        ->and(nativeRows())->toHaveCount(1)
        ->and((int) nativeRows()[0]->user_id)->toBe($staff->id)
        ->and(Activity::query()->where('event', 'push_device_added')->count())->toBe(1);
});
