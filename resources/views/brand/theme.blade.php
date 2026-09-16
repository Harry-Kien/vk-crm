{{--
    Lớp nhận diện mỏng phủ lên giao diện dựng sẵn của Filament, để hệ thống trông liền một mạch
    với luatvukhang.com. Mọi giá trị dưới đây đọc TRỰC TIẾP từ CSS của chính website, không phải
    phỏng theo trí nhớ.

    **Vì sao tiêm qua render hook chứ không dựng theme riêng.** Theme Filament phải biên dịch bằng
    Node/Vite; máy dev và máy chủ của dự án này chỉ có PHP trong Docker (xem CLAUDE.md), nên một
    theme biên dịch sẽ là một bước dựng nữa phải bảo trì suốt đời hệ thống để đổi vài token. Ở đây
    chỉ ghi đè token, không đụng vào cấu trúc lớp của Filament, nên một lần nâng cấp Filament
    không làm vỡ gì — cùng lắm là một token đổi tên và giao diện quay về mặc định, thấy được ngay.

    **Góc vuông là chữ ký thị giác của thương hiệu này, không phải sở thích.** CSS của website chỉ
    dùng `border-radius: 0` và `3px`; 999px chỉ dành cho nhãn tròn. Filament mặc định bo tròn mọi
    thứ (`--radius-lg` = .5rem), nên nếu để nguyên thì hệ thống trông như một phần mềm SaaS bất kỳ
    dán tên văn phòng. Ghi đè bốn token bán kính là đủ vì mọi thành phần của Filament đều tham
    chiếu chúng — sau đó trả lại dáng viên thuốc cho riêng `.fi-badge`, đúng như website làm.

    **Chữ có chân cho tiêu đề.** Website dùng Noto Serif cho tiêu đề và Be Vietnam Pro cho phần
    còn lại. Panel đã nạp Be Vietnam Pro qua `->font()`; ở đây nạp thêm bộ chữ có chân và chỉ áp
    cho hai lớp tiêu đề, không áp cho bảng biểu — bảng dày đặc số liệu thì chữ không chân dễ đọc
    hơn, và đó cũng là cách website dùng hai bộ chữ.
--}}
<link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
<link rel="stylesheet" href="https://fonts.bunny.net/css?family=noto-serif:600,700&display=swap">

<style>
    :root {
        /* Bán kính: website chỉ dùng 0 và 3px. */
        --radius-sm: 2px;
        --radius-md: 3px;
        --radius-lg: 3px;
        --radius-xl: 4px;

        --vk-serif: "Noto Serif", "Times New Roman", Times, serif;
        --vk-red: {{ config('vkcrm.brand.colors.red') }};
    }

    /* Nhãn trạng thái giữ dáng viên thuốc, đúng như trên website. */
    .fi-badge { border-radius: 999px; }

    /* Tiêu đề trang và tiêu đề khối dùng chữ có chân, siết khoảng chữ như website
       (letter-spacing: -.035em ở các cỡ lớn). */
    .fi-header-heading,
    .fi-simple-header-heading,
    .fi-section-header-heading {
        font-family: var(--vk-serif);
        font-weight: 700;
        letter-spacing: -0.02em;
    }

    /* Trang đăng nhập đã có logo ngay trên tiêu đề nên không cần vạch đỏ nữa — hai dấu hiệu
       chồng lên nhau trong một khung hẹp thì thành rối, không thành nhận diện. */
    .fi-simple-header-heading { text-align: center; }

    /* Vạch đỏ thương hiệu dưới tiêu đề trang — chi tiết duy nhất mang màu đỏ ở giao diện nội bộ,
       vì đỏ ở đây còn phải để dành cho cảnh báo. */
    .fi-header-heading::after {
        content: "";
        display: block;
        width: 2.25rem;
        height: 2px;
        margin-top: 0.45rem;
        background: var(--vk-red);
    }
</style>
