{{--
    Dấu hiệu nhận diện của Công ty Luật TNHH Vũ Khang Solutions & Partners, đặt trong panel qua
    `brandLogo()` dưới dạng SVG NỘI TUYẾN (không phải <img>) vì hai lý do: chữ trong SVG nội tuyến
    kế thừa được webfont Be Vietnam Pro mà panel đã nạp, và `currentColor` cho phép cùng một tệp
    hiển thị đúng ở cả nền sáng lẫn nền tối mà không cần bản logo thứ hai.

    Chữ "VK" là một MẶT NẠ khoét thủng ô vuông chứ không phải chữ trắng đè lên: nếu tô trắng, ở
    giao diện tối ô vuông cũng sáng và chữ biến mất.

    Thay bằng logo thật: đặt tệp vào `public/brand/logo.svg` rồi trỏ `brandLogo()` sang đường dẫn
    đó trong AdminPanelProvider/PortalPanelProvider — không cần sửa tệp này.
--}}
<svg viewBox="0 0 296 48" role="img" aria-label="{{ config('vkcrm.brand.legal_name') }}"
     style="height:100%;width:auto;display:block" xmlns="http://www.w3.org/2000/svg">
    <mask id="vk-monogram">
        <rect x="0" y="2" width="44" height="44" rx="9" fill="#fff"/>
        <text x="22" y="31" text-anchor="middle" fill="#000"
              font-family="'Be Vietnam Pro',system-ui,sans-serif" font-size="19" font-weight="700"
              letter-spacing="0.5">VK</text>
    </mask>

    <rect x="0" y="2" width="44" height="44" rx="9" fill="currentColor" mask="url(#vk-monogram)"/>
    {{-- Vạch đỏ thương hiệu, lấy đúng màu --red của luatvukhang.com; đỏ này đọc được trên cả hai nền. --}}
    <rect x="9" y="39" width="26" height="3" rx="1.5" fill="#c6283d" mask="url(#vk-monogram)"/>

    <text x="58" y="25" fill="currentColor"
          font-family="'Be Vietnam Pro',system-ui,sans-serif" font-size="20" font-weight="700"
          letter-spacing="1.6">VŨ KHANG</text>
    <text x="58" y="40" fill="currentColor" opacity="0.6"
          font-family="'Be Vietnam Pro',system-ui,sans-serif" font-size="9" font-weight="500"
          letter-spacing="2.6">SOLUTIONS &amp; PARTNERS</text>
</svg>
