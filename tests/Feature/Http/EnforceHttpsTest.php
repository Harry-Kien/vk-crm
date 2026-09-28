<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

/*
|--------------------------------------------------------------------------
| SPEC §10 mục 1 — ép HTTPS + HSTS (kế hoạch M8 Task 1, `App\Http\Middleware\EnforceHttps`)
|--------------------------------------------------------------------------
*/

it('§10.1 để trống, FORCE_HTTPS bật mặc định ngoài local/testing: request http nhận 301 sang https', function () {
    config(['vkcrm.security.force_https' => null]);
    app()->detectEnvironment(fn () => 'production');

    $response = $this->get('http://vk-crm.test/portal/login');

    $response->assertRedirect('https://vk-crm.test/portal/login');
    expect($response->getStatusCode())->toBe(301);
});

it('§10.1 để trống, FORCE_HTTPS TẮT trong local/testing: request http không bị chuyển hướng', function () {
    config(['vkcrm.security.force_https' => null]);

    $this->get('/portal/login')->assertOk();
});

it('§10.1 FORCE_HTTPS=true chuyển hướng GET/HEAD bằng 301, giữ nguyên đường dẫn và tham số', function () {
    config(['vkcrm.security.force_https' => true]);

    $response = $this->get('http://vk-crm.test/portal/login?redirect=%2Fportal%2Fho-so');

    expect($response->getStatusCode())->toBe(301);
    $response->assertRedirect('https://vk-crm.test/portal/login?redirect=%2Fportal%2Fho-so');
});

it('§10.1 FORCE_HTTPS=true chuyển hướng phương thức khác GET/HEAD bằng 308, không mất thân request', function () {
    config(['vkcrm.security.force_https' => true]);

    Route::post('/vk-crm-test-post-https', fn () => 'khong bao gio toi day')->middleware('web');

    $response = $this->post('http://vk-crm.test/vk-crm-test-post-https', ['field' => 'value']);

    expect($response->getStatusCode())->toBe(308)
        ->and($response->headers->get('Location'))->toBe('https://vk-crm.test/vk-crm-test-post-https');
});

it('§10.1 FORCE_HTTPS=true không chuyển hướng một request đã an toàn qua proxy được tin', function () {
    config(['vkcrm.security.force_https' => true, 'trustedproxy.proxies' => '10.0.0.5']);

    $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
        ->get('http://vk-crm.test/portal/login');

    $response->assertOk();
});

it('§10.1 FORCE_HTTPS=true KHÔNG tin X-Forwarded-Proto của một proxy chưa được khai báo — vẫn chuyển hướng (đứng SAU TrustProxies)', function () {
    config(['vkcrm.security.force_https' => true, 'trustedproxy.proxies' => null]);

    $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
        ->get('http://vk-crm.test/portal/login');

    expect($response->getStatusCode())->toBe(301);
});

it('§10.1 HSTS chỉ gửi trên response mà request thật sự an toàn', function () {
    config(['vkcrm.security.force_https' => false, 'vkcrm.security.hsts_max_age' => 3600]);

    $insecure = $this->get('/portal/login');
    $secure = $this->withServerVariables(['HTTPS' => 'on'])->get('https://vk-crm.test/portal/login');

    expect($insecure->headers->has('Strict-Transport-Security'))->toBeFalse()
        ->and($secure->headers->get('Strict-Transport-Security'))->toBe('max-age=3600');
});

it('§10.1 HSTS_MAX_AGE để trống: một năm ngoài local/testing, tắt (0, không gửi header) trong local/testing', function () {
    config(['vkcrm.security.force_https' => false, 'vkcrm.security.hsts_max_age' => null]);

    $inTesting = $this->withServerVariables(['HTTPS' => 'on'])->get('https://vk-crm.test/portal/login');
    expect($inTesting->headers->has('Strict-Transport-Security'))->toBeFalse();

    app()->detectEnvironment(fn () => 'production');
    $inProduction = $this->withServerVariables(['HTTPS' => 'on'])->get('https://vk-crm.test/portal/login');
    expect($inProduction->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000');
});

it('§10.1 HSTS không kèm includeSubDomains/preload trừ khi .env ghi rõ', function () {
    config([
        'vkcrm.security.force_https' => false,
        'vkcrm.security.hsts_max_age' => 3600,
        'vkcrm.security.hsts_include_subdomains' => false,
        'vkcrm.security.hsts_preload' => false,
    ]);

    $response = $this->withServerVariables(['HTTPS' => 'on'])->get('https://vk-crm.test/portal/login');

    expect($response->headers->get('Strict-Transport-Security'))->toBe('max-age=3600');
});

it('§10.1 HSTS kèm includeSubDomains và preload khi cả hai được bật rõ', function () {
    config([
        'vkcrm.security.force_https' => false,
        'vkcrm.security.hsts_max_age' => 3600,
        'vkcrm.security.hsts_include_subdomains' => true,
        'vkcrm.security.hsts_preload' => true,
    ]);

    $response = $this->withServerVariables(['HTTPS' => 'on'])->get('https://vk-crm.test/portal/login');

    expect($response->headers->get('Strict-Transport-Security'))->toBe('max-age=3600; includeSubDomains; preload');
});

/**
 * `URL::forceHttps()` được gọi ở `AppServiceProvider::boot()`, đọc CHÍNH giá trị mà
 * `HttpsDefaults::boolFromRaw(config('vkcrm.security.force_https'))` trả về — hàm đó đã được đo cả
 * hai chiều ở các test khác của tệp này (qua hành vi chuyển hướng của `EnforceHttps`, đọc cùng một
 * hàm). Test này vì vậy không lặp lại phép đo đó — nó gọi thẳng `URL::forceHttps()` để đứng vào
 * ĐÚNG trạng thái mà `boot()` sẽ dựng khi cờ đó bật, rồi hỏi câu hỏi RIÊNG của chính nó: chữ ký của
 * một URL sinh dưới trạng thái đó có thật sự phủ scheme `https` hay không. Gọi lại `boot()` giữa
 * một test (`$this->refreshApplication()`) từng được thử và làm hỏng bảng route đã nạp — không
 * đáng đánh đổi để chứng minh lại một hàm đã được đo ở nơi khác.
 */
it('§10.1 URL::forceHttps() bật khi FORCE_HTTPS bật: chữ ký của một URL ký sẵn phủ cả scheme https', function () {
    URL::forceHttps();
    config(['trustedproxy.proxies' => '10.0.0.5']);

    Route::get('/vk-crm-test-signed', fn () => 'ok')->middleware(['web', 'signed'])->name('vk-crm-test-signed');

    // `RouteCollection` chỉ nạp lại bảng tên (`nameList`) ở `toSymfonyRouteCollection()` — bình
    // thường được gọi MỘT LẦN, khi request ĐẦU TIÊN của tiến trình thật sự khớp route (dispatch
    // thật). Một route đăng ký thẳng trong thân test, rồi hỏi tên nó NGAY bằng
    // `URL::temporarySignedRoute()` mà chưa có request nào chạy qua router, hỏi một bảng tên còn
    // rỗng — `RouteNotFoundException`. Đo được bằng một test rút gọn trước khi thêm dòng này.
    Route::getRoutes()->refreshNameLookups();

    $url = URL::temporarySignedRoute('vk-crm-test-signed', now()->addMinutes(5));

    expect($url)->toStartWith('https://');

    // Gọi ĐÚNG url đã ký, qua proxy được tin khai báo https — chữ ký hợp lệ.
    $viaHttps = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
        ->get($url);
    $viaHttps->assertOk();

    // Cùng chuỗi query/hết hạn/chữ ký, nhưng đổi thủ công tiền tố sang http — server thấy request
    // KHÔNG an toàn, nên `hasValidSignature()` tính lại URL bằng scheme http và KHÔNG khớp chữ ký
    // đã ký cho https — 403 thay vì lặng lẽ chấp nhận. `X-Forwarded-Proto: http` GHI ĐÈ giá trị
    // `https` của lượt gọi trên — `withHeaders()`/`withServerVariables()` của test client CỘNG DỒN
    // vào cùng một danh sách cho MỌI lượt gọi tiếp theo trong CÙNG một test, không tự xoá.
    $viaHttpTampered = str_replace('https://', 'http://', $url);
    $this->withHeaders(['X-Forwarded-Proto' => 'http'])
        ->get($viaHttpTampered)
        ->assertForbidden();
});
