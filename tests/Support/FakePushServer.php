<?php

namespace Tests\Support;

use App\Models\ClientUser;
use App\Models\User;
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Máy chủ push GIẢ cho test thông báo đẩy (M12 R6, Task 7) — giả ở tầng HTTP, KHÔNG thay
 * `Minishlink\WebPush\WebPush` bằng một bản giả: kênh của gói vẫn mã hoá payload (aes128gcm), ký
 * VAPID, gửi qua client mà `App\Providers\WebPushServiceProvider` dựng, rồi đọc mã trả lời thật —
 * `ReportHandler` xoá đăng ký 404/410 và phát `NotificationSent`/`NotificationFailed` như ở máy chủ
 * thật.
 *
 * # Đo trước (Task 7, kế hoạch R6 "Chưa đo")
 *
 * `Http::fake()` CHẶN ĐƯỢC request của `WebPushChannel`, với MỘT điều kiện thứ tự: bộ chặn phải
 * được đăng ký TRƯỚC lần đầu kênh được phân giải trong app của test. Lý do (đọc trên Laravel 13.31):
 *  - client Guzzle của kênh dựng một lần, lúc container phân giải `WebPushChannel`, với chồng
 *    handler `Http::buildHandlerStack()` — hàm đó tạo một `PendingRequest` mới, CHỤP collection
 *    `stubCallbacks` và cờ `preventStrayRequests` của factory lúc ấy;
 *  - `ChannelManager` giữ driver đã dựng suốt đời app, nên kênh (và client của nó) không bao giờ
 *    dựng lại trong cùng một test;
 *  - `Http::fake()` gọi sau đó GÁN cho factory một collection MỚI (`merge()` trả bản mới), và
 *    `Http::preventStrayRequests()` gọi sau đó chỉ đổi cờ của factory — client đã dựng không thấy
 *    cả hai: request đi ra mạng thật.
 * Đo bằng test "a fake registered after the channel was built does not intercept" của
 * `tests/Feature/Push/SendPushAlertTest.php` (endpoint `https://127.0.0.1:9/…` — cổng đóng, lỗi kết
 * nối ngay, không request nào ra khỏi máy). Mỗi test Pest có app riêng, nên gọi {@see self::start()}
 * ở `beforeEach` (hay đầu test, trước mọi lần gửi) là đủ.
 *
 * {@see self::start()} bật luôn `Http::preventStrayRequests()`: một request không khớp bộ chặn nào
 * (không thể xảy ra với bộ chặn bắt-tất-cả ở đây, nhưng là lưới cho test sau này đổi bộ chặn) ném
 * lỗi thay vì đi ra máy chủ push thật.
 *
 * Endpoint mặc định trả 201; truyền `[endpoint => mã]` hay `[endpoint => Closure]` để đổi từng máy.
 */
final class FakePushServer
{
    /** @var list<array{endpoint: string, ttl: ?string, urgency: ?string, timeout: mixed}> */
    public array $requests = [];

    /**
     * @param  array<string, int|Closure(Request, array<string, mixed>): mixed>  $responses
     */
    public static function start(array $responses = []): self
    {
        $server = new self;

        Http::preventStrayRequests();
        Http::fake(function (Request $request, array $options) use ($server, $responses) {
            $server->requests[] = [
                'endpoint' => $request->url(),
                'ttl' => $request->header('TTL')[0] ?? null,
                'urgency' => $request->header('Urgency')[0] ?? null,
                'timeout' => $options['timeout'] ?? null,
            ];

            $response = $responses[$request->url()] ?? 201;

            return $response instanceof Closure ? $response($request, $options) : Http::response('', $response);
        });

        return $server;
    }

    /** Endpoint dạng FCM, trong danh sách máy chủ push đã biết (`config('vkcrm.pwa.push_hosts')`). */
    public static function endpoint(string $suffix): string
    {
        return 'https://fcm.googleapis.com/fcm/send/kiem-thu-'.$suffix;
    }

    /**
     * Một máy đã bật của `$owner`, khoá thật như trình duyệt gửi — đi qua trait của gói như nút Bật
     * (`RegisterPushDevice`). Trả endpoint.
     */
    public static function device(User|ClientUser $owner, string $suffix): string
    {
        $endpoint = self::endpoint($suffix);
        $keys = WebPushTestKeys::subscription();
        $owner->updatePushSubscription($endpoint, $keys['p256dh'], $keys['auth'], 'aes128gcm');

        return $endpoint;
    }

    /** @return list<string> */
    public function endpoints(): array
    {
        return array_column($this->requests, 'endpoint');
    }
}
