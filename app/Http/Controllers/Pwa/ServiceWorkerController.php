<?php

namespace App\Http\Controllers\Pwa;

use App\Actions\Pwa\BuildServiceWorker;
use App\Support\Pwa\PwaPanels;
use App\Support\Security\ContentSecurityPolicy;
use Illuminate\Http\Response;

/**
 * `GET /{admin,portal}/sw.js` — service worker của app trên điện thoại (kế hoạch M12, phán quyết R4).
 * Controller mỏng: hằng số do {@see BuildServiceWorker} dựng, văn bản là view
 * `resources/views/pwa/sw-js.blade.php`; ở đây chỉ có bốn header của R4:
 *
 *  - `Content-Type: application/javascript; charset=utf-8` — trình duyệt từ chối đăng ký một
 *    worker không mang kiểu JavaScript (và `X-Content-Type-Options: nosniff` là toàn cục);
 *  - `Cache-Control: no-cache` — mỗi lượt kiểm cập nhật hỏi lại máy chủ. Header `immutable` một năm
 *    mà hai mẫu máy chủ web gửi cho tệp `.js` tĩnh không chạm tới route này: mẫu nginx có khối
 *    `location =` riêng, mẫu Apache không cần (`<FilesMatch>` chỉ khớp tệp có thật) — đo thật bằng
 *    `tools/deploy/verify-pwa-routes.sh`;
 *  - `Service-Worker-Allowed: {path}` — worker đặt ở `/admin/sw.js` mặc định chỉ được giữ scope
 *    `/admin/`; scope `/admin` (không dấu `/` cuối, R2) cần header này, thiếu nó trình duyệt từ
 *    chối bằng `SecurityError` (đo ở khảo sát Task 1, mục 2.2);
 *  - `Content-Security-Policy:` {@see ContentSecurityPolicy::WORKER_POLICY} — CSP riêng của worker,
 *    mà middleware toàn cục `SendSecurityHeaders` để nguyên.
 *
 * Route nằm ở `routes/pwa.php`, ngoài nhóm `web` (không cookie, không phiên); bản của `/admin` vẫn
 * nằm sau giới hạn IP (M8 R7). Tên panel đến từ `->defaults('panel', …)` của route.
 */
final class ServiceWorkerController
{
    public function __invoke(string $panel, BuildServiceWorker $buildServiceWorker): Response
    {
        $path = PwaPanels::path($panel);

        return new Response(
            view(BuildServiceWorker::VIEW, $buildServiceWorker->handle($panel, $path))->render(),
            200,
            [
                'Content-Type' => 'application/javascript; charset=utf-8',
                'Cache-Control' => 'no-cache',
                'Service-Worker-Allowed' => $path,
                ContentSecurityPolicy::HEADER_ENFORCE => ContentSecurityPolicy::WORKER_POLICY,
            ],
        );
    }
}
