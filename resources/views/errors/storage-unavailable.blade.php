{{--
    Trang 503 "kho tài liệu tạm thời chưa truy cập được" (kế hoạch M14, R3, R9) — cho CẢ nhân sự (panel
    admin) lẫn khách (cổng), trả bởi `bootstrap/app.php` khi một request gặp `DocumentStorageUnavailable`
    hay `DocumentStorageMisconfigured`, kèm header `Retry-After: 120`.

    Chép khuôn `errors/404.blade.php` (không sửa nó — làn M12 cũng sửa tệp đó), cùng ba luật:

    1. **Không chi tiết kỹ thuật.** Không `$exception->getMessage()`, không mã tệp, không khoá: câu chữ cố
       định từ `lang/vi/storage.php`, đúng câu R9 hứa ("Tài liệu vẫn được lưu an toàn").
    2. **Một đường đi tiếp không qua một trang.** Với KHÁCH: số điện thoại văn phòng (`OfficeProfile`).
       Nhân sự (guard `web` có phiên) là người của chính văn phòng: không cần số tổng đài, chỉ cần quay
       lại sau ít phút.
    3. **Kiểu dáng nội tuyến, mọi biến màu có giá trị dự phòng** (không có bước dựng CSS; trang trả về
       ngoài Filament nên `theme.css` có thể chưa được nạp).
--}}

@php
    $forStaff = auth('web')->check();
    $hotline = $forStaff ? null : App\Support\OfficeProfile::current()->hotline();
    $tap = 'min-height:44px;display:inline-flex;align-items:center;justify-content:center;padding:0.625rem 1rem;border-radius:0.5rem;text-decoration:none;font-weight:600;';
@endphp
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('storage.unavailable_page.title') }}</title>
</head>
<body style="margin:0;padding:1.5rem;background-color:var(--gray-50, #f9fafb);color:var(--gray-950, #0f172a);font-family:ui-sans-serif, system-ui, 'Segoe UI', Roboto, sans-serif;line-height:1.6;">
    <main style="max-width:32rem;margin:0 auto;padding:1.5rem;border-radius:0.75rem;background-color:var(--gray-25, #ffffff);border:1px solid color-mix(in srgb, var(--gray-500, #6b7280) 30%, transparent);">
        <h1 style="margin:0;font-size:1.375rem;font-weight:700;">{{ __('storage.unavailable_page.heading') }}</h1>

        <p style="margin-top:0.75rem;">{{ __('storage.exceptions.unavailable') }}</p>

        @if (filled($hotline))
            <p style="margin-top:1.25rem;margin-bottom:0.25rem;color:color-mix(in srgb, var(--gray-500, #6b7280) 95%, transparent);">
                {{ __('storage.unavailable_page.call_lead') }}
            </p>

            <a href="tel:{{ $hotline }}" style="{{ $tap }}background-color:var(--primary-600, #2563eb);color:var(--primary-50, #eff6ff);">
                {{ __('storage.unavailable_page.call', ['hotline' => $hotline]) }}
            </a>
        @endif

        <p style="margin-top:1rem;">
            <a href="{{ url('/') }}" style="{{ $tap }}border:1px solid color-mix(in srgb, var(--gray-500, #6b7280) 40%, transparent);color:var(--primary-600, #2563eb);">
                {{ __('storage.unavailable_page.home') }}
            </a>
        </p>
    </main>
</body>
</html>
