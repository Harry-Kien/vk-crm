{{--
    M12 R4 — trang ngoại tuyến của app trên điện thoại (`App\Actions\Pwa\RenderOfflinePage`).

    Service worker cài trang này vào bộ đệm lúc `install` và trả nó TẠI URL đang mở khi một lần
    điều hướng gặp lỗi mạng — nên:
     - TĨNH: không script, không form, không token CSRF, không dữ liệu phiên (route không có phiên);
     - mọi đường dẫn là tuyệt đối (`asset()`, `$startUrl`), không tương đối với URL đang mở;
     - chỉ style nội tuyến, có giá trị dự phòng cho mọi biến màu: trang đứng ngoài Filament và
       không có bước dựng CSS (CLAUDE.md), cùng khuôn `resources/views/errors/403.blade.php`;
     - hotline dạng `tel:` (chỉ chữ số và `+`) — đường đi tiếp không cần mạng dữ liệu.
    "Thử lại" trỏ về `start_url` của app (lý do ở docblock Action).
--}}
@php
    $tap = 'min-height:44px;display:inline-flex;align-items:center;justify-content:center;padding:0.625rem 1rem;border-radius:0.5rem;text-decoration:none;font-weight:600;';
@endphp
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="{{ config('vkcrm.brand.colors.navy') }}">
    <title>{{ __('pwa.offline.heading') }} — {{ $title }}</title>
</head>
<body style="margin:0;padding:1.5rem;background-color:var(--gray-50, {{ config('vkcrm.brand.colors.paper') }});color:var(--gray-950, #0f172a);font-family:ui-sans-serif, system-ui, 'Segoe UI', Roboto, sans-serif;line-height:1.6;">
    <main style="max-width:32rem;margin:0 auto;padding:1.5rem;border-radius:0.75rem;background-color:var(--gray-25, #ffffff);border:1px solid color-mix(in srgb, var(--gray-500, #6b7280) 30%, transparent);">
        <img src="{{ $logo }}" alt="{{ $firm }}" width="48" height="48" style="display:block;width:48px;height:48px;">

        <h1 style="margin:1rem 0 0;font-size:1.375rem;font-weight:700;">{{ __('pwa.offline.heading') }}</h1>

        <p style="margin-top:0.75rem;">{{ __('pwa.offline.body') }}</p>

        <p style="margin-top:1rem;">
            <a href="{{ $startUrl }}" style="{{ $tap }}background-color:var(--primary-600, {{ config('vkcrm.brand.colors.navy') }});color:var(--primary-50, #ffffff);">
                {{ __('pwa.offline.retry') }}
            </a>
        </p>

        <p style="margin-top:1.25rem;margin-bottom:0.25rem;color:color-mix(in srgb, var(--gray-500, #6b7280) 95%, transparent);">
            {{ __('pwa.offline.call_lead') }}
        </p>

        <a href="tel:{{ $tel }}" style="{{ $tap }}border:1px solid color-mix(in srgb, var(--gray-500, #6b7280) 40%, transparent);color:var(--primary-600, {{ config('vkcrm.brand.colors.navy') }});">
            {{ __('pwa.offline.call', ['hotline' => $hotline]) }}
        </a>
    </main>
</body>
</html>
