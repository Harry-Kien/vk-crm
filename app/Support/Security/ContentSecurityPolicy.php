<?php

namespace App\Support\Security;

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
 * Một giá trị lạ (gõ sai `enforced`, viết hoa…) rơi về `enforce`: lỗi gõ ở `.env` production
 * không được phép lặng lẽ tắt CSP.
 */
final class ContentSecurityPolicy
{
    public const MODE_OFF = 'off';

    public const MODE_REPORT = 'report';

    public const MODE_ENFORCE = 'enforce';

    public const HEADER_ENFORCE = 'Content-Security-Policy';

    public const HEADER_REPORT = 'Content-Security-Policy-Report-Only';

    public static function mode(): string
    {
        $mode = strtolower(trim((string) config('vkcrm.security.csp_mode')));

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
     * phải mang nonce — của Livewire tự gắn qua `Vite::cspNonce()`, của Filament qua các view đã
     * giữ riêng ở `resources/views/vendor/`.
     *
     * `style-src` CÓ `'unsafe-inline'` — luật style nội tuyến của dự án (không có bước build CSS)
     * và Filament in `style=""` khắp nơi; SPEC chỉ cấm với script. Vì thế cũng KHÔNG được thêm
     * nonce vào `style-src`: có nonce thì trình duyệt bỏ qua `'unsafe-inline'` và mọi `style=""`
     * vỡ. `fonts.bunny.net` ở `style-src` và `font-src` là bộ chữ đã quyết ở SPEC §3.
     */
    public static function policy(string $nonce): string
    {
        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-{$nonce}'"],
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
