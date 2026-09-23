{{--
    Layout dùng chung của mọi thư văn phòng gửi đi (SPEC §9).

    Bảng lồng bảng và style nội tuyến, không phải vì cổ điển mà vì đó là thứ duy nhất chạy được
    trên hộp thư: Outlook bỏ qua <style> trong <head>, Gmail cắt CSS ngoài, và dự án cũng không
    có bước dựng CSS nào (CLAUDE.md) để sinh ra một tệp style cho thư.

    Màu và chữ lấy từ `config('vkcrm.brand')` — cùng một nguồn với hai panel và trang đăng nhập,
    nên đổi nhận diện là đổi một chỗ.

    Chân thư dựng từ `App\Support\BrandFooter::legalLines()`: bốn thông tin pháp lý ở
    `config/vkcrm.php` CỐ Ý còn trống (chủ văn phòng chưa cung cấp), và chúng phải biến mất khỏi
    thư chứ không được để lại nhãn cụt đuôi hay dòng rỗng. Đọc docblock của lớp đó trước khi sửa.
--}}
@php
    /** @var array<string, mixed> $brand */
    $brand = config('vkcrm.brand');
    $colors = $brand['colors'];
    $legalLines = App\Support\BrandFooter::legalLines();
@endphp
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $brand['short_name'] }}</title>
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
                                    <img src="{{ asset('brand/vk-mark-96.png') }}" width="48" height="48" alt="{{ __('emails.logo_alt') }}" style="display:block; width:48px; height:48px; border:0;">
                                </td>
                                <td valign="middle">
                                    <div style="color:#ffffff; font-family:'{{ $brand['font'] }}', Arial, sans-serif; font-size:16px; font-weight:700; line-height:20px;">{{ $brand['lockup']['entity'] }} {{ $brand['lockup']['name'] }}</div>
                                    <div style="color:#c8cede; font-family:'{{ $brand['font'] }}', Arial, sans-serif; font-size:13px; line-height:18px;">{{ $brand['lockup']['suffix'] }}</div>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="padding:28px 24px; color:{{ $colors['deep'] }}; font-family:'{{ $brand['font'] }}', Arial, sans-serif; font-size:15px; line-height:24px;">
                        @yield('content')
                    </td>
                </tr>
                <tr>
                    <td style="border-top:3px solid {{ $colors['red'] }}; background-color:{{ $colors['paper'] }}; padding:20px 24px; color:{{ $colors['muted'] }}; font-family:'{{ $brand['font'] }}', Arial, sans-serif; font-size:12px; line-height:19px;">
                        <div style="color:{{ $colors['navy'] }}; font-size:13px; font-weight:700;">{{ $brand['legal_name'] }}</div>
                        @foreach ($legalLines as $line)
                            <div>{{ $line }}</div>
                        @endforeach
                        <div>{{ __('emails.footer.hotline', ['value' => $brand['hotline']]) }}</div>
                        <div>{{ __('emails.footer.website', ['value' => $brand['website']]) }}</div>
                        <div style="padding-top:10px;">{{ __('emails.footer.automated') }}</div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
