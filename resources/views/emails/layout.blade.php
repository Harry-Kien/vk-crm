{{--
    Layout dùng chung của mọi thư văn phòng gửi đi (SPEC §9).

    Bảng lồng bảng và style nội tuyến, không phải vì cổ điển mà vì đó là thứ duy nhất chạy được
    trên hộp thư: Outlook bỏ qua <style> trong <head>, Gmail cắt CSS ngoài, và dự án cũng không
    có bước dựng CSS nào (CLAUDE.md) để sinh ra một tệp style cho thư.

    Màu, chữ và khối tên cạnh logo lấy từ cấu hình (`vkcrm.brand.colors`, `.font`, `.lockup`,
    `.short_name`) — cùng một nguồn với hai panel và trang đăng nhập, nên đổi nhận diện là đổi một
    chỗ; chúng KHÔNG sửa được trong app.

    Tên pháp lý, hotline, website và bốn thông tin pháp lý ở chân thư lấy từ
    `App\Support\OfficeProfile` (M7 Task 10: trang "Thông tin văn phòng" → bảng `settings` → cấu
    hình), đọc LÚC RENDER, nên thư đang chờ trong hàng đợi mang giá trị mới nhất. Mỗi dòng của chân
    thư chỉ in khi có giá trị: bốn thông tin pháp lý có thể còn trống (chủ văn phòng sẽ tự nhập
    sau), và chúng phải biến mất khỏi thư chứ không được để lại nhãn cụt đuôi hay dòng rỗng — danh
    sách dựng từ `App\Support\BrandFooter::legalLines()`. Đọc docblock của hai lớp đó trước khi sửa.

    Logo dựng từ `App\Support\PortalUrl::asset()`, KHÔNG phải `asset()` của Laravel (vòng sửa 1,
    minor): `asset()` dựng URL theo request hiện tại, hay theo `APP_URL` khi không có request nào
    (đúng ngữ cảnh `queue:work` — mọi thư của M6.5 Task 11 trở đi đều gửi từ một job hàng đợi).
    Một thư CHO KHÁCH dựng ngay sau một request `/admin` có thể mang logo trỏ vào tên miền QUẢN
    TRỊ nếu `APP_URL` trỏ về đó — cùng hình dạng lỗi mà `PortalUrl` đã sửa cho liên kết cổng.
--}}
@php
    $colors = config('vkcrm.brand.colors');
    $font = config('vkcrm.brand.font');
    $lockup = config('vkcrm.brand.lockup');
    $office = App\Support\OfficeProfile::current();
    $legalLines = App\Support\BrandFooter::legalLines($office);
@endphp
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('vkcrm.brand.short_name') }}</title>
</head>
<body style="margin:0; padding:0; background-color:{{ $colors['paper'] }};">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:{{ $colors['paper'] }};">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:600px; background-color:#ffffff; border-radius:6px; overflow:hidden;">
                <tr>
                    <td style="background-color:{{ $colors['navy'] }}; padding:20px 24px;">
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="padding-right:12px;" valign="middle">
                                    <img src="{{ App\Support\PortalUrl::asset('brand/vk-mark-96.png') }}" width="48" height="48" alt="{{ __('emails.logo_alt') }}" style="display:block; width:48px; height:48px; border:0;">
                                </td>
                                <td valign="middle">
                                    <div style="color:#ffffff; font-family:'{{ $font }}', Arial, sans-serif; font-size:16px; font-weight:700; line-height:20px;">{{ $lockup['entity'] }} {{ $lockup['name'] }}</div>
                                    <div style="color:#c8cede; font-family:'{{ $font }}', Arial, sans-serif; font-size:13px; line-height:18px;">{{ $lockup['suffix'] }}</div>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="padding:28px 24px; color:{{ $colors['deep'] }}; font-family:'{{ $font }}', Arial, sans-serif; font-size:15px; line-height:24px;">
                        @yield('content')
                    </td>
                </tr>
                <tr>
                    <td style="border-top:3px solid {{ $colors['red'] }}; background-color:{{ $colors['paper'] }}; padding:20px 24px; color:{{ $colors['muted'] }}; font-family:'{{ $font }}', Arial, sans-serif; font-size:12px; line-height:19px;">
                        @if (filled($office->legalName()))
                            <div style="color:{{ $colors['navy'] }}; font-size:13px; font-weight:700;">{{ $office->legalName() }}</div>
                        @endif
                        @foreach ($legalLines as $line)
                            <div>{{ $line }}</div>
                        @endforeach
                        @if (filled($office->hotline()))
                            <div>{{ __('emails.footer.hotline', ['value' => $office->hotline()]) }}</div>
                        @endif
                        @if (filled($office->website()))
                            <div>{{ __('emails.footer.website', ['value' => $office->website()]) }}</div>
                        @endif
                        <div style="padding-top:10px;">{{ __('emails.footer.automated') }}</div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
