<?php

namespace App\Http\Middleware;

use App\Actions\Deployment\RunPreflight;
use App\Support\Security\HttpsDefaults;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SPEC §10 mục 1 (kế hoạch M8 Task 1) — ép HTTPS bằng chuyển hướng, và gửi
 * `Strict-Transport-Security` làm lớp DỰ PHÒNG khi hosting không cho cấu hình máy chủ web (mẫu
 * cấu hình thật ở `tools/deploy/nginx.conf.example` và `apache-vhost.conf.example` là tầng
 * CHÍNH — xem docblock của hai tệp đó).
 *
 * **Vì sao PHẢI đứng SAU `TrustProxies` trong danh sách middleware toàn cục.**
 * `$request->secure()` chỉ đọc header `X-Forwarded-Proto` của một proxy ĐÃ được tin
 * (`TRUSTED_PROXIES`, xem `config/trustedproxy.php`). Đứng trước `TrustProxies` — như
 * {@see SendSecurityHeaders}, thứ có lý do RIÊNG để đứng đầu, xem docblock của nó — thì lớp này
 * sẽ luôn thấy request là KHÔNG an toàn sau một proxy kết thúc TLS, và mọi request hợp lệ bị
 * chuyển hướng vào một vòng lặp vô tận (proxy nhận https, gọi app bằng http, app thấy "không an
 * toàn" và chuyển hướng sang https, proxy lại nhận https rồi lại gọi app bằng http…). Đăng ký
 * bằng `$middleware->append()` ở `bootstrap/app.php` — cách MIDDLEWARE toàn cục mặc định của
 * Laravel được lắp ráp (`Middleware::getGlobalMiddleware()`) đặt mọi middleware `append()` SAU
 * `TrustProxies`.
 *
 * Thiếu `TRUSTED_PROXIES` ở production không phải lỗi của middleware này — đó là lưới của
 * `vkcrm:preflight` (R1, {@see RunPreflight}).
 *
 * **Bật/tắt:** `config('vkcrm.security.force_https')` (env `FORCE_HTTPS`), qua
 * {@see HttpsDefaults::boolFromRaw()} — để trống là BẬT ở mọi môi trường trừ `local`/`testing`.
 *
 * **`URL::forceHttps()` không nằm ở đây.** Middleware chỉ chạy khi có một REQUEST HTTP; một job
 * hàng đợi gửi thư có link tải tệp ký sẵn không đi qua middleware nào. `URL::forceHttps()` vì
 * vậy được gọi ở `AppServiceProvider::boot()` — chạy cho MỌI tiến trình (web, queue worker, lệnh
 * artisan) — để link trong thư luôn là `https` bất kể ai sinh ra nó.
 *
 * **Phương thức khác GET/HEAD không được lặng lẽ mất thân request.** Một 301/302 khiến trình
 * duyệt/HTTP client đổi phương thức thành GET và bỏ thân request (RFC 7231 §6.4). Chuyển hướng
 * bằng 308 (Permanent Redirect) cho các phương thức đó — 308 là phần DUY NHẤT của họ 3xx bắt
 * buộc giữ nguyên phương thức và thân request theo đặc tả. GET/HEAD dùng 301 thường lệ (không có
 * thân request để giữ, và 301 là mã quen thuộc hơn cho việc "chuyển hẳn sang https").
 */
class EnforceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (HttpsDefaults::boolFromRaw(config('vkcrm.security.force_https')) && ! $request->secure()) {
            $target = 'https://'.$request->getHttpHost().$request->getRequestUri();

            return redirect()->to($target, $request->isMethod('GET') || $request->isMethod('HEAD') ? 301 : 308);
        }

        $response = $next($request);

        $this->applyHsts($request, $response);

        return $response;
    }

    /**
     * SPEC §10 mục 1: "HSTS ở tầng web server. Khi hosting không cho cấu hình web server, gửi
     * HSTS từ middleware." CHỈ trên response mà request hiện tại thật sự an toàn — một header
     * HSTS đọc được qua http thô không có nghĩa gì (trình duyệt bỏ qua theo đặc tả), và gửi nó vô
     * điều kiện sẽ làm test khẳng định "không có header trên request http" sai một cách vô hại
     * nhưng khó hiểu.
     *
     * KHÔNG bật `includeSubDomains`/`preload` trừ khi `.env` ghi rõ — xem lý do ở
     * `config/vkcrm.php` (`hsts_include_subdomains`/`hsts_preload`): các tên miền con khác của
     * văn phòng nằm ngoài ứng dụng này.
     */
    private function applyHsts(Request $request, Response $response): void
    {
        if (! $request->secure()) {
            return;
        }

        $maxAge = HttpsDefaults::secondsFromRaw(config('vkcrm.security.hsts_max_age'), 31536000);

        if ($maxAge <= 0) {
            return;
        }

        $directive = 'max-age='.$maxAge;

        if (config('vkcrm.security.hsts_include_subdomains')) {
            $directive .= '; includeSubDomains';
        }

        if (config('vkcrm.security.hsts_preload')) {
            $directive .= '; preload';
        }

        $response->headers->set('Strict-Transport-Security', $directive);
    }
}
