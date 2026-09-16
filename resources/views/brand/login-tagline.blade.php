{{--
    Câu định vị của văn phòng, đặt ngay dưới logo ở trang đăng nhập của cả hai panel.

    Với cổng khách hàng, đây là màn hình đầu tiên khách nhìn thấy khi được cấp tài khoản: họ vừa
    nhận một đường dẫn lạ qua email và đang phải quyết định có gõ mật khẩu vào đó không. Tên pháp
    lý đầy đủ và câu định vị y như trên luatvukhang.com là thứ trả lời câu hỏi đó trong một giây.

    Kiểu dáng viết thẳng trong thẻ (không dùng lớp Tailwind) vì panel Filament nạp CSS dựng sẵn
    của riêng nó, không nạp bundle Vite của ứng dụng — một lớp tự đặt tên sẽ không có luật nào.
--}}
<p style="margin:-0.35rem 0 0;text-align:center;font-size:0.8125rem;line-height:1.5;
          letter-spacing:0.01em;color:rgb(107 114 128);font-style:italic">
    {{ config('vkcrm.brand.tagline') }}
</p>
