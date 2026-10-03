<?php

use App\Models\ClientUser;
use App\Providers\WebPushServiceProvider;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;
use NotificationChannels\WebPush\WebPushServiceProvider as PackageWebPushServiceProvider;
use Tests\Support\WebPushTestKeys;

/*
|--------------------------------------------------------------------------
| M12 Task 4 — gói `laravel-notification-channels/webpush` (R6), cấu hình và khoá VAPID (R7)
|--------------------------------------------------------------------------
|
| Hạn 10 giây cho mỗi request ra máy chủ push (R12) được đo trên ĐÚNG đường gửi thật: kênh của gói
| mã hoá payload, ký VAPID, rồi gửi qua client HTTP mà container đưa cho nó — `Http::fake()` đứng ở
| cuối chồng handler của client đó và đọc lại các tuỳ chọn Guzzle mà request thật sự mang theo.
*/

it('nạp gói đúng một lần, qua provider của dự án (gói gốc bị loại khỏi tự dò)', function () {
    $loaded = array_keys(app()->getLoadedProviders());

    expect($loaded)->toContain(WebPushServiceProvider::class)
        ->and($loaded)->not->toContain(PackageWebPushServiceProvider::class)
        ->and(Artisan::all())->toHaveKey('webpush:vapid');
});

it('config/webpush.php giữ đủ khoá cấp một của tệp gói, vì mergeConfigFrom gộp nông', function () {
    $vendor = require base_path('vendor/laravel-notification-channels/webpush/config/webpush.php');
    $published = require base_path('config/webpush.php');

    expect(array_keys($published))->toEqualCanonicalizing(array_keys($vendor))
        ->and(array_keys($published['vapid']))->toEqualCanonicalizing(array_keys($vendor['vapid']));
});

it('bảng và kết nối của đăng ký là của chính ứng dụng, không đọc biến môi trường riêng', function () {
    expect(config('webpush.table_name'))->toBe('push_subscriptions')
        ->and(config('webpush.database_connection'))->toBeNull()
        ->and(config('webpush.vapid.pem_file'))->toBeNull();
});

it('một request ra máy chủ push mang đúng hạn webpush.client_options.timeout = 10 giây', function () {
    config(WebPushTestKeys::config());

    $captured = null;
    Http::fake(function ($request, array $options) use (&$captured) {
        $captured = ['url' => $request->url(), 'options' => $options];

        return Http::response('', 201);
    });

    $endpoint = 'https://fcm.googleapis.com/fcm/send/kiem-thu-han-gui';
    $keys = WebPushTestKeys::subscription();
    $account = ClientUser::factory()->create();
    $account->updatePushSubscription($endpoint, $keys['p256dh'], $keys['auth'], 'aes128gcm');

    $notification = new class extends Notification
    {
        public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
        {
            return (new WebPushMessage)->title('Luật Vũ Khang')->body('Kiểm thử hạn gửi.');
        }
    };

    app(WebPushChannel::class)->send($account->fresh(), $notification);

    expect(config('webpush.client_options.timeout'))->toBe(10)
        ->and($captured)->not->toBeNull()
        ->and($captured['url'])->toBe($endpoint)
        ->and($captured['options']['timeout'] ?? null)->toBe(10);
});

it('.env.example khai ba biến VAPID dạng KEY= trống — chép thành .env rồi webpush:vapid ghi được khoá vào đó', function () {
    $example = (string) file_get_contents(base_path('.env.example'));

    foreach (['VAPID_SUBJECT', 'VAPID_PUBLIC_KEY', 'VAPID_PRIVATE_KEY'] as $name) {
        expect($example)->toMatch('/^'.$name.'=$/m');
    }

    $directory = sys_get_temp_dir().'/vk-vapid-'.bin2hex(random_bytes(6));
    mkdir($directory);
    copy(base_path('.env.example'), $directory.'/.env');

    $originalPath = app()->environmentPath();
    app()->useEnvironmentPath($directory);

    try {
        config(['webpush.vapid.public_key' => '', 'webpush.vapid.private_key' => '']);

        expect(Artisan::call('webpush:vapid'))->toBe(0);

        $written = (string) file_get_contents($directory.'/.env');
    } finally {
        app()->useEnvironmentPath($originalPath);
        @unlink($directory.'/.env');
        @rmdir($directory);
    }

    preg_match('/^VAPID_PUBLIC_KEY=(\S+)$/m', $written, $public);
    preg_match('/^VAPID_PRIVATE_KEY=(\S+)$/m', $written, $private);

    expect($public[1] ?? null)->not->toBeNull()
        ->and(strlen(base64_decode(strtr($public[1], '-_', '+/'))))->toBe(65)
        ->and(strlen(base64_decode(strtr($private[1], '-_', '+/'))))->toBe(32)
        ->and($written)->toMatch('/^VAPID_SUBJECT=$/m');
});
