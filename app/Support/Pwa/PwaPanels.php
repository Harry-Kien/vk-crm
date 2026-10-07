<?php

namespace App\Support\Pwa;

use App\Http\Middleware\RestrictAdminIpAllowlist;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * Hai panel có app trên điện thoại và đường dẫn (scope) của mỗi app — kế hoạch M12, phán quyết R2.
 *
 * {@see self::path()} là PATH của URL panel, không có dấu `/` cuối: `/admin`, `/portal`. Lấy từ
 * `Filament::getPanel($panel)->getUrl()` rồi rút phần path bằng `parse_url` — không viết cứng. Với
 * panel `portal` (không có route `home`), `getUrl()` rơi về `url($this->getPath())` nên HOST đi theo
 * request hiện tại (docblock `App\Support\PortalUrl`); chỉ phần path được dùng nên điều đó không
 * ảnh hưởng. Scope có dấu `/` cuối thì Chromium bỏ scope (vì `start_url` `/admin` nằm ngoài
 * `/admin/`) và rơi về cả origin — app nội bộ khi đó "nuốt" luôn `/portal` (đo ở
 * `docs/research/2026-10-01-pwa-khao-sat.md` mục 2.3).
 *
 * Nằm ở `Support` chứ không trong Action vì nó hỏi Filament (`tests/Feature/ArchitectureTest.php`:
 * `App\Actions` không phụ thuộc Filament). Task 3 dùng lại cho scope của `sw.js` và header
 * `Service-Worker-Allowed`, và cho nút "Về trang chính" của trang lỗi ({@see self::startUrlFor()}).
 */
final class PwaPanels
{
    /** `Filament\Panel::getId()` của hai panel có app trên điện thoại. */
    public const IDS = ['admin', 'portal'];

    /**
     * App của một request không thuộc panel nào (hay không được biết tới panel của nó): cổng khách —
     * cũng là nơi `/` của ứng dụng chuyển hướng tới (`routes/web.php`).
     */
    public const FALLBACK = 'portal';

    public static function path(string $panel): string
    {
        if (! in_array($panel, self::IDS, true)) {
            throw new \InvalidArgumentException("Panel [{$panel}] không có app trên điện thoại.");
        }

        return '/'.trim((string) parse_url((string) Filament::getPanel($panel)->getUrl(), PHP_URL_PATH), '/');
    }

    /**
     * URL tuyệt đối của `start_url` thuộc app mà `$request` nằm trong — đích của nút "Về trang
     * chính" trên trang lỗi 403/404 (`resources/views/errors/`). M12 Task 3 vòng sửa 1.
     *
     * **Vì sao không còn là `url('/')`.** Từ Task 3, liên kết tải của cả hai app mở trong CÙNG cửa
     * sổ, kể cả cửa sổ app đã cài, nên một liên kết hết hạn hay bị từ chối mở trang lỗi ngay trong
     * app. `/` chuyển hướng tới `/portal` — trang đăng nhập của KHÁCH, ngoài scope `/admin`; và với
     * app khách, `/` cũng ngoài scope `/portal`. Trên iPhone, một điều hướng ra ngoài scope mở tấm
     * Safari ngoài cửa sổ app, mà cửa sổ standalone lại không có nút quay lại hay tải lại: lối ra
     * duy nhất của trang lỗi thành một ngõ cụt.
     *
     * **App của request, theo thứ tự:**
     *  1. path nằm dưới path của một panel, khớp theo ĐOẠN (`admin` hoặc `admin/…`; `/adminxyz`
     *     không khớp) — gồm route bí danh tải tệp, mọi trang của panel và mọi URL lạ dưới đó;
     *  2. không thì panel hiện hành của Filament — request cập nhật Livewire (`/livewire-…/update`)
     *     không nằm dưới path nào, nhưng middleware BỀN `SetUpPanel` (chạy lại theo route gốc của
     *     component, đứng ĐẦU chồng middleware của panel) đã đặt nó trước khi lời từ chối được ném;
     *  3. không thì {@see self::FALLBACK}.
     *
     * **Giới hạn IP của admin (M8 R7) thắng.** Panel mang `RestrictAdminIpAllowlist` trong
     * `getMiddleware()` — cùng luật với nhóm route PWA ở `routes/pwa.php` — chỉ được chọn khi IP được
     * vào; không thì {@see self::FALLBACK}. Thiếu vế này, trang 404 mà IP lạ nhận dưới `/admin` sẽ
     * khác trang 404 của một path lạ bất kỳ đúng ở cái nút này: tự nó chỉ ra `/admin` có gì đặc biệt.
     * (Vế "panel có mang middleware đó không" không đo được từ ngoài: panel duy nhất không mang nó
     * cũng chính là {@see self::FALLBACK}.)
     *
     * Chỉ đọc path, panel hiện hành và IP — không đọc bản ghi, phiên hay người dùng — nên hai lời từ
     * chối khác nhau trong CÙNG một app vẫn ra đúng từng byte cùng một trang (SPEC §10.10).
     *
     * Không xét tên miền riêng của panel (`ADMIN_DOMAIN`/`PORTAL_DOMAIN`, để trống trong
     * `.env.example`): kết quả là path trên host của chính request, cùng cách `start_url` của
     * manifest là một path.
     */
    public static function startUrlFor(Request $request): string
    {
        $panel = Arr::first(self::IDS, fn (string $id): bool => $request->is(ltrim(self::path($id), '/'), ltrim(self::path($id), '/').'/*'))
            ?? Arr::first(self::IDS, fn (string $id): bool => $id === Filament::getCurrentPanel()?->getId())
            ?? self::FALLBACK;

        return url(self::path(self::admits($request, $panel) ? $panel : self::FALLBACK));
    }

    private static function admits(Request $request, string $panel): bool
    {
        return ! in_array(RestrictAdminIpAllowlist::class, Filament::getPanel($panel)->getMiddleware(), true)
            || RestrictAdminIpAllowlist::admits($request);
    }
}
