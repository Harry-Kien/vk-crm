<?php

use App\Actions\User\ResetStaffTwoFactor;
use App\Enums\Role;
use App\Filament\Portal\Pages\MyMatters;
use App\Models\ClientUser;
use App\Models\User;
use App\Support\Push\PushSession;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\WebPushTestKeys;

/*
|--------------------------------------------------------------------------
| M12 Task 6 — đăng xuất và cắt phiên gỡ đúng thiết bị đang đăng xuất (kế hoạch M12, R9)
|--------------------------------------------------------------------------
|
| Endpoint của trình duyệt này nằm trong phiên theo GUARD (`push.endpoint.web` / `.client`,
| `App\Support\Push\PushSession`), ghi ở lượt Bật hoặc lượt kiểm "của mình" (Task 5). Mọi đường
| đăng xuất phát một sự kiện TRƯỚC khi phiên bị xoá, và listener gỡ đúng dòng đó — chỉ khi dòng
| thuộc đúng người đang đăng xuất:
|  1. nút Đăng xuất của hai panel (`Filament\Auth\Http\Controllers\LogoutController`, `Logout`);
|  2. cắt phiên SPEC §10.9 (`EnsurePortalAccountIsActive`, `Logout`) — cả trên request cập nhật
|     Livewire;
|  3. "Đặt lại 2FA" (`RejectStaffSessionsFromBeforeReset`, `Logout`, KHÔNG `invalidate()`);
|  4. mật khẩu đổi ở nơi khác (`AuthenticateSession` của panel, `CurrentDeviceLogout`, rồi
|     `flush()`).
|
| Mọi ca đi qua HTTP thật: thiết bị được bật bằng chính `POST …/push/subscriptions` (để khoá phiên
| do code thật ghi), rồi đăng xuất bằng route hay middleware thật.
*/

beforeEach(function () {
    config(WebPushTestKeys::config());
});

function logoutPushEndpoint(string $suffix): string
{
    return 'https://fcm.googleapis.com/fcm/send/'.$suffix.':APA91bLogoutT6';
}

/** Bật máy này bằng đúng request mà `register.js` gửi khi người dùng bấm "Bật trên máy này". */
function logoutPushEnable(string $panel, string $endpoint): void
{
    test()->postJson("/{$panel}/push/subscriptions", [
        'endpoint' => $endpoint,
        'keys' => WebPushTestKeys::subscription(),
        'contentEncoding' => 'aes128gcm',
    ])->assertCreated();
}

/** Một máy KHÁC của `$owner` (trình duyệt khác, phiên khác) — dựng thẳng qua quan hệ của chính người đó. */
function logoutPushOtherDevice(User|ClientUser $owner, string $endpoint): void
{
    $keys = WebPushTestKeys::subscription();
    $owner->updatePushSubscription($endpoint, $keys['p256dh'], $keys['auth'], 'aes128gcm');
}

/** Dòng đăng ký (của bất kỳ ai) có endpoint này — đọc thẳng bảng, chỉ trong test. */
function logoutPushRow(string $endpoint): ?object
{
    return DB::table('push_subscriptions')->where('endpoint', $endpoint)->first();
}

function logoutPushClient(): ClientUser
{
    return ClientUser::factory()->activated()->create();
}

function logoutPushStaff(Role $role = Role::Lawyer): User
{
    return User::factory()->withRole($role)->create();
}

it('removes the device of this browser on portal logout, and leaves the second device of the same client', function () {
    $client = logoutPushClient();
    $thisPhone = logoutPushEndpoint('khach-may-nay');
    $otherPhone = logoutPushEndpoint('khach-may-khac');

    $this->actingAs($client, 'client');
    logoutPushEnable('portal', $thisPhone);
    logoutPushOtherDevice($client, $otherPhone);

    $this->post(Filament::getPanel('portal')->getLogoutUrl())
        ->assertRedirect(Filament::getPanel('portal')->getLoginUrl());

    expect(auth('client')->check())->toBeFalse()
        ->and(logoutPushRow($thisPhone))->toBeNull()
        ->and(logoutPushRow($otherPhone))->not->toBeNull()
        ->and((int) logoutPushRow($otherPhone)->subscribable_id)->toBe($client->id);

    // Một dòng nhật ký gỡ, trên chính chủ máy, chỉ mang nhãn thiết bị — không endpoint.
    $removed = Activity::query()->where('event', 'push_device_removed')->get();
    expect($removed)->toHaveCount(1)
        ->and($removed[0]->subject_type)->toBe('client_user')
        ->and($removed[0]->subject_id)->toBe($client->id)
        ->and($removed[0]->causer_id)->toBe($client->id)
        ->and(array_keys($removed[0]->properties->toArray()))->toBe(['device_label'])
        ->and(Activity::query()->get()->toJson())->not->toContain('fcm.googleapis.com');
});

/*
| SPEC §10.9 — tài khoản bị vô hiệu (hay khách hàng bị xoá mềm) giữa phiên: phiên chết ở request
| CẬP NHẬT Livewire kế tiếp (`EnsurePortalAccountIsActive` bền, `logout()` rồi `invalidate()`), và
| máy này thôi nhận push của tài khoản đó ngay lúc ấy. Request giả của đường ống bền vẫn đọc được
| khoá phiên: listener dùng kho phiên mà `StartSession` của request thật đã mở.
*/
it('removes the device of this browser when the session is cut on a livewire update (SPEC 10.9)', function (Closure $cut) {
    $client = logoutPushClient();
    $thisPhone = logoutPushEndpoint('khach-bi-cat');
    $otherPhone = logoutPushEndpoint('khach-may-thu-hai');

    $this->actingAs($client, 'client');
    logoutPushEnable('portal', $thisPhone);
    logoutPushOtherDevice($client, $otherPhone);

    preg_match_all('/wire:snapshot="([^"]*)"/', $this->get(MyMatters::getUrl(panel: 'portal'))->assertOk()->getContent(), $matches);
    $snapshot = collect($matches[1])
        ->map(fn (string $encoded): string => html_entity_decode($encoded, ENT_QUOTES))
        ->first(fn (string $json): bool => (json_decode($json, true)['memo']['name'] ?? null) === MyMatters::class);
    expect($snapshot)->not->toBeNull();

    $cut($client);
    $this->actingAs($client->fresh(), 'client');

    $this->withHeaders(['X-Livewire' => '1'])
        ->postJson(Livewire::getUpdateUri(), [
            'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]],
        ])
        ->assertRedirect(Filament::getPanel('portal')->getLoginUrl());

    expect(auth('client')->check())->toBeFalse()
        ->and(logoutPushRow($thisPhone))->toBeNull()
        ->and(logoutPushRow($otherPhone))->not->toBeNull();
})->with([
    'tài khoản cổng bị vô hiệu' => [fn (ClientUser $client) => $client->forceFill(['is_active' => false])->save()],
    'khách hàng bị xoá mềm' => [fn (ClientUser $client) => $client->client->delete()],
]);

/*
| Hai panel chung MỘT cookie phiên nhưng mỗi service worker (scope `/admin`, scope `/portal`) có
| đăng ký push RIÊNG. Panel bật SAU CÙNG ở đây là portal — với một khoá phiên chung, nó đã ghi đè
| endpoint của app nội bộ, và đăng xuất `/admin` bỏ sót máy của nhân sự (phán quyết (c)).
*/
it('removes only the staff device on admin logout, after both panels enabled push in the same browser', function () {
    $staff = logoutPushStaff();
    $client = logoutPushClient();
    $adminEndpoint = logoutPushEndpoint('app-noi-bo');
    $portalEndpoint = logoutPushEndpoint('app-khach');

    $this->actingAs($staff, 'web');
    logoutPushEnable('admin', $adminEndpoint);
    $this->actingAs($client, 'client');
    logoutPushEnable('portal', $portalEndpoint);

    expect(session(PushSession::endpointKey('web')))->toBe($adminEndpoint)
        ->and(session(PushSession::endpointKey('client')))->toBe($portalEndpoint);

    $this->actingAs($staff, 'web')
        ->post(Filament::getPanel('admin')->getLogoutUrl())
        ->assertRedirect(Filament::getPanel('admin')->getLoginUrl());

    expect(logoutPushRow($adminEndpoint))->toBeNull()
        ->and(logoutPushRow($portalEndpoint))->not->toBeNull()
        ->and(logoutPushRow($portalEndpoint)->subscribable_type)->toBe('client_user')
        ->and((int) logoutPushRow($portalEndpoint)->subscribable_id)->toBe($client->id);
});

/*
| Đường thứ ba (brief Task 6): "Đặt lại 2FA" làm `RejectStaffSessionsFromBeforeReset` `logout()`
| guard `web` ở request kế tiếp của phiên cũ — KHÔNG `invalidate()`. Listener vì vậy cũng phải xoá
| khoá phiên của guard đó, để người đăng nhập lại trong cùng phiên được kiểm lại từ đầu.
*/
it('removes the staff device when a 2FA reset logs the old session out on its next request', function () {
    $admin = logoutPushStaff(Role::Admin);
    $staff = logoutPushStaff();
    $endpoint = logoutPushEndpoint('may-bi-mat');

    $this->actingAs($staff, 'web');
    logoutPushEnable('admin', $endpoint);

    app(ResetStaffTwoFactor::class)->handle($admin, $staff);

    $this->actingAs($staff->fresh(), 'web')
        ->get(Filament::getPanel('admin')->getUrl())
        ->assertRedirect(Filament::getPanel('admin')->getLoginUrl());

    expect(auth('web')->check())->toBeFalse()
        ->and(logoutPushRow($endpoint))->toBeNull()
        ->and(session()->has(PushSession::endpointKey('web')))->toBeFalse()
        ->and(session()->has(PushSession::checkedKey('web')))->toBeFalse();
});

/*
| Đường thứ tư: mật khẩu đổi ở NƠI KHÁC (văn phòng đặt lại mật khẩu cho khách; nhân sự đổi trên
| máy khác). `AuthenticateSession` của panel thấy băm mật khẩu trong phiên lệch, gọi
| `logoutCurrentDevice()` — sự kiện `CurrentDeviceLogout`, KHÔNG phải `Logout` — rồi `flush()`.
*/
it('removes the device of this browser when the password changed elsewhere ends the session', function () {
    $client = logoutPushClient();
    $endpoint = logoutPushEndpoint('mat-khau-doi');

    $this->actingAs($client, 'client');
    $this->get(MyMatters::getUrl(panel: 'portal'))->assertOk();
    logoutPushEnable('portal', $endpoint);

    $client->forceFill(['password' => 'mat-khau-van-phong-dat-lai-2026'])->save();

    $this->actingAs($client->fresh(), 'client')
        ->get(MyMatters::getUrl(panel: 'portal'))
        ->assertRedirect(Filament::getPanel('portal')->getLoginUrl());

    expect(auth('client')->check())->toBeFalse()
        ->and(logoutPushRow($endpoint))->toBeNull();
});

it('never removes a device that is not the one signing out, even when the session points at it', function () {
    $client = logoutPushClient();
    $neighbour = logoutPushClient();
    $neighbourPhone = logoutPushEndpoint('may-hang-xom');
    $myOtherPhone = logoutPushEndpoint('may-khac-cua-toi');
    logoutPushOtherDevice($neighbour, $neighbourPhone);
    logoutPushOtherDevice($client, $myOtherPhone);

    $this->actingAs($client, 'client')
        ->withSession([PushSession::endpointKey('client') => $neighbourPhone])
        ->post(Filament::getPanel('portal')->getLogoutUrl())
        ->assertRedirect(Filament::getPanel('portal')->getLoginUrl());

    expect(logoutPushRow($neighbourPhone))->not->toBeNull()
        ->and((int) logoutPushRow($neighbourPhone)->subscribable_id)->toBe($neighbour->id)
        ->and(logoutPushRow($myOtherPhone))->not->toBeNull()
        ->and(Activity::query()->where('event', 'push_device_removed')->count())->toBe(0);
});

/*
| Khoá phiên chỉ được ghi sau khi endpoint qua luật của `RegisterPushDevice`, nhưng lối đăng xuất
| vẫn kiểm hình dạng: một giá trị lạ (ký tự ngoài ASCII, không phải chuỗi) không bao giờ vào câu
| WHERE trên cột `ascii` — MariaDB strict trả lỗi kèm câu SQL, tức kèm giá trị.
*/
it('never sends a malformed endpoint from the session to the database on logout', function (mixed $stored) {
    $client = logoutPushClient();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->actingAs($client, 'client')
        ->withSession([PushSession::endpointKey('client') => $stored])
        ->post(Filament::getPanel('portal')->getLogoutUrl())
        ->assertRedirect(Filament::getPanel('portal')->getLoginUrl());

    expect(auth('client')->check())->toBeFalse()
        ->and(collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'push_subscriptions'))->all())->toBe([]);
})->with([
    'ký tự ngoài ASCII' => ['https://fcm.googleapis.com/fcm/send/máy-của-tôi'],
    'không phải chuỗi' => [['endpoint' => 'https://fcm.googleapis.com/fcm/send/x']],
]);

/** `logout()` trên một guard không có ai đăng nhập vẫn phát `Logout` (người dùng `null`): không gỡ gì, không lỗi. */
it('ignores a logout event that carries no user', function () {
    logoutPushOtherDevice(logoutPushClient(), logoutPushEndpoint('khong-ai'));

    auth('client')->logout();

    expect(DB::table('push_subscriptions')->count())->toBe(1);
});

/*
| Rà soát Task 5, Minor 4(b): `DELETE …/push/subscriptions` chỉ quên khoá phiên khi endpoint vừa
| gỡ ĐÚNG là của trình duyệt này. Gỡ một máy KHÁC của mình rồi đăng xuất: máy này vẫn phải được gỡ.
*/
it('still removes this device on logout after another device of mine was removed through DELETE', function () {
    $client = logoutPushClient();
    $thisPhone = logoutPushEndpoint('may-dang-dung');
    $oldTablet = logoutPushEndpoint('may-tinh-bang-cu');

    $this->actingAs($client, 'client');
    logoutPushEnable('portal', $thisPhone);
    logoutPushOtherDevice($client, $oldTablet);

    $this->deleteJson('/portal/push/subscriptions', ['endpoint' => $oldTablet])->assertNoContent();

    expect(session(PushSession::endpointKey('client')))->toBe($thisPhone);

    $this->post(Filament::getPanel('portal')->getLogoutUrl())->assertRedirect();

    expect(logoutPushRow($thisPhone))->toBeNull()
        ->and(logoutPushRow($oldTablet))->toBeNull();
});

/*
| Dọn thiết bị là vệ sinh, không phải điều kiện để đăng xuất: CSDL hỏng đúng lúc gỡ thì người dùng
| VẪN ra khỏi phiên (phiên bị huỷ, về trang đăng nhập — không trang lỗi 500), và nhật ký chỉ mang
| tên lớp ngoại lệ: thông điệp của `QueryException` chứa câu SQL KÈM endpoint (một URL mang quyền
| gửi, R8).
*/
it('still signs the client out when removing the device fails, and logs no endpoint', function () {
    $client = logoutPushClient();
    $endpoint = logoutPushEndpoint('csdl-hong');

    $this->actingAs($client, 'client');
    logoutPushEnable('portal', $endpoint);

    DB::beforeExecuting(function (string $sql, array $bindings): void {
        if (str_contains($sql, 'push_subscriptions')) {
            throw new QueryException(DB::getDefaultConnection(), $sql, $bindings, new PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded'));
        }
    });

    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event;
    });

    $this->post(Filament::getPanel('portal')->getLogoutUrl())
        ->assertRedirect(Filament::getPanel('portal')->getLoginUrl());

    expect(auth('client')->check())->toBeFalse()
        ->and(session()->has(PushSession::endpointKey('client')))->toBeFalse();

    $warnings = collect($logged)->where('level', 'warning')->values();
    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]->context['exception'] ?? null)->toBe(QueryException::class)
        ->and(json_encode(collect($logged)->map(fn (MessageLogged $e): array => [$e->message, $e->context])->all()))
        ->not->toContain('csdl-hong')
        ->not->toContain('fcm.googleapis.com');
});
