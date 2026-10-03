<?php

use App\Support\Push\DeviceLabel;

/*
|--------------------------------------------------------------------------
| M12 Task 5 — nhãn thiết bị rút từ User-Agent (R8)
|--------------------------------------------------------------------------
|
| Nhãn là thứ DUY NHẤT về một thiết bị mà trang thiết bị và audit được ghi (không bao giờ endpoint).
| Lớp hỗ trợ thuần — không có màn hình để đi qua; đường HTTP (nhãn vào dòng đăng ký và audit) đã có ở
| `PushDeviceRegistrationTest`.
*/

it('names the system and the browser a person would recognise', function (string $userAgent, string $label) {
    expect(DeviceLabel::fromUserAgent($userAgent))->toBe($label);
})->with([
    'iPhone, Safari' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1', 'iPhone · Safari'],
    'iPhone, app đã cài' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148', 'iPhone · Ứng dụng đã cài'],
    'iPhone, Chrome' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/124.0.6367.88 Mobile/15E148 Safari/604.1', 'iPhone · Chrome'],
    'iPad' => ['Mozilla/5.0 (iPad; CPU OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1', 'iPad · Safari'],
    'Android, Chrome' => ['Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36', 'Android · Chrome'],
    'Android, Samsung Internet' => ['Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/24.0 Chrome/117.0.0.0 Mobile Safari/537.36', 'Android · Samsung Internet'],
    'Android, Firefox' => ['Mozilla/5.0 (Android 14; Mobile; rv:125.0) Gecko/125.0 Firefox/125.0', 'Android · Firefox'],
    'Windows, Edge' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 Edg/124.0.2478.80', 'Windows · Edge'],
    'Windows, Opera' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 OPR/110.0.0.0', 'Windows · Opera'],
    'Mac, Safari' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14_4_1) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4.1 Safari/605.1.15', 'Mac · Safari'],
    'Linux, Firefox' => ['Mozilla/5.0 (X11; Linux x86_64; rv:125.0) Gecko/20100101 Firefox/125.0', 'Linux · Firefox'],
    'ChromeOS' => ['Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36', 'ChromeOS · Chrome'],
]);

it('falls back to a Vietnamese "unknown device" and never exceeds the column', function () {
    expect(DeviceLabel::fromUserAgent(null))->toBe(__('push.devices.unknown_device'))
        ->and(DeviceLabel::fromUserAgent(''))->toBe(__('push.devices.unknown_device'))
        ->and(DeviceLabel::fromUserAgent('curl/8.5.0'))->toBe(__('push.devices.unknown_device'))
        ->and(mb_strlen(DeviceLabel::fromUserAgent(str_repeat('x', 5000))))->toBeLessThanOrEqual(DeviceLabel::MAX_LENGTH);
});
