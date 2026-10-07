<?php

/*
 * M12 — app trên điện thoại (PWA). `:firm` là `config('vkcrm.brand.short_name')`.
 *
 * `name` hiện ở hộp thoại cài đặt của Chrome; `short_name` hiện dưới biểu tượng trên màn hình
 * chính (Android) và là `apple-mobile-web-app-title` (tên iOS đề xuất khi "Thêm vào Màn hình chính").
 *
 * `offline` — trang ngoại tuyến (R4, `resources/views/pwa/offline.blade.php`), cài vào bộ đệm của
 * service worker lúc cài app và hiện khi một lần điều hướng gặp lỗi mạng. Trang tĩnh, không dữ
 * liệu phiên: không câu nào ở đây nhắc tới khách, hồ sơ hay người đang đăng nhập.
 */
return [
    'admin' => [
        'name' => ':firm — Nội bộ',
        'short_name' => 'VK Nội bộ',
    ],
    'portal' => [
        'name' => ':firm — Khách hàng',
        'short_name' => ':firm',
    ],
    'offline' => [
        'heading' => 'Chưa có kết nối mạng',
        // Vòng sửa cuối I1: không hứa "hồ sơ không được lưu trên máy" — tài liệu khách chủ động tải về
        // nằm lại trong thư mục tải xuống (QUY-TRINH, "Điều nên biết"); không nói "Điện thoại": trang
        // này cũng hiện trên máy tính đã cài app.
        'body' => 'Máy đang không kết nối được Internet nên chưa mở được trang này. Ứng dụng không giữ bản sao hồ sơ để xem khi mất mạng: khi có mạng trở lại, mọi thông tin hiện ra như bình thường.',
        'retry' => 'Thử lại',
        'call_lead' => 'Cần việc gấp? Gọi văn phòng:',
        'call' => 'Gọi :hotline',
    ],
];
