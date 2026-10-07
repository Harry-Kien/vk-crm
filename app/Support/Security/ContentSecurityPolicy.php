<?php

namespace App\Support\Security;

use App\Filament\AvatarProviders\InitialsAvatarProvider;

/**
 * Chính sách Content-Security-Policy của cả hai panel và route web (SPEC §10 mục 2, phán quyết
 * R4 của kế hoạch M8). Số đo đứng sau từng nguồn nằm ở `docs/research/2026-09-26-csp-khao-sat.md`
 * — thêm một nguồn vào đây mà không có số đo ở đó là thêm theo đoán.
 *
 * Ba chế độ, đọc từ `config('vkcrm.security.csp_mode')` (env `CSP_MODE`):
 *  - `off`: không gửi header CSP nào (ba header còn lại của §10.2 vẫn luôn gửi);
 *  - `report`: gửi `Content-Security-Policy-Report-Only` — trình duyệt chỉ báo vi phạm ra
 *    console và sự kiện `securitypolicyviolation`, không chặn gì;
 *  - `enforce`: gửi `Content-Security-Policy` — trình duyệt chặn.
 *
 * Để trống: `report` CHỈ khi `APP_ENV` là `local` hoặc `testing` (máy dev thấy vi phạm trong
 * console mà không bị chặn); mọi môi trường khác — production, staging, một `APP_ENV` gõ sai — là
 * `enforce`. Chiều hạ xuống phải được gọi tên, chiều an toàn là mặc định (vòng sửa 1, I3): chỉ
 * một `CSP_MODE=report` hay `off` viết ra mới nới CSP, và giá trị viết ra được tôn trọng ở mọi
 * môi trường. Một giá trị `CSP_MODE` lạ (gõ sai `enforced`…) cũng rơi về `enforce`: lỗi gõ ở
 * `.env` không được phép lặng lẽ tắt CSP. Giá trị `CSP_MODE` không phân biệt hoa thường và bỏ
 * khoảng trắng hai đầu (chỉ khoảng trắng = để trống); tên môi trường thì phân biệt hoa thường.
 */
final class ContentSecurityPolicy
{
    public const MODE_OFF = 'off';

    public const MODE_REPORT = 'report';

    public const MODE_ENFORCE = 'enforce';

    public const HEADER_ENFORCE = 'Content-Security-Policy';

    public const HEADER_REPORT = 'Content-Security-Policy-Report-Only';

    /** Hai môi trường duy nhất mà `CSP_MODE` để trống được hạ xuống `report`. */
    public const REPORT_BY_DEFAULT_IN = ['local', 'testing'];

    /**
     * CSP riêng của service worker (kế hoạch M12, phán quyết R4) — header của chính
     * `/{admin,portal}/sw.js` (`App\Http\Controllers\Pwa\ServiceWorkerController`), áp lên mọi
     * request mà worker tự gửi (`fetch`, `cache.addAll`), không lên trang.
     *
     * Worker chỉ đi cùng origin (luật "chỉ GET cùng origin" của R4) nên `default-src 'self'` là đủ.
     * Ở mọi chỉ thị rơi về `default-src` (nguồn script, `fetch`, ảnh…) nó CHẶT HƠN chính sách trang
     * ({@see self::policy()}: không nonce, không `unsafe-eval`, không `blob:`, không Bunny Fonts).
     * Ba chỉ thị của trang KHÔNG rơi về `default-src` (`frame-ancestors`, `form-action`,
     * `base-uri`) vắng mặt ở đây — chúng không có nghĩa với một worker (không khung, không form,
     * không `<base>`). Vì thế chuỗi này CHỈ dành cho response JavaScript của worker: chỉ
     * `ServiceWorkerController` đặt nó (test quét `app/` ở `tests/Feature/Pwa/ServiceWorkerTest.php`).
     *
     * Được THI HÀNH ở mọi chế độ, kể cả `off`: công tắc `off` là lối thoát khẩn cho một trang bị
     * CSP làm hỏng; worker không cần gì ngoài origin của nó nên không có gì để cứu.
     *
     * `App\Http\Middleware\SendSecurityHeaders` để nguyên ĐÚNG chuỗi này và không thêm chính sách
     * trang vào response mang nó; mọi CSP khác mà một response tự đặt bị thay bằng chính sách trang.
     */
    public const WORKER_POLICY = "default-src 'self'";

    public static function mode(): string
    {
        $mode = strtolower(trim((string) config('vkcrm.security.csp_mode')));

        if ($mode === '') {
            return app()->environment(self::REPORT_BY_DEFAULT_IN) ? self::MODE_REPORT : self::MODE_ENFORCE;
        }

        return in_array($mode, [self::MODE_OFF, self::MODE_REPORT, self::MODE_ENFORCE], true)
            ? $mode
            : self::MODE_ENFORCE;
    }

    /**
     * Tên header ứng với chế độ hiện hành, `null` khi chế độ là `off`.
     */
    public static function headerName(): ?string
    {
        return match (self::mode()) {
            self::MODE_OFF => null,
            self::MODE_REPORT => self::HEADER_REPORT,
            default => self::HEADER_ENFORCE,
        };
    }

    /**
     * Chính sách cho một request, với nonce của chính request đó.
     *
     * `script-src` KHÔNG BAO GIỜ có `'unsafe-inline'` (SPEC §10.2): mọi `<script>` nội tuyến
     * phải mang nonce — của Livewire tự gắn qua `Vite::cspNonce()`, của Filament qua ba view đã
     * giữ riêng ở `resources/views/vendor/` (danh sách và test ghim:
     * `tests/Feature/Http/PublishedFilamentViewsTest.php`).
     *
     * `script-src` CÓ `'unsafe-eval'` — ĐO ĐƯỢC là bắt buộc, không thêm theo đoán: bản Alpine của
     * Livewire dựng mọi biểu thức `x-data`/`x-on`/`wire:*` và mọi khối `@script` của Filament bằng
     * `Function`. Thiếu nó, trang đăng nhập nhân sự không dùng được; bản Alpine CSP
     * (`livewire.csp_safe`) bỏ được nó nhưng làm hỏng form "Chuyển giai đoạn". SPEC chỉ cấm
     * `unsafe-inline`. Cái giá của nó ghi ở mục 5 của tài liệu khảo sát.
     *
     * `worker-src 'self' blob:` — ĐO ĐƯỢC (vòng sửa 1, C1): ô tải lên của Filament (FilePond) dựng
     * bản xem trước ẢNH trong một Worker tạo từ `URL.createObjectURL(blob)`. Không có chỉ thị này
     * thì trình duyệt dùng `script-src`, nơi `blob:` không khớp, và bản xem trước ảnh chụp của
     * khách (SPEC §8.4) hỏng ở cả Chromium lẫn WebKit. `blob:` CHỈ mở cho Worker: `script-src`
     * không có nó, nên một `<script src="blob:…">` vẫn bị chặn. Nguồn `'self'` của chỉ thị này cũng
     * là thứ cho `navigator.serviceWorker.register('/{admin,portal}/sw.js')` của M12 (R5,
     * `public/pwa/register.js`) — cùng origin, không cần thêm nguồn nào.
     *
     * `manifest-src 'self'` — M12 R5: manifest của hai app trên điện thoại (`routes/pwa.php`)
     * cùng origin với trang. Trước M12 nó rơi về `default-src 'self'` (0 vi phạm, đo ở
     * `docs/research/2026-10-01-pwa-khao-sat.md` mục 2.4); ghi tường minh để một lần siết
     * `default-src` không chặn manifest. Máy chủ push (Apple, Google, Mozilla) do TRÌNH DUYỆT gọi,
     * không phải script của trang, nên KHÔNG thuộc `connect-src` — đừng "sửa" bằng cách mở rộng nó.
     *
     * `style-src` CÓ `'unsafe-inline'` — luật style nội tuyến của dự án (không có bước build CSS)
     * và Filament in `style=""` khắp nơi; SPEC chỉ cấm với script. Vì thế cũng KHÔNG được thêm
     * nonce vào `style-src`: có nonce thì trình duyệt bỏ qua `'unsafe-inline'` và mọi `style=""`
     * vỡ. `fonts.bunny.net` ở `style-src` và `font-src` là bộ chữ đã quyết ở SPEC §3.
     *
     * `img-src` KHÔNG có `ui-avatars.com`: ảnh đại diện dựng tại chỗ thành ảnh `data:`
     * ({@see InitialsAvatarProvider}).
     */
    public static function policy(string $nonce): string
    {
        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-{$nonce}'", "'unsafe-eval'"],
            'worker-src' => ["'self'", 'blob:'],
            'manifest-src' => ["'self'"],
            'style-src' => ["'self'", "'unsafe-inline'", 'https://fonts.bunny.net'],
            'font-src' => ["'self'", 'https://fonts.bunny.net', 'data:'],
            'img-src' => ["'self'", 'data:', 'blob:'],
            'connect-src' => ["'self'"],
            'frame-ancestors' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'object-src' => ["'none'"],
        ];

        return collect($directives)
            ->map(fn (array $sources, string $directive): string => $directive.' '.implode(' ', $sources))
            ->implode('; ');
    }
}
