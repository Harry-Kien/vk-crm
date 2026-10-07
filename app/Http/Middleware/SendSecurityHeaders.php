<?php

namespace App\Http\Middleware;

use App\Support\Security\ContentSecurityPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header bảo mật của SPEC §10 mục 2 trên mọi phản hồi mà Kernel HTTP dựng ra sau khi ứng dụng đã
 * khởi động: cả hai panel, route web (kể cả route tải tệp có chữ ký), endpoint cập nhật Livewire,
 * trang lỗi, và phản hồi do middleware toàn cục khác tự dựng (503 bảo trì, 413, 400).
 *
 *  - Ba header LUÔN gửi, ở mọi chế độ CSP: `X-Frame-Options: DENY`,
 *    `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`.
 *  - Content-Security-Policy theo chế độ `CSP_MODE`; chế độ và chính sách:
 *    {@see ContentSecurityPolicy}.
 *  - **Một ngoại lệ, hẹp (M12 R4):** response mà CSP duy nhất của nó là ĐÚNG
 *    {@see ContentSecurityPolicy::WORKER_POLICY} — `sw.js` của hai app trên điện thoại — giữ nguyên
 *    CSP đó và không nhận chính sách trang (kể cả bản `…-Report-Only` ở `local`/`testing`). Mọi CSP
 *    KHÁC mà một response tự đặt bị gỡ và thay bằng chính sách trang theo chế độ, nên không response
 *    nào tự nới được CSP; chính sách worker thì chặt hơn chính sách trang. Có test cả hai chiều
 *    (`tests/Feature/Pwa/ServiceWorkerTest.php`, `tests/Feature/Http/SecurityHeadersTest.php`).
 *
 * **Vì sao là middleware toàn cục chứ không nằm trong danh sách của từng panel.** Route của
 * Filament KHÔNG đi qua nhóm `web` — mỗi panel mang danh sách middleware riêng — còn route tải
 * tệp và `/livewire/update` thì đi qua nhóm `web` và không đi qua panel nào. Gắn ở ba nơi là ba
 * chỗ để quên; gắn toàn cục là một chỗ, và nó bọc cả phản hồi lỗi mà exception handler dựng ra
 * bên trong đường ống.
 *
 * **Vì sao ĐẦU danh sách toàn cục (`$middleware->prepend()` ở `bootstrap/app.php`).** Middleware
 * toàn cục đứng TRƯỚC lớp này trả phản hồi của nó mà không đi qua lớp này:
 * `ValidatePathEncoding` (400), `PreventRequestsDuringMaintenance` (503 bảo trì),
 * `ValidatePostSize` (413). Gắn cuối (`append`) thì ba phản hồi đó không có header nào.
 *
 * Không phủ (không sửa được ở tầng middleware): phản hồi cho một lỗi ném ra lúc ứng dụng KHỞI
 * ĐỘNG, trước đường ống — `Kernel::handle()` vẽ thẳng lỗi đó; trang bảo trì VẼ SẴN
 * (`artisan down --render=…`), do `storage/framework/maintenance.php` in ra từ `public/index.php`
 * trước cả khi có Kernel (`artisan down` không `--render` thì vẫn đi qua lớp này); và tệp tĩnh
 * dưới `public/` do máy chủ web trả, không qua PHP.
 *
 * Nonce sinh MỖI request bằng `Vite::useCspNonce()` — TRƯỚC khi view được vẽ, vì Livewire đọc
 * `Vite::cspNonce()` để gắn nonce vào thẻ script của nó, và các view Filament đã giữ riêng ở
 * `resources/views/vendor/` đọc cùng giá trị ấy. Nonce được sinh cả ở chế độ `off` để HTML
 * không đổi theo chế độ; chỉ header là đổi.
 *
 * `form-action` thêm các origin mà một màn hình của CHÍNH request này đã xin
 * (`ContentSecurityPolicy::allowFormActionTo()`, M11 Task 4: màn hình đồng ý OAuth) — đọc từ thuộc
 * tính của request sau khi phản hồi đã dựng xong, nên không trang nào khác mang theo.
 */
class SendSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // M12 R4 — `sw.js` mang CSP riêng của worker và giữ nó ở mọi chế độ, không kèm chính sách
        // trang (docblock `ContentSecurityPolicy::WORKER_POLICY`). Chỉ khi header CSP thi hành của
        // response là ĐÚNG MỘT dòng mang ĐÚNG chuỗi đó.
        if ($response->headers->all(ContentSecurityPolicy::HEADER_ENFORCE) === [ContentSecurityPolicy::WORKER_POLICY]) {
            return $response;
        }

        // Mọi CSP khác mà một response tự đặt bị gỡ: chính sách trang theo chế độ (hoặc không gì,
        // ở `off`) là chính sách DUY NHẤT — không response nào tự nới được nó.
        $response->headers->remove(ContentSecurityPolicy::HEADER_ENFORCE);
        $response->headers->remove(ContentSecurityPolicy::HEADER_REPORT);

        if ($header = ContentSecurityPolicy::headerName()) {
            $response->headers->set($header, ContentSecurityPolicy::policy($nonce, ContentSecurityPolicy::formActionOrigins($request)));
        }

        return $response;
    }
}
