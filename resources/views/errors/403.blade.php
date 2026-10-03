{{--
    Trang trả lời cho "chữ ký của đường dẫn không dùng được" — 403.

    # Vì sao tệp này tồn tại, và vì sao nó KHÔNG giống trang 404

    Trang này được viết cho 403 của middleware `signed` trên route `documents.download` và hai
    bí danh trong scope của nó, `/admin/documents/{id}/download` và `/portal/documents/{id}/download`
    (xem `routes/web.php`). Href tải tệp được nướng vào trang lúc render và hết hạn sau 5 phút, nên
    một khách đọc lịch sử vụ việc của mình trong sáu phút rồi bấm tải sẽ gặp trang này — đó là
    cách dùng bình thường, không phải một lần tấn công. Thứ họ nhận được trước tệp này là trang 403
    mặc định của Laravel: bố cục minh hoạ sẵn có, chữ "Forbidden" và "Invalid signature", tiếng
    Anh, không số điện thoại, không đường quay lại.

    # Ba luật của `404.blade.php` đều được giữ, và MỘT câu được nói thêm

    Giữ: không nói gì về thứ vừa được hỏi tới; một đường đi tiếp KHÔNG qua một trang (số điện
    thoại văn phòng); kiểu dáng nội tuyến với giá trị dự phòng cho mọi biến màu, vì dự án không
    có bước dựng CSS (CLAUDE.md) và tệp này được trả về NGOÀI Filament.

    Nói thêm: **liên kết đã hết hạn, bấm nút về đầu app rồi bấm lại vào tệp**. Câu đó được phép ở
    đây trong khi nó bị cấm ở trang 404, và lý do phải nói rõ vì hai trang đứng cạnh nhau: `signed`
    trả lời TRƯỚC khi một bản ghi nào được đọc, nên câu ấy nói về ĐƯỜNG DẪN chứ không về một tài
    liệu, và nó đúng y như nhau cho một tài liệu có thật lẫn cho một id bịa. `DocumentDownloadTest`
    ghim đúng điều đó bằng một phép so TỪNG BYTE giữa hai response ấy (cùng một route) — nên nó
    không rò rỉ gì dưới SPEC §10.10. Mọi lời từ chối CÓ đọc bản ghi vẫn là 404 và vẫn dùng trang kia.

    # Nút về trỏ `start_url` của CHÍNH app này, và câu chữ chỉ vào đúng nút đó (M12 Task 3 vòng sửa 1)

    Từ M12 Task 3, liên kết tải của cả hai app mở trong CÙNG cửa sổ, nên trang này hiện ra NGAY
    TRONG cửa sổ đang mở — kể cả cửa sổ app đã cài trên điện thoại, và thay cả trang admin đang mở
    trên máy tính. Cửa sổ standalone của iPhone không có nút quay lại hay tải lại, nên câu chữ không
    được dựa vào chúng ("quay lại trang hồ sơ, tải lại trang" là câu cũ): nó bảo bấm nút trên chính
    trang này. Nút đó trỏ `App\Support\Pwa\PwaPanels::startUrlFor()` — `/admin` cho request của app
    nội bộ, `/portal` cho mọi thứ khác (luật đầy đủ ở docblock đó) — chứ không `url('/')`: `/`
    chuyển hướng tới đăng nhập của KHÁCH, ngoài scope của app nội bộ, và trên iPhone mở một tấm
    Safari ngoài app mà không có đường về. `tests/Feature/Pwa/ErrorPageHomeLinkTest.php` ghim cả hai
    bí danh.

    **Trang này không phải lời giải cho một 403 ở nơi khác.** Nếu một màn hình cổng nào đó trả
    403 thì lỗi nằm ở màn hình ấy, không ở đây: SPEC §10.10 đòi cổng từ chối bằng 404, và một 403
    ở đó chính là máy dò sự tồn tại mà §10.10 dựng lên để chặn. Xem docblock
    `MatterProgress::recordViewsOnceTheResponseIsBuilt()` cho một chỗ đã từng làm đúng như thế.
--}}

@php
    $hotline = config('vkcrm.brand.hotline');
    $home = \App\Support\Pwa\PwaPanels::startUrlFor(request());
    $tap = 'min-height:44px;display:inline-flex;align-items:center;justify-content:center;padding:0.625rem 1rem;border-radius:0.5rem;text-decoration:none;font-weight:600;';
@endphp
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('portal_progress.link_expired.heading') }}</title>
</head>
<body style="margin:0;padding:1.5rem;background-color:var(--gray-50, #f9fafb);color:var(--gray-950, #0f172a);font-family:ui-sans-serif, system-ui, 'Segoe UI', Roboto, sans-serif;line-height:1.6;">
    <main style="max-width:32rem;margin:0 auto;padding:1.5rem;border-radius:0.75rem;background-color:var(--gray-25, #ffffff);border:1px solid color-mix(in srgb, var(--gray-500, #6b7280) 30%, transparent);">
        <h1 style="margin:0;font-size:1.375rem;font-weight:700;">{{ __('portal_progress.link_expired.heading') }}</h1>

        <p style="margin-top:0.75rem;">{{ __('portal_progress.link_expired.body') }}</p>

        <p style="margin-top:0.75rem;font-weight:600;">{{ __('portal_progress.link_expired.retry', ['home' => __('portal_progress.link_expired.home')]) }}</p>

        <p style="margin-top:1.25rem;margin-bottom:0.25rem;color:color-mix(in srgb, var(--gray-500, #6b7280) 95%, transparent);">
            {{ __('portal_progress.link_expired.call_lead') }}
        </p>

        <a href="tel:{{ $hotline }}" style="{{ $tap }}background-color:var(--primary-600, #2563eb);color:var(--primary-50, #eff6ff);">
            {{ __('portal_progress.link_expired.call', ['hotline' => $hotline]) }}
        </a>

        <p style="margin-top:1rem;">
            <a href="{{ $home }}" style="{{ $tap }}border:1px solid color-mix(in srgb, var(--gray-500, #6b7280) 40%, transparent);color:var(--primary-600, #2563eb);">
                {{ __('portal_progress.link_expired.home') }}
            </a>
        </p>
    </main>
</body>
</html>
