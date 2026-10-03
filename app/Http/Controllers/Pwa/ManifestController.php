<?php

namespace App\Http\Controllers\Pwa;

use App\Actions\Pwa\BuildManifest;
use App\Support\Pwa\PwaPanels;
use Illuminate\Http\JsonResponse;

/**
 * `GET /{admin,portal}/manifest.webmanifest` (kế hoạch M12, phán quyết R2). Controller mỏng: nội
 * dung do {@see BuildManifest} dựng từ path của panel ({@see PwaPanels::path()}); ở đây chỉ có kiểu
 * nội dung `application/manifest+json`.
 *
 * Route nằm ở `routes/pwa.php`, NGOÀI nhóm `web` và ngoài chồng middleware có phiên của panel; route
 * của `/admin` vẫn mang giới hạn IP (M8 R7) — lý do ở docblock tệp đó. Tên panel đến từ
 * `->defaults('panel', …)` của route, không từ URL người dùng gõ.
 */
final class ManifestController
{
    public function __invoke(string $panel, BuildManifest $buildManifest): JsonResponse
    {
        return new JsonResponse(
            $buildManifest->handle($panel, PwaPanels::path($panel)),
            200,
            ['Content-Type' => 'application/manifest+json'],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
