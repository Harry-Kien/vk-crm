{{--
    Trang trả lời chung cho "không tìm thấy" VÀ "không có quyền" — SPEC §10.10.

    # Vì sao tệp này tồn tại

    Laravel có sẵn một trang 404, và nó bằng TIẾNG ANH. Trên cổng khách hàng thì lời từ chối
    không phải một trường hợp hiếm gặp: `MatterProgress`, `MyRequests` và `SubmitDocument` đều
    trả lời 404 cho mọi tình huống "id này không phải của anh/chị", và tài khoản bị vô hiệu hoá
    giữa chừng (SPEC §10.9) cũng rơi vào đây. Livewire vẽ nguyên nội dung response lỗi vào một
    hộp đè lên màn hình, nên câu tiếng Anh ấy hiện ra đúng lúc người đọc đang bối rối nhất.

    # Ba luật của tệp này, và cả ba đều đo được

    1. **Không nói gì về thứ vừa được hỏi tới.** Không tên, không mã hồ sơ, không phân biệt "không
       có" với "không được xem", và KHÔNG `$exception->getMessage()` — thông điệp của một
       `AuthorizationException` là chữ viết cho lập trình viên và đôi khi kể ra chính thứ SPEC
       §10.10 bắt phải giấu. `MatterProgressTest` ghim hai tình huống khác nhau phải trả về đúng
       TỪNG BYTE cùng một trang; bất kỳ thứ gì thay đổi theo ngữ cảnh ở đây cũng làm test đó đỏ,
       và nó đỏ vì một lý do đúng.

    2. **Một đường đi tiếp KHÔNG qua một trang.** Người đang đọc câu này vừa không mở được một
       trang, nên chỉ đưa thêm một cái nút là đưa họ quay lại đúng chỗ vừa hỏng. Số điện thoại
       văn phòng là đường duy nhất ở đây chắc chắn còn dùng được.

    3. **Kiểu dáng nội tuyến, và mọi biến màu có giá trị dự phòng.** Dự án KHÔNG có bước dựng CSS
       (CLAUDE.md), nên một lớp Tailwind viết tay không tô gì cả. Khác các trang trong panel: tệp
       này được trả về NGOÀI Filament, nên `theme.css` có thể chưa hề được nạp và `var(--gray-500)`
       khi ấy không phân giải ra gì — vì vậy mỗi `var()` ở đây mang một giá trị dự phòng thật.
       Trong panel thì biến của Filament thắng, ngoài panel thì giá trị dự phòng đỡ.

    # Nút "Về trang chính" trỏ `start_url` của CHÍNH app (M12 Task 3 vòng sửa 1)

    Không `url('/')`: `/` chuyển hướng tới đăng nhập của KHÁCH, ngoài scope của app nội bộ đã cài
    (và `/` ngoài scope `/portal` của app khách) — trên iPhone, chạm nút đó trong cửa sổ app mở một
    tấm Safari ngoài app mà không có đường về. Từ Task 3, liên kết tải mở trong CÙNG cửa sổ nên một
    liên kết bị từ chối mở trang này ngay trong app. `App\Support\Pwa\PwaPanels::startUrlFor()` chỉ
    đọc path, panel hiện hành và IP (giới hạn IP của admin thắng), không đọc bản ghi — luật 1 ở trên
    đứng nguyên: hai lời từ chối trong cùng một app vẫn ra đúng từng byte cùng một trang.
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
    <title>{{ __('portal_progress.not_found.heading') }}</title>
</head>
<body style="margin:0;padding:1.5rem;background-color:var(--gray-50, #f9fafb);color:var(--gray-950, #0f172a);font-family:ui-sans-serif, system-ui, 'Segoe UI', Roboto, sans-serif;line-height:1.6;">
    <main style="max-width:32rem;margin:0 auto;padding:1.5rem;border-radius:0.75rem;background-color:var(--gray-25, #ffffff);border:1px solid color-mix(in srgb, var(--gray-500, #6b7280) 30%, transparent);">
        <h1 style="margin:0;font-size:1.375rem;font-weight:700;">{{ __('portal_progress.not_found.heading') }}</h1>

        <p style="margin-top:0.75rem;">{{ __('portal_progress.not_found.body') }}</p>

        <p style="margin-top:1.25rem;margin-bottom:0.25rem;color:color-mix(in srgb, var(--gray-500, #6b7280) 95%, transparent);">
            {{ __('portal_progress.not_found.call_lead') }}
        </p>

        <a href="tel:{{ $hotline }}" style="{{ $tap }}background-color:var(--primary-600, #2563eb);color:var(--primary-50, #eff6ff);">
            {{ __('portal_progress.not_found.call', ['hotline' => $hotline]) }}
        </a>

        <p style="margin-top:1rem;">
            <a href="{{ $home }}" style="{{ $tap }}border:1px solid color-mix(in srgb, var(--gray-500, #6b7280) 40%, transparent);color:var(--primary-600, #2563eb);">
                {{ __('portal_progress.not_found.home') }}
            </a>
        </p>
    </main>
</body>
</html>
