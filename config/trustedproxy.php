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
    | bằng địa chỉ THẬT của proxy — xem mục chặn ra mắt ở `docs/PROGRESS.md`. Dùng `*` chỉ khi
    | không có cách nào biết địa chỉ đó (`*` tin bất kỳ ai gửi header, tức trả lại quyền tự khai
    | địa chỉ cho người gọi; chỉ an toàn khi không có đường nào chạm tới ứng dụng mà không đi qua
    | proxy).
    |
    | Nhiều proxy thì ngăn cách bằng dấu phẩy: `TRUSTED_PROXIES=10.0.0.1,10.0.0.2`.
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
