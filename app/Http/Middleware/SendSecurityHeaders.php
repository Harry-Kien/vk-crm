<?php

namespace App\Http\Middleware;

use App\Support\Security\ContentSecurityPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header bảo mật của SPEC §10 mục 2 trên MỌI phản hồi: cả hai panel, route web (kể cả route tải
 * tệp có chữ ký), endpoint cập nhật Livewire và trang lỗi.
 *
 *  - Ba header LUÔN gửi, ở mọi chế độ CSP: `X-Frame-Options: DENY`,
 *    `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`.
 *  - Content-Security-Policy theo chế độ `CSP_MODE`; chế độ và chính sách:
 *    {@see ContentSecurityPolicy}.
 *
 * **Vì sao là middleware toàn cục (`bootstrap/app.php`) chứ không nằm trong danh sách của từng
 * panel.** Route của Filament KHÔNG đi qua nhóm `web` — mỗi panel mang danh sách middleware riêng
 * — còn route tải tệp và `/livewire/update` thì đi qua nhóm `web` và không đi qua panel nào.
 * Gắn ở ba nơi là ba chỗ để quên; gắn toàn cục là một chỗ, và nó bọc cả phản hồi lỗi mà
 * exception handler dựng ra bên trong đường ống.
 *
 * Nonce sinh MỖI request bằng `Vite::useCspNonce()` — TRƯỚC khi view được vẽ, vì Livewire đọc
 * `Vite::cspNonce()` để gắn nonce vào thẻ script của nó, và các view Filament đã giữ riêng ở
 * `resources/views/vendor/` đọc cùng giá trị ấy. Nonce được sinh cả ở chế độ `off` để HTML
 * không đổi theo chế độ; chỉ header là đổi.
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

        if ($header = ContentSecurityPolicy::headerName()) {
            $response->headers->set($header, ContentSecurityPolicy::policy($nonce));
        }

        return $response;
    }
}
