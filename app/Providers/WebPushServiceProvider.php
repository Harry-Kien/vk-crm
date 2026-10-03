<?php

namespace App\Providers;

use App\Http\Controllers\Pwa\PushSubscriptionController;
use GuzzleHttp\Client;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use NotificationChannels\WebPush\WebPushServiceProvider as PackageWebPushServiceProvider;
use Psr\Http\Client\ClientInterface;

/**
 * Provider của `laravel-notification-channels/webpush` (M12 R6), nạp ở `bootstrap/providers.php`
 * THAY cho provider gốc — gói được loại khỏi tự dò (`composer.json`, `extra.laravel.dont-discover`),
 * nên lệnh `webpush:vapid`, cấu hình và chỗ publish migration vẫn có, đúng một lần.
 *
 * Chỉ đổi MỘT việc: client HTTP gửi tới máy chủ push. Bản gốc (13.0.1,
 * `WebPushServiceProvider::webPushClient()`) dựng nó trên Laravel ≥ 13.13 bằng
 * `Http::timeout(30)->withOptions($options)->buildClient()`. Nhưng `PendingRequest::buildClient()`
 * chỉ đưa vào `GuzzleHttp\Client` hai thứ — `handler` và `cookies`; `timeout` và mọi tuỳ chọn của
 * `withOptions()` nằm trong `PendingRequest` và chỉ được ghép vào request khi gửi qua CHÍNH
 * `PendingRequest`. `minishlink/web-push` gửi bằng `$client->sendRequest()` (PSR-18) trên client đã
 * dựng, nên cả 30 giây của gói lẫn `webpush.client_options` đều bị bỏ: request ra máy chủ push KHÔNG
 * có hạn (đo ở Task 4: một socket nhận kết nối rồi im lặng giữ request quá 40 giây, tới khi tiến trình bị
 * giết). Một máy chủ push treo kết nối sẽ giữ đứng lượt rút hàng đợi `push` (R12).
 *
 * Ở đây `webpush.client_options` (hạn 10 giây) thành cấu hình của chính client Guzzle, nên áp vào
 * mọi request. Chồng handler vẫn lấy từ `Http::buildHandlerStack()` — middleware toàn cục và bộ
 * chặn của `Http::fake()`/`Http::preventStrayRequests()` vẫn đứng trong đường gửi (test
 * `tests/Feature/Push/WebPushInstallTest.php` đọc lại tuỳ chọn thật của request qua đúng bộ chặn đó).
 *
 * M12 Task 5 thêm chỗ cắm bộ đếm `throttle:push-devices` (R8: 10 request/phút cho mỗi tài khoản) của
 * route đăng ký thiết bị — ở đây chứ không ở `AppServiceProvider`, để mọi thứ của push nằm một chỗ.
 * Con số và khoá đếm thuộc {@see PushSubscriptionController}.
 */
class WebPushServiceProvider extends PackageWebPushServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        RateLimiter::for(PushSubscriptionController::RATE_LIMITER, fn (Request $request) => Limit::perMinute(
            PushSubscriptionController::REQUESTS_PER_MINUTE,
        )->by(PushSubscriptionController::rateLimitKey($request)));
    }

    /** @param  array<mixed>  $options */
    protected function webPushClient(array $options): ClientInterface
    {
        return new Client(['handler' => Http::buildHandlerStack(), ...$options]);
    }
}
