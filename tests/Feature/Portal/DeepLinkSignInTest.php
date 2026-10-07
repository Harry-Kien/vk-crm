<?php

use App\Filament\Portal\Pages\Auth\ChangePassword;
use App\Filament\Portal\Pages\Auth\Login;
use App\Filament\Portal\Pages\MatterProgress;
use App\Http\Middleware\RequirePortalPasswordChange;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Notifications\Client\SendLoginCode;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\WebPushTestKeys;

/*
|--------------------------------------------------------------------------
| M12 Task 6 (R9) — chạm một thông báo khi phiên đã hết: về ĐÚNG trang hồ sơ sau khi đăng nhập
|--------------------------------------------------------------------------
|
| Hết phiên mà không đăng xuất thì đăng ký push vẫn còn, có chủ đích: đó là lúc push có ích nhất.
| Chạm thông báo mở `/portal/ho-so/{id}` → chưa đăng nhập → mật khẩu → mã OTP qua email → (đổi
| mật khẩu lần đầu, nếu văn phòng vừa đặt lại) → trang hồ sơ đó, không phải trang chủ cổng.
|
| `$this->get()` và `$this->livewire()` dùng CHUNG một phiên trong cùng một test, nên URL mà
| middleware cất ở bước đầu còn nguyên khi bước cuối đọc nó — đúng đường trình duyệt thật đi qua
| (cùng khuôn `AdminTwoFactorRequiredTest`, "login → mã 2FA → quay lại đúng URL sâu ban đầu").
*/

beforeEach(function () {
    Notification::fake();
    Filament::setCurrentPanel('portal');

    $this->client = Client::factory()->create();
    $this->matter = Matter::factory()->for($this->client)->create([
        'is_published_to_portal' => true,
        'title' => 'Hồ sơ thừa kế nhà đất Bình Dương',
    ]);
    $this->deepLink = MatterProgress::getUrl(['record' => $this->matter->getKey()], panel: 'portal');
});

/** Mật khẩu rồi mã OTP vừa gửi tới hộp thư — trả component sau lần bấm cuối. */
function deepLinkSignIn(ClientUser $user)
{
    $component = test()->livewire(Login::class)
        ->set('data.email', $user->email)
        ->set('data.password', 'password')
        ->call('authenticate')
        ->assertHasNoFormErrors();

    // Vẫn ở bước mã — chưa đăng nhập thật.
    expect(auth('client')->check())->toBeFalse();

    $code = Notification::sent($user, SendLoginCode::class)->last()->code();

    return $component
        ->set('data.multiFactor.email_code.code', $code)
        ->call('authenticate')
        ->assertHasNoFormErrors();
}

it('takes a client from a deep link, through password and one time code, to that matter page', function () {
    $user = ClientUser::factory()->activated()->create(['client_id' => $this->client->id]);

    $this->get($this->deepLink)->assertRedirect(Filament::getPanel('portal')->getLoginUrl());

    deepLinkSignIn($user)->assertRedirect($this->deepLink);

    $this->get($this->deepLink)->assertOk()->assertSee('Hồ sơ thừa kế nhà đất Bình Dương');
});

/*
| Văn phòng vừa đặt lại mật khẩu (`must_change_password` bật lại): `LoginResponse` đã TIÊU
| `url.intended` để về trang hồ sơ, `RequirePortalPasswordChange` chặn trang đó để đòi đổi mật khẩu
| — URL phải sống qua bước này.
*/
it('keeps the deep link through the forced password change too', function () {
    $user = ClientUser::factory()->create([
        'client_id' => $this->client->id,
        'must_change_password' => true,
    ]);

    $this->get($this->deepLink)->assertRedirect(Filament::getPanel('portal')->getLoginUrl());

    deepLinkSignIn($user)->assertRedirect($this->deepLink);

    $this->get($this->deepLink)->assertRedirect(ChangePassword::getUrl(panel: 'portal'));
    $this->get(ChangePassword::getUrl(panel: 'portal'))->assertOk();

    $this->livewire(ChangePassword::class)
        ->set('data.password', 'mat-khau-moi-cua-khach-2026')
        ->set('data.passwordConfirmation', 'mat-khau-moi-cua-khach-2026')
        ->call('changePassword')
        ->assertHasNoErrors()
        ->assertRedirect($this->deepLink);

    // Đích chỉ dùng một lần: lần đổi mật khẩu sau (văn phòng đặt lại lần nữa) không bị kéo về đây.
    expect(session()->has(RequirePortalPasswordChange::INTENDED_URL_KEY))->toBeFalse();

    $this->get($this->deepLink)->assertOk()->assertSee('Hồ sơ thừa kế nhà đất Bình Dương');
});

/*
| Trên trang đổi mật khẩu, `register.js` vẫn gửi lượt kiểm `sync=1` (`POST …/push/subscriptions`),
| và cổng đổi mật khẩu chuyển hướng cả request đó. Chỉ một lần mở TRANG (`GET`) được ghi làm đích
| sau khi đổi: ghi một `POST` thì sau khi đổi mật khẩu khách bị đưa tới một route chỉ nhận POST.
*/
it('does not let a background POST overwrite the page the client was going to', function () {
    config(WebPushTestKeys::config());

    $user = ClientUser::factory()->create([
        'client_id' => $this->client->id,
        'must_change_password' => true,
    ]);

    $this->actingAs($user, 'client');

    $this->get($this->deepLink)->assertRedirect(ChangePassword::getUrl(panel: 'portal'));

    $this->postJson('/portal/push/subscriptions', [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/doi-mat-khau:APA91bT6',
        'keys' => WebPushTestKeys::subscription(),
        'sync' => 1,
    ])->assertRedirect(ChangePassword::getUrl(panel: 'portal'));

    $this->livewire(ChangePassword::class)
        ->set('data.password', 'mat-khau-moi-cua-khach-2026')
        ->set('data.passwordConfirmation', 'mat-khau-moi-cua-khach-2026')
        ->call('changePassword')
        ->assertRedirect($this->deepLink);
});

/*
| Khách đang mở sẵn trang hồ sơ thì văn phòng đặt lại mật khẩu: cú bấm kế tiếp (request cập nhật
| Livewire) bị cổng chuyển sang trang đổi mật khẩu. Request giả của đường ống bền mang phương thức
| `GET` và đường dẫn của trang hồ sơ — đổi xong khách quay lại đúng trang đó.
*/
it('returns the client to the page they had open when the gate catches a livewire update', function () {
    $user = ClientUser::factory()->activated()->create(['client_id' => $this->client->id]);

    $this->actingAs($user, 'client');

    preg_match_all('/wire:snapshot="([^"]*)"/', $this->get($this->deepLink)->assertOk()->getContent(), $matches);
    $snapshot = collect($matches[1])
        ->map(fn (string $encoded): string => html_entity_decode($encoded, ENT_QUOTES))
        ->first(fn (string $json): bool => (json_decode($json, true)['memo']['name'] ?? null) === MatterProgress::class);
    expect($snapshot)->not->toBeNull();

    $user->forceFill(['must_change_password' => true])->save();
    $this->actingAs($user->fresh(), 'client');

    $this->withHeaders(['X-Livewire' => '1'])
        ->postJson(Livewire::getUpdateUri(), [
            'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]],
        ])
        ->assertRedirect(ChangePassword::getUrl(panel: 'portal'));

    $this->livewire(ChangePassword::class)
        ->set('data.password', 'mat-khau-moi-cua-khach-2026')
        ->set('data.passwordConfirmation', 'mat-khau-moi-cua-khach-2026')
        ->call('changePassword')
        ->assertRedirect($this->deepLink);
});

/** Không có đích nào được ghi (khách tự mở trang đổi mật khẩu từ đầu): về trang chủ cổng như trước. */
it('sends the client to the portal home after the password change when there was no page to go back to', function () {
    $user = ClientUser::factory()->create([
        'client_id' => $this->client->id,
        'must_change_password' => true,
    ]);

    $this->actingAs($user, 'client');

    $this->livewire(ChangePassword::class)
        ->set('data.password', 'mat-khau-moi-cua-khach-2026')
        ->set('data.passwordConfirmation', 'mat-khau-moi-cua-khach-2026')
        ->call('changePassword')
        ->assertRedirect(Filament::getPanel('portal')->getUrl());
});
