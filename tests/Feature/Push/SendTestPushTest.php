<?php

use App\Enums\OutboundChannel;
use App\Enums\OutboundStatus;
use App\Enums\PushTopic;
use App\Enums\Role;
use App\Models\ClientUser;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Notifications\PushAlert;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakePushServer;
use Tests\Support\WebPushTestKeys;

/*
|--------------------------------------------------------------------------
| M12 Task 7 (chuyển từ Task 5, phán quyết (b) của controller) — nút "Gửi thử"
|--------------------------------------------------------------------------
|
| Trang "Thông báo trên điện thoại" của hai panel có một `<form method="post">` tới
| `POST /{panel}/push/test` (route trong `authenticatedRoutes()`, cùng throttle và — ở admin — cùng cổng
| 2FA với hai route đăng ký). Không JavaScript: nút là một biểu mẫu thường. Plan Task 5: "Nút Gửi thử
| xếp đúng một `PushAlert` cho chính người bấm." Mọi ca đi qua HTTP.
*/

beforeEach(function () {
    config(WebPushTestKeys::config());
});

/** Thân các toast Filament đã flash cho request kế tiếp. */
function pushTestToasts(): array
{
    return collect(session()->get('filament.notifications', []))->pluck('title')->all();
}

/**
 * Plan Task 5 (chuyển sang Task 7): đúng MỘT `PushAlert` (một job cho mọi máy của người đó), cho
 * CHÍNH người bấm — không cho người khác của cùng khách hàng, kể cả khi họ có máy. Nội dung là chủ đề
 * `push.test` (R11: câu chung, về trang thiết bị của panel người bấm).
 *
 * Mutation probe: `SendTestPush` gửi cho mọi tài khoản cổng của cùng khách hàng → ĐỎ.
 */
it('queues exactly one PushAlert for the client who pressed the button, and for nobody else', function () {
    Notification::fake();
    $me = ClientUser::factory()->activated()->create();
    $sibling = ClientUser::factory()->activated()->create(['client_id' => $me->client_id]);
    FakePushServer::device($me, 'cua-toi-1');
    FakePushServer::device($me, 'cua-toi-2');
    FakePushServer::device($sibling, 'cua-nguoi-khac');

    $this->actingAs($me, 'client')
        ->post('/portal/push/test')
        ->assertRedirect('/portal/thong-bao-dien-thoai');

    Notification::assertSentToTimes($me, PushAlert::class, 1);
    Notification::assertNotSentTo($sibling, PushAlert::class);
    Notification::assertSentTo($me, PushAlert::class, function (PushAlert $alert) use ($me): bool {
        $payload = $alert->toWebPush($me, $alert)->toArray();

        return $alert->message->topic === PushTopic::Test->value
            && $payload['body'] === __('push.alerts.test')
            && $payload['data']['url'] === '/portal/thong-bao-dien-thoai';
    });
    expect(pushTestToasts())->toBe([__('push.test.sent', ['count' => 2])]);
});

it('queues exactly one PushAlert for the staff member who pressed it, an accountant included', function () {
    Notification::fake();
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    FakePushServer::device($accountant, 'ke-toan');
    FakePushServer::device($lawyer, 'luat-su');

    $this->actingAs($accountant, 'web')
        ->post('/admin/push/test')
        ->assertRedirect('/admin/thong-bao-dien-thoai');

    Notification::assertSentToTimes($accountant, PushAlert::class, 1);
    Notification::assertNotSentTo($lawyer, PushAlert::class);
    Notification::assertSentTo($accountant, PushAlert::class, fn (PushAlert $alert): bool => $alert->toWebPush($accountant, $alert)->toArray()['data']['url'] === '/admin/thong-bao-dien-thoai');
});

/** Không máy nào: không job, một câu nói thẳng điều đó (không "đã gửi"). */
it('queues nothing and says so when the person has no device', function () {
    Notification::fake();
    $me = ClientUser::factory()->activated()->create();

    $this->actingAs($me, 'client')
        ->post('/portal/push/test')
        ->assertRedirect('/portal/thong-bao-dien-thoai');

    Notification::assertNothingSent();
    expect(pushTestToasts())->toBe([__('push.test.none')]);
});

/** R7: máy chủ chưa có khoá VAPID — route trả 404 như `POST …/push/subscriptions`, không gì được xếp. */
it('answers 404 and queues nothing when the server has no VAPID keys', function () {
    Notification::fake();
    $me = ClientUser::factory()->activated()->create();
    FakePushServer::device($me, 'khong-khoa');
    config(['webpush.vapid.public_key' => '', 'webpush.vapid.private_key' => '']);

    $this->actingAs($me, 'client')->post('/portal/push/test')->assertNotFound();

    Notification::assertNothingSent();
});

it('sends a guest to the sign-in page and queues nothing', function () {
    Notification::fake();

    $this->post('/portal/push/test')->assertRedirect('/portal/login');

    Notification::assertNothingSent();
});

/**
 * Đường thật tới máy chủ push (bản giả HTTP, hàng đợi `sync`): mỗi máy một dòng nhật ký `push.test`,
 * không bản ghi liên quan — nên chỉ admin thấy dòng đó trong nhật ký thư.
 */
it('pushes to every device of the person and logs one push.test row per device, without a related record', function () {
    $server = FakePushServer::start();
    $me = ClientUser::factory()->activated()->create();
    $one = FakePushServer::device($me, 'that-1');
    $two = FakePushServer::device($me, 'that-2');

    $this->actingAs($me, 'client')->post('/portal/push/test')->assertRedirect();

    $rows = OutboundMessage::query()->withoutGlobalScopes()->where('channel', OutboundChannel::Push)->get();

    expect($server->endpoints())->toEqualCanonicalizing([$one, $two])
        ->and($rows)->toHaveCount(2)
        ->and($rows->pluck('template')->unique()->all())->toBe(['push.test'])
        ->and($rows->pluck('status')->unique()->all())->toBe([OutboundStatus::Sent])
        ->and($rows->pluck('related_type')->unique()->all())->toBe([null])
        ->and($rows->pluck('recipient')->unique()->all())->toBe(['client_user:'.$me->id]);
});

/**
 * Nút trên trang: một biểu mẫu POST tới đúng route của panel, có token CSRF, chỉ khi người xem có ít
 * nhất một máy (gửi thử tới không máy nào là một nút chết) và máy chủ có khoá.
 *
 * Mutation probe: bỏ điều kiện `count($devices) > 0` ở view → ĐỎ.
 */
it('offers the send-test button as a plain POST form only when the viewer has a device', function (string $panel, string $guard, Closure $makeViewer) {
    $viewer = $makeViewer();

    $without = $this->actingAs($viewer, $guard)->get("/{$panel}/thong-bao-dien-thoai")->assertOk()->getContent();

    FakePushServer::device($viewer, 'nut-'.$panel);
    $with = $this->actingAs($viewer, $guard)->get("/{$panel}/thong-bao-dien-thoai")->assertOk()->getContent();

    expect($without)->not->toContain('data-vk-push-test')
        ->and($with)->toContain('data-vk-push-test')
        ->toContain('action="'.url("/{$panel}/push/test").'"')
        ->toContain('method="post"')
        ->toContain('name="_token"')
        ->toContain(__('push.test.button'));

    config(['webpush.vapid.public_key' => '', 'webpush.vapid.private_key' => '']);

    expect($this->actingAs($viewer, $guard)->get("/{$panel}/thong-bao-dien-thoai")->assertOk()->getContent())
        ->not->toContain('data-vk-push-test');
})->with([
    'portal' => ['portal', 'client', fn () => ClientUser::factory()->activated()->create()],
    'admin' => ['admin', 'web', fn () => User::factory()->withRole(Role::Lawyer)->create()],
]);
