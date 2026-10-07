<?php

use App\Actions\Push\RegisterPushDevice;
use App\Enums\Role;
use App\Models\ClientUser;
use App\Models\User;
use App\Support\Push\PushSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use NotificationChannels\WebPush\PushSubscription;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\WebPushTestKeys;

/*
|--------------------------------------------------------------------------
| M12 Task 5 — đăng ký thiết bị nhận thông báo đẩy (kế hoạch M12, phán quyết R8)
|--------------------------------------------------------------------------
|
| `POST /{admin,portal}/push/subscriptions` (Bật, hoặc `sync=1` chỉ để kiểm) và
| `DELETE /{admin,portal}/push/subscriptions` — route của `authenticatedRoutes()` từng panel, nên
| đi sau toàn bộ chồng middleware có phiên và cổng đăng nhập của panel đó. Mọi ca đi qua HTTP thật.
|
| Ba luật được canh ở đây:
|  - SSRF: một người đã đăng nhập (kể cả khách) không được khiến máy chủ tự POST tới một địa chỉ
|    tuỳ ý theo lịch — endpoint phải là `https` tới đúng một máy chủ push đã biết
|    (`config('vkcrm.pwa.push_hosts')`);
|  - máy dùng chung: lượt kiểm `sync=1` KHÔNG BAO GIỜ chuyển chủ một endpoint; chỉ cú bấm Bật;
|  - endpoint là một URL mang quyền gửi: không bao giờ vào audit, log, hay câu trả lời.
*/

beforeEach(function () {
    config(WebPushTestKeys::config());
});

const PUSH_DEVICE_IPHONE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 '
    .'(KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1';

function pushDeviceEndpoint(string $suffix = 'thiet-bi'): string
{
    return 'https://fcm.googleapis.com/fcm/send/'.$suffix.':APA91bHun4MxP5egoKMwt2KZFBaFUH';
}

/** @return array<string, mixed> thân request như `PushSubscription.toJSON()` của trình duyệt */
function pushDevicePayload(string $endpoint, array $extra = []): array
{
    return [
        'endpoint' => $endpoint,
        'keys' => WebPushTestKeys::subscription(),
        'contentEncoding' => 'aes128gcm',
        ...$extra,
    ];
}

function pushDeviceUrl(string $panel): string
{
    return "/{$panel}/push/subscriptions";
}

/** Dòng đăng ký (của bất kỳ ai) có endpoint này — đọc thẳng bảng, chỉ trong test. */
function pushDeviceRow(string $endpoint): ?object
{
    return DB::table('push_subscriptions')->where('endpoint', $endpoint)->first();
}

function pushDeviceActivities(string $event)
{
    return Activity::query()->where('event', $event)->orderBy('id')->get();
}

/** Chủ thật của tài khoản khách đã kích hoạt — người nhận push hợp lệ. */
function pushDeviceClient(array $attributes = []): ClientUser
{
    return ClientUser::factory()->activated()->create($attributes);
}

it('registers a device for a signed-in client: a client_user row, an audit row with only the device label', function () {
    $client = pushDeviceClient();
    $endpoint = pushDeviceEndpoint('khach-bat');
    $payload = pushDevicePayload($endpoint);

    $response = $this->actingAs($client, 'client')
        ->withHeader('User-Agent', PUSH_DEVICE_IPHONE_UA)
        ->postJson(pushDeviceUrl('portal'), $payload);

    $response->assertCreated()->assertExactJson(['status' => 'enabled']);
    expect($response->getContent())->not->toContain($endpoint);

    $row = pushDeviceRow($endpoint);
    expect($row)->not->toBeNull()
        ->and($row->subscribable_type)->toBe('client_user')
        ->and((int) $row->subscribable_id)->toBe($client->id)
        ->and($row->public_key)->toBe($payload['keys']['p256dh'])
        ->and($row->auth_token)->toBe($payload['keys']['auth'])
        ->and($row->content_encoding)->toBe('aes128gcm')
        ->and($row->device_label)->toBe('iPhone · Safari')
        ->and($row->last_seen_at)->not->toBeNull();

    $added = pushDeviceActivities('push_device_added');
    expect($added)->toHaveCount(1)
        ->and($added[0]->subject_type)->toBe('client_user')
        ->and($added[0]->subject_id)->toBe($client->id)
        ->and($added[0]->causer_type)->toBe('client_user')
        ->and($added[0]->causer_id)->toBe($client->id)
        ->and($added[0]->properties->toArray())->toBe(['device_label' => 'iPhone · Safari']);

    // Endpoint (và hai khoá) không nằm ở BẤT KỲ đâu trong nhật ký — kể cả mô tả.
    $everyAuditRow = Activity::query()->get()->toJson();
    expect($everyAuditRow)->not->toContain('fcm.googleapis.com')
        ->not->toContain($payload['keys']['p256dh'])
        ->not->toContain($payload['keys']['auth']);

    // R9: endpoint ghi vào phiên theo GUARD — đăng xuất của guard này gỡ đúng máy này (Task 6).
    expect(session(PushSession::endpointKey('client')))->toBe($endpoint)
        ->and(session()->has(PushSession::endpointKey('web')))->toBeFalse();
});

it('registers a device for a signed-in staff member as a user row, under the web session key', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();
    $endpoint = pushDeviceEndpoint('nhan-su');

    $this->actingAs($staff, 'web')->postJson(pushDeviceUrl('admin'), pushDevicePayload($endpoint))
        ->assertCreated();

    $row = pushDeviceRow($endpoint);
    expect($row->subscribable_type)->toBe('user')
        ->and((int) $row->subscribable_id)->toBe($staff->id)
        ->and(session(PushSession::endpointKey('web')))->toBe($endpoint)
        ->and(session()->has(PushSession::endpointKey('client')))->toBeFalse();
});

it('rejects an endpoint that is not https to a known push service with a Vietnamese 422 (SSRF)', function (string $endpoint) {
    $client = pushDeviceClient();

    foreach ([false, true] as $sync) {
        $this->actingAs($client, 'client')
            ->postJson(pushDeviceUrl('portal'), pushDevicePayload($endpoint, $sync ? ['sync' => 1] : []))
            ->assertUnprocessable()
            ->assertJsonPath('errors.endpoint', [__('push.validation.endpoint')]);
    }

    expect(DB::table('push_subscriptions')->count())->toBe(0);
})->with([
    'địa chỉ siêu dữ liệu đám mây, http' => 'http://169.254.169.254/latest/meta-data/',
    'địa chỉ siêu dữ liệu đám mây, https' => 'https://169.254.169.254/latest/meta-data/',
    'máy chủ lạ' => 'https://evil.example/x',
    'máy nội bộ' => 'https://localhost/x',
    'đuôi giả mạo' => 'https://fcm.googleapis.com.evil.example/x',
    'tên ghép' => 'https://evilfcm.googleapis.com/x',
    // `jmt17.google.com` là tên đầy đủ, không phải `*.google.com`: máy khác của Google vẫn bị từ chối.
    'máy khác của google.com' => 'https://accounts.google.com/x',
    'jmt17 giả mạo' => 'https://jmt17.google.com.evil.example/fcm/send/x',
    'thông tin người dùng trước host' => 'https://fcm.googleapis.com@evil.example/x',
    // Host THẬT là của Apple, nhưng một endpoint push không bao giờ mang phần thông tin người dùng: cho
    // `@` vào phần host là để hai bộ phân tích URL (PHP, cURL) có chỗ đọc khác nhau.
    'thông tin người dùng trước host Apple' => 'https://evil.example@web.push.apple.com/x',
    'gạch chéo ngược' => 'https://evil.example\\@fcm.googleapis.com/x',
    'cổng lạ' => 'https://fcm.googleapis.com:8443/fcm/send/x',
    'không đường dẫn' => 'https://fcm.googleapis.com',
    // Xuống dòng ở CUỐI bị `TrimStrings` (middleware toàn cục) cắt trước khi tới luật — vô hại; ở
    // giữa thì không.
    'xuống dòng ở giữa' => "https://fcm.googleapis.com/fcm/send/x\ny",
    'tab' => "https://fcm.googleapis.com/fcm/send/x\ty",
    'khoảng trắng' => 'https://fcm.googleapis.com/fcm/send/a b',
    'ký tự ngoài ASCII' => 'https://fcm.googleapis.com/fcm/send/hồ-sơ',
    'mảnh #' => 'https://fcm.googleapis.com/fcm/send/x#y',
    'ký tự đại diện không có nhãn' => 'https://push.apple.com/x',
    'nhãn rỗng trước đuôi' => 'https://.push.apple.com/x',
    'apple giả mạo' => 'https://web.push.apple.com.evil.example/x',
    'ftp' => 'ftp://fcm.googleapis.com/x',
    'không có lược đồ' => '//fcm.googleapis.com/x',
]);

/**
 * Xuống dòng ở CUỐI không bao giờ tới được luật qua HTTP (`TrimStrings` cắt trước), nhưng luật còn
 * được dùng cho endpoint lấy từ PHIÊN (lối đăng xuất của Task 6, `ForgetPushDevice::byEndpoint()`), nên
 * `$` của mẫu không được khớp trước một ký tự xuống dòng ở cuối (cờ `D`). Gọi thẳng luật — không có
 * màn hình nào đưa được giá trị này tới nó.
 */
it('does not let a trailing newline slip past the endpoint pattern', function () {
    foreach ([true, false] as $knownHost) {
        $rule = ['endpoint' => RegisterPushDevice::endpointRule($knownHost)];

        expect(Validator::make(['endpoint' => "https://fcm.googleapis.com/fcm/send/x\n"], $rule)->fails())->toBeTrue()
            ->and(Validator::make(['endpoint' => 'https://fcm.googleapis.com/fcm/send/x'], $rule)->fails())->toBeFalse();
    }
});

it('accepts the endpoints of the four push services the plan names', function (string $endpoint) {
    $client = pushDeviceClient();

    $this->actingAs($client, 'client')->postJson(pushDeviceUrl('portal'), pushDevicePayload($endpoint))
        ->assertCreated();

    expect(pushDeviceRow($endpoint))->not->toBeNull();
})->with([
    'Chrome / Android (FCM)' => 'https://fcm.googleapis.com/fcm/send/dXk3:APA91bE-x_Y',
    // Task 10, ĐO trên bản Chromium 153 của Playwright (đăng ký thật, context không ẩn danh,
    // `tools/pwa/acceptance.cjs` mục 3): `pushManager.subscribe()` trả endpoint FCM trên tên máy
    // `jmt17.google.com`, không phải `fcm.googleapis.com` — thiếu dòng này thì trình duyệt đó bấm Bật
    // nhận 422. Google Chrome trên Android chưa đo (bước D1 của danh sách kiểm tra máy thật).
    'Chromium (FCM, jmt17.google.com)' => 'https://jmt17.google.com/fcm/send/eW1x:APA91bF-x_Y',
    'Safari (Apple)' => 'https://web.push.apple.com/QOx7Hk-3aR_eW9',
    'Firefox (Mozilla)' => 'https://updates.push.services.mozilla.com/wpush/v2/gAAAAABm-x_y=',
    'Edge (WNS)' => 'https://wns2-par02p.notify.windows.com/w/?token=BQYAAAD%2bAbC%3d',
    'chữ hoa trong host, cổng 443' => 'https://FCM.googleapis.com:443/fcm/send/abc',
]);

it('rejects an endpoint longer than the column and accepts one exactly as long', function () {
    $client = pushDeviceClient();
    $prefix = 'https://fcm.googleapis.com/fcm/send/';
    $exact = $prefix.str_repeat('a', PushSubscription::ENDPOINT_MAX_LENGTH - strlen($prefix));

    $this->actingAs($client, 'client')
        ->postJson(pushDeviceUrl('portal'), pushDevicePayload($exact.'a'))
        ->assertUnprocessable()
        ->assertJsonPath('errors.endpoint', [__('push.validation.endpoint')]);

    $this->actingAs($client, 'client')->postJson(pushDeviceUrl('portal'), pushDevicePayload($exact))
        ->assertCreated();

    expect(strlen($exact))->toBe(1024)
        ->and(DB::table('push_subscriptions')->count())->toBe(1);
});

it('rejects keys that are not the base64url P-256 point and 16-byte secret a browser sends', function (Closure $keys, string $field) {
    $client = pushDeviceClient();

    $this->actingAs($client, 'client')
        ->postJson(pushDeviceUrl('portal'), ['endpoint' => pushDeviceEndpoint(), 'keys' => $keys()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field => __('push.validation.keys')]);

    expect(DB::table('push_subscriptions')->count())->toBe(0);
})->with([
    'thiếu p256dh' => [fn () => ['auth' => WebPushTestKeys::subscription()['auth']], 'keys.p256dh'],
    'thiếu auth' => [fn () => ['p256dh' => WebPushTestKeys::subscription()['p256dh']], 'keys.auth'],
    'p256dh ngắn' => [fn () => ['p256dh' => substr(WebPushTestKeys::subscription()['p256dh'], 0, 40), 'auth' => WebPushTestKeys::subscription()['auth']], 'keys.p256dh'],
    'p256dh không mở đầu 0x04' => [fn () => ['p256dh' => rtrim(strtr(base64_encode("\x02".random_bytes(64)), '+/', '-_'), '='), 'auth' => WebPushTestKeys::subscription()['auth']], 'keys.p256dh'],
    'p256dh base64 thường (+/)' => [fn () => ['p256dh' => '+/'.substr(WebPushTestKeys::subscription()['p256dh'], 2), 'auth' => WebPushTestKeys::subscription()['auth']], 'keys.p256dh'],
    // Cùng 16 byte, chỉ khác bảng chữ: `+` của base64 thường không phải base64url mà trình duyệt gửi.
    'auth base64 thường (+)' => [fn () => ['p256dh' => WebPushTestKeys::subscription()['p256dh'], 'auth' => substr_replace(WebPushTestKeys::subscription()['auth'], '+', 10, 1)], 'keys.auth'],
    'auth 15 byte' => [fn () => ['p256dh' => WebPushTestKeys::subscription()['p256dh'], 'auth' => rtrim(strtr(base64_encode(random_bytes(15)), '+/', '-_'), '=')], 'keys.auth'],
    'auth là mảng' => [fn () => ['p256dh' => WebPushTestKeys::subscription()['p256dh'], 'auth' => ['x']], 'keys.auth'],
]);

it('accepts padded base64url keys and the legacy aesgcm encoding, and refuses an unknown encoding', function () {
    $client = pushDeviceClient();
    $keys = WebPushTestKeys::subscription();
    $padded = ['p256dh' => $keys['p256dh'].'=', 'auth' => $keys['auth'].'=='];

    $this->actingAs($client, 'client')
        ->postJson(pushDeviceUrl('portal'), ['endpoint' => pushDeviceEndpoint('a'), 'keys' => $padded, 'contentEncoding' => 'aesgcm'])
        ->assertCreated();

    $this->actingAs($client, 'client')
        ->postJson(pushDeviceUrl('portal'), pushDevicePayload(pushDeviceEndpoint('b'), ['contentEncoding' => 'gzip']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('contentEncoding');

    expect(pushDeviceRow(pushDeviceEndpoint('a'))->content_encoding)->toBe('aesgcm')
        ->and(pushDeviceRow(pushDeviceEndpoint('b')))->toBeNull();
});

it('sends a signed-out browser to the sign-in page and creates nothing', function (string $panel) {
    $this->post(pushDeviceUrl($panel), pushDevicePayload(pushDeviceEndpoint()))
        ->assertRedirect("/{$panel}/login");

    $this->postJson(pushDeviceUrl($panel), pushDevicePayload(pushDeviceEndpoint()))
        ->assertUnauthorized();

    $this->delete(pushDeviceUrl($panel), ['endpoint' => pushDeviceEndpoint()])
        ->assertRedirect("/{$panel}/login");

    expect(DB::table('push_subscriptions')->count())->toBe(0);
})->with(['admin', 'portal']);

it('stops a deactivated client at EnsurePortalAccountIsActive: signed out, back to sign-in, no row', function () {
    $client = pushDeviceClient(['is_active' => false]);

    $this->actingAs($client, 'client')
        ->post(pushDeviceUrl('portal'), pushDevicePayload(pushDeviceEndpoint()))
        ->assertRedirect('/portal/login');

    expect(DB::table('push_subscriptions')->count())->toBe(0)
        ->and(auth('client')->check())->toBeFalse();
});

it('sends a client who still has to change the password to that page, no row', function () {
    $client = ClientUser::factory()->create(['must_change_password' => true]);

    $response = $this->actingAs($client, 'client')
        ->postJson(pushDeviceUrl('portal'), pushDevicePayload(pushDeviceEndpoint()));

    expect($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->toContain('/portal/');

    expect(DB::table('push_subscriptions')->count())->toBe(0);
});

/**
 * Brief Task 5 (controller): `EnsureMultiFactorAuthenticationIsEnabled` của Filament chỉ gắn ở TỪNG
 * TRANG, không ở route tự đăng ký. Không dùng thẳng nó ở đây: nó trả lời bằng
 * `redirect()->guest()`, mà với một POST thì `guest()` ghi Referer vào `url.intended` — lượt kiểm
 * `sync=1` chạy trên chính trang "cài 2FA bắt buộc" sẽ ghi đè đường dẫn sâu mà người đó đang trên
 * đường tới.
 */
it('refuses a staff member without 2FA with a 404, creates nothing, and leaves url.intended alone', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->withoutTwoFactor()->create();

    $this->actingAs($staff, 'web')
        ->withSession(['url.intended' => 'http://localhost/admin/matters/5'])
        ->withHeader('Referer', 'http://localhost/admin/multi-factor-authentication/set-up')
        ->postJson(pushDeviceUrl('admin'), pushDevicePayload(pushDeviceEndpoint()))
        ->assertNotFound();

    $this->actingAs($staff, 'web')
        ->postJson(pushDeviceUrl('admin'), pushDevicePayload(pushDeviceEndpoint(), ['sync' => 1]))
        ->assertNotFound();

    expect(DB::table('push_subscriptions')->count())->toBe(0)
        ->and(session('url.intended'))->toBe('http://localhost/admin/matters/5');
});

it('sync=1 never takes over an endpoint that belongs to someone else', function () {
    $owner = pushDeviceClient();
    $other = pushDeviceClient(['client_id' => $owner->client_id]);
    $endpoint = pushDeviceEndpoint('may-chung');
    $owner->updatePushSubscription($endpoint, WebPushTestKeys::subscription()['p256dh'], WebPushTestKeys::subscription()['auth']);
    DB::table('push_subscriptions')->where('endpoint', $endpoint)->update(['last_seen_at' => '2026-09-01 08:00:00']);
    $before = pushDeviceRow($endpoint);

    $this->travel(5)->minutes();

    $this->actingAs($other, 'client')
        ->postJson(pushDeviceUrl('portal'), pushDevicePayload($endpoint, ['sync' => 1]))
        ->assertOk()
        ->assertExactJson(['status' => 'not_owned']);

    $after = pushDeviceRow($endpoint);
    expect((int) $after->id)->toBe((int) $before->id)
        ->and((int) $after->subscribable_id)->toBe($owner->id)
        ->and($after->last_seen_at)->toBe($before->last_seen_at)
        ->and(session()->has(PushSession::endpointKey('client')))->toBeFalse()
        ->and(session(PushSession::checkedKey('client')))->toBeTrue()
        ->and(pushDeviceActivities('push_device_added'))->toHaveCount(0)
        ->and(pushDeviceActivities('push_device_removed'))->toHaveCount(0);
});

it('sync=1 for an endpoint nobody owns answers not_owned and creates nothing', function () {
    $client = pushDeviceClient();

    $this->actingAs($client, 'client')
        ->postJson(pushDeviceUrl('portal'), pushDevicePayload(pushDeviceEndpoint(), ['sync' => 1]))
        ->assertOk()
        ->assertExactJson(['status' => 'not_owned']);

    expect(DB::table('push_subscriptions')->count())->toBe(0);
});

/**
 * Việc sau gộp M12 (làn fu4, mục 9): lượt kiểm `sync=1` bị 422 (endpoint của một máy chủ push chưa có
 * trong `push_hosts` — trình duyệt mới, hay một máy chủ đổi tên) vẫn ĐÁNH DẤU phiên là đã kiểm. Trước
 * đó khoá chỉ được ghi sau khi `check()` qua, nên mỗi lần tải trang `register.js` gửi lại lượt kiểm,
 * đốt hết 10 request/phút, rồi nút Bật nhận 429. Máy chủ ghi TÊN MÁY bị từ chối (để văn phòng biết mà
 * thêm vào `push_hosts`), không bao giờ endpoint (R8: một URL mang quyền gửi).
 *
 * Mutation probe: ghi khoá sau `check()` như cũ → vế `checkedKey` ĐỎ; bỏ dòng log → vế log ĐỎ; log cả
 * endpoint → vế "không endpoint" ĐỎ.
 */
it('sync=1 rejected for an unknown push host still marks the session as checked and logs only the host', function () {
    Log::spy();
    $client = pushDeviceClient();
    $endpoint = 'https://push.may-chu-moi.example/gui/bi-mat-khong-duoc-ghi-log';

    $this->actingAs($client, 'client')
        ->postJson(pushDeviceUrl('portal'), pushDevicePayload($endpoint, ['sync' => 1]))
        ->assertUnprocessable();

    expect(session(PushSession::checkedKey('client')))->toBeTrue()
        ->and(session()->has(PushSession::endpointKey('client')))->toBeFalse()
        ->and(DB::table('push_subscriptions')->count())->toBe(0);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []): bool => ($context['host'] ?? null) === 'push.may-chu-moi.example'
            && ! str_contains($message.json_encode($context), 'bi-mat-khong-duoc-ghi-log'))
        ->once();
});

/** Vế âm của dòng log: endpoint của một máy chủ đã biết không để lại dòng nào. */
it('logs no rejected host for an endpoint on a known push service', function () {
    Log::spy();
    $client = pushDeviceClient();

    $this->actingAs($client, 'client')
        ->postJson(pushDeviceUrl('portal'), pushDevicePayload(pushDeviceEndpoint('may-biet'), ['sync' => 1]))
        ->assertOk();

    Log::shouldNotHaveReceived('warning');
});

it('sync=1 for my own endpoint answers owned, refreshes last_seen_at and remembers the endpoint for this guard', function () {
    $client = pushDeviceClient();
    $endpoint = pushDeviceEndpoint('cua-toi');
    $client->updatePushSubscription($endpoint);

    $this->travelTo(now()->setDate(2026, 10, 3)->setTime(9, 15));

    $this->actingAs($client, 'client')
        ->postJson(pushDeviceUrl('portal'), pushDevicePayload($endpoint, ['sync' => 1]))
        ->assertOk()
        ->assertExactJson(['status' => 'owned']);

    expect(substr((string) pushDeviceRow($endpoint)->last_seen_at, 0, 16))->toBe('2026-10-03 09:15')
        ->and(session(PushSession::endpointKey('client')))->toBe($endpoint)
        ->and(session(PushSession::checkedKey('client')))->toBeTrue()
        ->and(pushDeviceActivities('push_device_added'))->toHaveCount(0);
});

it('pressing Bật (no sync) moves the endpoint to the new person and deletes the previous owner row (R6)', function () {
    $previous = pushDeviceClient();
    $next = pushDeviceClient();
    $endpoint = pushDeviceEndpoint('may-chung');
    $previous->updatePushSubscription($endpoint);
    $previousRowId = (int) pushDeviceRow($endpoint)->id;
    DB::table('push_subscriptions')->where('id', $previousRowId)->update(['device_label' => 'Android · Chrome']);

    $this->actingAs($next, 'client')
        ->withHeader('User-Agent', PUSH_DEVICE_IPHONE_UA)
        ->postJson(pushDeviceUrl('portal'), pushDevicePayload($endpoint))
        ->assertCreated();

    $row = pushDeviceRow($endpoint);
    expect((int) $row->subscribable_id)->toBe($next->id)
        ->and(DB::table('push_subscriptions')->where('id', $previousRowId)->exists())->toBeFalse()
        ->and($previous->pushSubscriptions()->count())->toBe(0);

    $removed = pushDeviceActivities('push_device_removed');
    expect($removed)->toHaveCount(1)
        ->and($removed[0]->subject_id)->toBe($previous->id)
        ->and($removed[0]->causer_id)->toBe($next->id)
        ->and($removed[0]->properties->toArray())->toBe(['device_label' => 'Android · Chrome'])
        ->and(pushDeviceActivities('push_device_added'))->toHaveCount(1);
});

it('pressing Bật again on my own device updates it in place and writes no second added row', function () {
    $client = pushDeviceClient();
    $endpoint = pushDeviceEndpoint('lan-hai');

    $this->actingAs($client, 'client')->postJson(pushDeviceUrl('portal'), pushDevicePayload($endpoint))->assertCreated();
    $first = pushDeviceRow($endpoint);

    $newKeys = WebPushTestKeys::subscription();
    $this->actingAs($client, 'client')
        ->postJson(pushDeviceUrl('portal'), ['endpoint' => $endpoint, 'keys' => $newKeys])
        ->assertCreated();

    $second = pushDeviceRow($endpoint);
    expect((int) $second->id)->toBe((int) $first->id)
        ->and($second->public_key)->toBe($newKeys['p256dh'])
        ->and(pushDeviceActivities('push_device_added'))->toHaveCount(1);
});

it('cannot remove a device of someone else by guessing its endpoint: 404, row stays', function () {
    $owner = pushDeviceClient();
    $guesser = pushDeviceClient(['client_id' => $owner->client_id]);
    $endpoint = pushDeviceEndpoint('cua-nguoi-khac');
    $owner->updatePushSubscription($endpoint);

    $this->actingAs($guesser, 'client')
        ->deleteJson(pushDeviceUrl('portal'), ['endpoint' => $endpoint])
        ->assertNotFound();

    expect(pushDeviceRow($endpoint))->not->toBeNull()
        ->and(pushDeviceActivities('push_device_removed'))->toHaveCount(0);
});

it('removes my own device by endpoint, audits the label, and forgets it from the session', function () {
    $staff = User::factory()->withRole(Role::Accountant)->create();
    $endpoint = pushDeviceEndpoint('go-di');

    $this->actingAs($staff, 'web')
        ->withHeader('User-Agent', PUSH_DEVICE_IPHONE_UA)
        ->postJson(pushDeviceUrl('admin'), pushDevicePayload($endpoint))
        ->assertCreated();
    expect(session(PushSession::endpointKey('web')))->toBe($endpoint);

    $this->actingAs($staff, 'web')
        ->deleteJson(pushDeviceUrl('admin'), ['endpoint' => $endpoint])
        ->assertNoContent();

    $removed = pushDeviceActivities('push_device_removed');
    expect(pushDeviceRow($endpoint))->toBeNull()
        ->and($removed)->toHaveCount(1)
        ->and($removed[0]->subject_type)->toBe('user')
        ->and($removed[0]->properties->toArray())->toBe(['device_label' => 'iPhone · Safari'])
        ->and(session()->has(PushSession::endpointKey('web')))->toBeFalse()
        ->and(Activity::query()->get()->toJson())->not->toContain('fcm.googleapis.com');
});

it('rejects a malformed endpoint on removal before it reaches the database', function () {
    $client = pushDeviceClient();

    $this->actingAs($client, 'client')
        ->deleteJson(pushDeviceUrl('portal'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/hồ-sơ'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.endpoint', [__('push.validation.endpoint')]);
});

it('throttles at 10 requests a minute per account: the 11th is 429, another account is not affected', function () {
    $first = pushDeviceClient();
    $second = pushDeviceClient();
    $payload = pushDevicePayload(pushDeviceEndpoint(), ['sync' => 1]);

    for ($i = 1; $i <= 10; $i++) {
        $this->actingAs($first, 'client')->postJson(pushDeviceUrl('portal'), $payload)->assertOk();
    }

    $this->actingAs($first, 'client')->postJson(pushDeviceUrl('portal'), $payload)->assertTooManyRequests();
    $this->actingAs($first, 'client')->deleteJson(pushDeviceUrl('portal'), ['endpoint' => pushDeviceEndpoint()])->assertTooManyRequests();

    $this->actingAs($second, 'client')->postJson(pushDeviceUrl('portal'), $payload)->assertOk();
});

it('counts a staff account and a client account that share an id separately', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();
    $client = pushDeviceClient();
    // Không bảng nào tham chiếu `client_users.id` lúc này — đổi id để hai tài khoản trùng số.
    DB::table('client_users')->where('id', $client->id)->update(['id' => $staff->id]);
    $client = ClientUser::query()->findOrFail($staff->id);
    $payload = pushDevicePayload(pushDeviceEndpoint(), ['sync' => 1]);

    for ($i = 1; $i <= 10; $i++) {
        $this->actingAs($client, 'client')->postJson(pushDeviceUrl('portal'), $payload)->assertOk();
    }

    $this->actingAs($client, 'client')->postJson(pushDeviceUrl('portal'), $payload)->assertTooManyRequests();
    $this->actingAs($staff, 'web')->postJson(pushDeviceUrl('admin'), $payload)->assertOk();
});

it('keeps the push feature off without VAPID keys: no registration, but removal still works', function () {
    config(['webpush.vapid.public_key' => '']);
    $client = pushDeviceClient();
    $endpoint = pushDeviceEndpoint('khong-khoa');
    $client->updatePushSubscription($endpoint);

    $this->actingAs($client, 'client')->postJson(pushDeviceUrl('portal'), pushDevicePayload(pushDeviceEndpoint('moi')))
        ->assertNotFound();
    $this->actingAs($client, 'client')->postJson(pushDeviceUrl('portal'), pushDevicePayload($endpoint, ['sync' => 1]))
        ->assertNotFound();

    expect(pushDeviceRow(pushDeviceEndpoint('moi')))->toBeNull();

    $this->actingAs($client, 'client')->deleteJson(pushDeviceUrl('portal'), ['endpoint' => $endpoint])
        ->assertNoContent();

    expect(pushDeviceRow($endpoint))->toBeNull();
});

/**
 * Bẫy khoá phiên (brief Task 6): hai service worker (`/admin`, `/portal`) = hai endpoint trên cùng
 * một trình duyệt, nhưng hai panel chung MỘT phiên. Khoá phiên theo guard, để đăng xuất panel này
 * không gỡ nhầm (hay bỏ sót) thiết bị của panel kia.
 */
it('keeps one session key per guard when both panels register in the same browser session', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();
    $client = pushDeviceClient();

    $this->actingAs($staff, 'web')->actingAs($client, 'client');

    $this->postJson(pushDeviceUrl('admin'), pushDevicePayload(pushDeviceEndpoint('admin-sw')))->assertCreated();
    $this->postJson(pushDeviceUrl('portal'), pushDevicePayload(pushDeviceEndpoint('portal-sw')))->assertCreated();

    expect(session(PushSession::endpointKey('web')))->toBe(pushDeviceEndpoint('admin-sw'))
        ->and(session(PushSession::endpointKey('client')))->toBe(pushDeviceEndpoint('portal-sw'))
        ->and($staff->pushSubscriptions()->pluck('endpoint')->all())->toBe([pushDeviceEndpoint('admin-sw')])
        ->and($client->pushSubscriptions()->pluck('endpoint')->all())->toBe([pushDeviceEndpoint('portal-sw')]);
});

/**
 * Rà soát Task 4, Minor 3: hai lượt Bật đồng thời cùng một endpoint — cả hai không thấy dòng nào,
 * lượt sau vấp UNIQUE. Lượt thử lại đầu tiên thấy dòng của lượt trước và đi đúng nhánh "đã có".
 * Mô phỏng bằng một dòng chen vào ngay trước câu INSERT (trong cùng transaction, nên lần lỗi cuộn lại
 * cả dòng chen).
 */
it('retries once when a concurrent registration of the same endpoint wins the unique index', function () {
    $client = pushDeviceClient();
    $rival = pushDeviceClient();
    $endpoint = pushDeviceEndpoint('dong-thoi');
    $raced = 0;

    Event::listen('eloquent.creating: '.PushSubscription::class, function () use (&$raced, $rival, $endpoint): void {
        if ($raced++ === 0) {
            DB::table('push_subscriptions')->insert([
                'subscribable_type' => 'client_user', 'subscribable_id' => $rival->id,
                'endpoint' => $endpoint, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });

    $this->actingAs($client, 'client')->postJson(pushDeviceUrl('portal'), pushDevicePayload($endpoint))
        ->assertCreated();

    expect((int) pushDeviceRow($endpoint)->subscribable_id)->toBe($client->id)
        ->and(DB::table('push_subscriptions')->count())->toBe(1);
});

it('answers 409 without the endpoint anywhere when the unique index keeps losing', function () {
    $client = pushDeviceClient();
    $rival = pushDeviceClient();
    $endpoint = pushDeviceEndpoint('mai-tranh');

    Event::listen('eloquent.creating: '.PushSubscription::class, function () use ($rival, $endpoint): void {
        DB::table('push_subscriptions')->insert([
            'subscribable_type' => 'client_user', 'subscribable_id' => $rival->id,
            'endpoint' => $endpoint, 'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    $response = $this->actingAs($client, 'client')->postJson(pushDeviceUrl('portal'), pushDevicePayload($endpoint));

    $response->assertStatus(409)->assertExactJson(['status' => 'conflict']);
    expect($response->getContent())->not->toContain('fcm.googleapis.com')
        ->and(DB::table('push_subscriptions')->count())->toBe(0);
});
