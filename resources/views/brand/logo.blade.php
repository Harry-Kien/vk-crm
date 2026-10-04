{{--
    Logo thật của Công ty Luật TNHH Vũ Khang Solutions & Partners, do văn phòng cung cấp.

    **Vì sao là <img> + chữ, không phải một tệp ảnh duy nhất.** Logo gốc là con dấu tròn, vuông
    khổ. Đặt nguyên nó vào thanh bên cao khoảng 3rem thì chữ "VK" bên trong nhỏ tới mức không đọc
    được, và tên văn phòng biến mất hoàn toàn. Nên ở đây dựng đúng cách một bộ nhận diện: con dấu
    giữ nguyên tỉ lệ vuông, tên văn phòng đặt cạnh bằng chữ thật — vừa đọc được ở mọi cỡ, vừa cho
    phép tên đổi màu theo nền sáng/tối, việc mà một tệp ảnh không làm được.

    **Ba dòng, và dòng đầu là loại hình doanh nghiệp.** Với một tổ chức hành nghề luật, "Công ty
    Luật TNHH" là một phần của danh tính pháp lý chứ không phải chữ trang trí — bỏ nó đi thì khối
    nhận diện nói tên một thương hiệu, không nói tên một pháp nhân. Dòng giữa mang trọng lượng thị
    giác, hai dòng ngoài nhỏ và giãn chữ, đúng cách website xếp tên.

    **Nền quanh vành vàng đã được cắt trong suốt** (xem tools/brand/make-logo.php): ảnh gốc nền
    trắng, để nguyên thì ở giao diện tối nó thành một ô trắng vuông giữa thanh bên. Phần trắng BÊN
    TRONG vành vàng thì giữ lại, vì đó là một phần của con dấu chứ không phải nền.

    Muốn đổi logo: thay tools/brand/vk-logo-source.jpg rồi chạy
    `bin/dev php tools/brand/make-logo.php`, không cần sửa tệp này.

    **`alt` là ba dòng lockup ghép lại, không phải tên pháp lý** (M7 Task 10). `alt` mô tả HÌNH —
    khối nhận diện này, thứ không sửa được trong app — chứ không mô tả pháp nhân; với giá trị mặc
    định hai chuỗi trùng nhau từng chữ. Đọc tên pháp lý ở đây (nay sửa được, qua
    `App\Support\OfficeProfile`) sẽ thêm một truy vấn vào MỌI trang của cả hai panel chỉ để lấy chữ
    thay thế cho một ảnh.
--}}
@php($lockup = config('vkcrm.brand.lockup'))

<div style="display:flex;align-items:center;gap:0.62rem;height:100%;line-height:1">
    <img
        src="{{ asset('brand/vk-mark-256.png') }}"
        alt="{{ $lockup['entity'] }} {{ $lockup['name'] }} {{ $lockup['suffix'] }}"
        style="height:100%;width:auto;display:block;flex:none"
    >

    <span style="display:flex;flex-direction:column;justify-content:center;gap:0.18em;min-width:0;
                 font-family:'Be Vietnam Pro',system-ui,sans-serif;color:currentColor">
        <span style="font-size:0.5rem;font-weight:500;letter-spacing:0.15em;text-transform:uppercase;
                     opacity:0.6;white-space:nowrap">{{ $lockup['entity'] }}</span>

        <span style="font-size:0.93rem;font-weight:700;letter-spacing:0.09em;text-transform:uppercase;
                     white-space:nowrap">{{ $lockup['name'] }}</span>

        <span style="font-size:0.5rem;font-weight:500;letter-spacing:0.15em;text-transform:uppercase;
                     opacity:0.6;white-space:nowrap">{{ $lockup['suffix'] }}</span>
    </span>
</div>
