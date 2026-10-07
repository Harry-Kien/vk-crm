<?php

namespace App\Http\Controllers\Pwa;

use App\Actions\Pwa\RenderOfflinePage;
use App\Support\Pwa\PwaPanels;
use Illuminate\Http\Response;

/**
 * `GET /{admin,portal}/offline` — trang ngoại tuyến của app trên điện thoại (kế hoạch M12, phán
 * quyết R4). Service worker cài nó vào bộ đệm lúc `install` và trả nó thay cho một lần điều hướng
 * gặp lỗi mạng. Nội dung: {@see RenderOfflinePage}. Route ở `routes/pwa.php`, ngoài nhóm `web`:
 * không cookie, không phiên — trang không biết ai đang xem, và không có gì để biết.
 */
final class OfflinePageController
{
    public function __invoke(string $panel, RenderOfflinePage $renderOfflinePage): Response
    {
        return new Response(
            $renderOfflinePage->handle($panel, PwaPanels::path($panel)),
            200,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }
}
