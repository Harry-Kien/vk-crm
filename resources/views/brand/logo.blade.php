{{--
    Logo thật của Công ty Luật TNHH Vũ Khang Solutions & Partners, do văn phòng cung cấp.

    **Vì sao là <img> + chữ, không phải một tệp ảnh duy nhất.** Logo gốc là con dấu tròn, vuông
    khổ. Đặt nguyên nó vào thanh bên cao khoảng 2rem thì chữ "VK" bên trong nhỏ tới mức không đọc
    được, và tên văn phòng biến mất hoàn toàn. Nên ở đây dùng đúng cách một bộ nhận diện được dựng:
    con dấu giữ nguyên tỉ lệ vuông, tên văn phòng đặt cạnh bằng chữ thật — vừa đọc được ở mọi cỡ,
    vừa cho phép tên đổi màu theo nền sáng/tối, việc mà một tệp ảnh không làm được.

    **Nền quanh vành vàng đã được cắt trong suốt** (xem tools/brand/make-logo.php): ảnh
    gốc có nền trắng, để nguyên thì ở giao diện tối nó thành một ô trắng vuông giữa thanh bên. Phần
    trắng BÊN TRONG vành vàng thì giữ lại, vì đó là một phần của con dấu chứ không phải nền.

    Muốn đổi logo: thay tools/brand/vk-logo-source.jpg rồi chạy
    `bin/dev php tools/brand/make-logo.php`, không cần sửa tệp này.
--}}
<div style="display:flex;align-items:center;gap:0.6rem;height:100%;line-height:1">
    <img
        src="{{ asset('brand/vk-mark-256.png') }}"
        alt="{{ config('vkcrm.brand.legal_name') }}"
        style="height:100%;width:auto;display:block;flex:none"
    >

    <span style="display:flex;flex-direction:column;justify-content:center;gap:0.14em;min-width:0">
        <span style="font-family:'Be Vietnam Pro',system-ui,sans-serif;font-size:0.95rem;
                     font-weight:700;letter-spacing:0.09em;color:currentColor;white-space:nowrap">
            VŨ KHANG
        </span>
        <span style="font-family:'Be Vietnam Pro',system-ui,sans-serif;font-size:0.5rem;
                     font-weight:500;letter-spacing:0.17em;opacity:0.62;color:currentColor;
                     white-space:nowrap">
            SOLUTIONS &amp; PARTNERS
        </span>
    </span>
</div>
