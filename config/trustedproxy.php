<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Proxy được tin (SPEC §10.3 và §10.6)
    |--------------------------------------------------------------------------
    |
    | `Illuminate\Http\Middleware\TrustProxies` nằm sẵn trong danh sách middleware toàn cục của
    | Laravel 13 và đọc khoá này ở **thời điểm xử lý request** (`setTrustedProxyIpAddresses()`),
    | nên đây là chỗ duy nhất cấu hình được bằng biến môi trường. `bootstrap/app.php` KHÔNG làm
    | được việc này: callback `withMiddleware()` chạy lúc Kernel được dựng, trước khi
    | `LoadConfiguration` và `LoadEnvironmentVariables` chạy, nên `config()` và `env()` ở đó còn
    | rỗng.
    |
    | Vì sao con số này quan trọng, đo được chứ không phải phòng xa: đứng sau một proxy mà không
    | khai báo nó thì `request()->ip()` trả về địa chỉ của PROXY cho mọi khách. Hai hệ quả:
    |
    |  1. **Chiều IP của SPEC §10.3 sụp thành một bộ đếm duy nhất cho cả cổng.** Năm lần gõ sai
    |     của bất kỳ ai khoá TOÀN BỘ khách hàng trong 15 phút.
    |  2. `client_users.last_login_ip` và `stage_log_views.ip` ghi lại địa chỉ của proxy. Cột thứ
    |     hai là bằng chứng của văn phòng rằng một khách đã được cho xem một cập nhật; một cột
    |     bằng chứng phải nói rõ nó ghi địa chỉ của ai.
    |
    | Mặc định là **không tin ai** (`null`), nên máy dev và bộ test giữ nguyên hành vi: header
    | `X-Forwarded-For` bị bỏ qua hoàn toàn. Trên máy thật thì `TRUSTED_PROXIES` phải được điền
    | bằng địa chỉ THẬT của proxy — xem mục chặn ra mắt ở `docs/PROGRESS.md`.
    |
    | **KHÔNG dùng `*`/`**` hay các dải bao trọn `0.0.0.0/0`/`::/0` — dù trước đây tài liệu này có
    | gợi ý `*` "khi không có cách nào biết địa chỉ đó".** Bốn giá trị đó tin bất kỳ ai gửi header
    | `X-Forwarded-For` (`Illuminate\Http\Middleware\TrustProxies::
    | setTrustedProxyIpAddressesToTheCallingIp()` đặt dải tin thành đúng hai CIDR bao trọn đó khi
    | thấy `*`/`**`), xuyên thủng `ADMIN_IP_ALLOWLIST` (R7) và bộ đếm đăng nhập theo IP (§10.3) —
    | `vkcrm:preflight` (R1, fix round 1 Task 1) chặn ĐỎ cả bốn giá trị này, không còn cho qua
    | XANH. Đứng theo đúng mẫu `tools/deploy/nginx.conf.example`/`apache-vhost.conf.example` (máy
    | chủ web nói thẳng với php-fpm, KHÔNG proxy/CDN tách rời) thì điền `TRUSTED_PROXIES=
    | 127.0.0.1` — không có proxy nào để tin, `REMOTE_ADDR` mà php-fpm thấy đã là địa chỉ thật của
    | khách (nginx tự đặt qua `fastcgi_param REMOTE_ADDR $remote_addr`). Có CDN/reverse-proxy thật
    | đứng trước (Cloudflare, một load balancer riêng…) thì điền địa chỉ/dải IP THẬT của nó.
    |
    | Nhiều proxy thì ngăn cách bằng dấu phẩy: `TRUSTED_PROXIES=10.0.0.1,10.0.0.2`.
    |
    | CI đỏ từ 2026-09-22 (`e2e/F1` — xem `docs/audits/2026-09-24-quy-trinh.md`): `cp
    | .env.example .env` từng đưa dòng RỖNG `TRUSTED_PROXIES=` vào $_SERVER (khác với "biến
    | không tồn tại"), nên `env('TRUSTED_PROXIES')` trả CHUỖI RỖNG '' chứ không `null`. `?: null`
    | gộp cả hai trường hợp "không đặt" và "đặt rỗng" về đúng MỘT nghĩa: không tin ai.
    |
    */

    'proxies' => env('TRUSTED_PROXIES') ?: null,

];
