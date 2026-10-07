<?php

namespace App\Actions\Pwa;

use App\Support\Pwa\AppIcons;
use App\Support\Pwa\PwaPanels;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\View;

/**
 * Các hằng số mà service worker của MỘT panel cần (kế hoạch M12, phán quyết R4), được render vào
 * `resources/views/pwa/sw-js.blade.php`. Máy dev không có Node nên JavaScript không có test chạy
 * nó; mọi thứ quyết định hành vi vì vậy được dựng ở PHP và test trên văn bản phục vụ ra
 * (`tests/Feature/Pwa/ServiceWorkerTest.php`):
 *
 *  - `scope` = `$path` của panel ({@see PwaPanels::path()}, không dấu `/` cuối).
 *  - `static_prefixes` = `config('vkcrm.pwa.static_prefixes')` NGUYÊN VĂN — danh sách cho phép
 *    duy nhất của bộ đệm.
 *  - `precache`: trang ngoại tuyến của CHÍNH panel, logo trên trang đó, biểu tượng 192 — toàn tài
 *    nguyên công khai; một mục 404 làm hỏng cả lượt cài (`cache.addAll`), nên mỗi mục là một tệp
 *    có thật (test).
 *  - `cache_prefix` = `vk-static-{panel}-`, tên bộ đệm = tiền tố + VERSION. **Lệch kế hoạch có lý
 *    do:** kế hoạch viết "`activate` xoá mọi cache khác tên `vk-static-{VERSION}`". Khi
 *    `ADMIN_DOMAIN`/`PORTAL_DOMAIN` để trống, hai app chung MỘT origin, tức chung một
 *    CacheStorage; luật nguyên văn để worker của app này xoá bộ đệm (kể cả trang ngoại tuyến đã
 *    cài) của app kia mỗi lần VERSION của hai bên lệch nhau — điều xảy ra ngay sau mỗi lần triển
 *    khai, vì VERSION còn băm trang ngoại tuyến riêng của từng panel. Mỗi worker vì vậy chỉ xoá
 *    bộ đệm mang tiền tố của CHÍNH nó.
 *  - `version`: 16 ký tự hex đầu của sha256 trên — nội dung NGUỒN của view `sw-js` (thứ bộ tìm
 *    view thật sự dùng), danh sách tiền tố, danh sách cài sẵn, HTML trang ngoại tuyến
 *    ({@see RenderOfflinePage}), và phiên bản Filament. View, tiền tố và phiên bản Filament là của kế hoạch;
 *    danh sách cài sẵn và trang ngoại tuyến được thêm vì bản trong bộ đệm chỉ được cài lại khi
 *    VERSION đổi — không có chúng, một lần đổi hotline để app đã cài hiện số cũ khi mất mạng tới
 *    lần nâng cấp Filament kế tiếp. Phiên bản Filament có mặt vì tài nguyên tĩnh của Filament mang
 *    `?v=` trong URL: nâng cấp mà VERSION giữ nguyên để lại một bộ bản sao cũ. KHÔNG có bí mật nào
 *    trong băm (`sw.js` là tệp công khai) — có test đổi `APP_KEY` mà VERSION giữ nguyên.
 *
 * `$filamentVersion` để trống thì đọc phiên bản đã cài (`InstalledVersions`). Tham số có mặt chỉ vì
 * Composer không cho giả phiên bản đã cài qua HTTP — test truyền hai số để khẳng định nó nằm trong
 * băm; controller không bao giờ truyền.
 *
 * Action không hỏi Filament (`tests/Feature/ArchitectureTest.php`): `$path` do nơi gọi đưa vào.
 */
final class BuildServiceWorker
{
    public const VIEW = 'pwa.sw-js';

    public function __construct(private readonly RenderOfflinePage $offlinePage) {}

    /**
     * `push_*` (M12 Task 7, R11): tiêu đề (`vkcrm.brand.short_name`), câu dự phòng
     * (`push.service_worker.fallback`) và hai biểu tượng mà trình nghe `push` dùng khi một lần đẩy tới
     * mà không đọc được nội dung — cùng giá trị `App\Enums\PushTopic` đặt vào payload thường. Không vào
     * VERSION: chúng không đổi gì trong bộ đệm, và trình duyệt tự cài lại worker khi văn bản `sw.js`
     * khác đi một byte.
     *
     * @return array{version: string, scope: string, cache_prefix: string, offline_url: string, precache: list<string>, static_prefixes: list<string>, push_title: string, push_body: string, push_icon: string, push_badge: string}
     */
    public function handle(string $panel, string $path, ?string $filamentVersion = null): array
    {
        $offlineUrl = (string) parse_url(route("pwa.{$panel}.offline"), PHP_URL_PATH);
        $staticPrefixes = array_values((array) config('vkcrm.pwa.static_prefixes'));
        $precache = [
            $offlineUrl,
            (string) parse_url(asset(RenderOfflinePage::LOGO), PHP_URL_PATH),
            (string) parse_url(asset(AppIcons::ANY[192]), PHP_URL_PATH),
        ];

        $version = substr(hash('sha256', (string) json_encode([
            'view' => (string) file_get_contents(View::getFinder()->find(self::VIEW)),
            'static_prefixes' => $staticPrefixes,
            'precache' => $precache,
            'offline' => $this->offlinePage->handle($panel, $path),
            'filament' => $filamentVersion ?? (string) InstalledVersions::getPrettyVersion('filament/filament'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 16);

        return [
            'version' => $version,
            'scope' => $path,
            'cache_prefix' => "vk-static-{$panel}-",
            'offline_url' => $offlineUrl,
            'precache' => $precache,
            'static_prefixes' => $staticPrefixes,
            'push_title' => (string) config('vkcrm.brand.short_name'),
            'push_body' => (string) __('push.service_worker.fallback'),
            'push_icon' => (string) parse_url(asset(AppIcons::ANY[192]), PHP_URL_PATH),
            'push_badge' => (string) parse_url(asset(AppIcons::BADGE), PHP_URL_PATH),
        ];
    }
}
