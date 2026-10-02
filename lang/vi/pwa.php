<?php

/*
 * M12 — app trên điện thoại (PWA). `:firm` là `config('vkcrm.brand.short_name')`.
 *
 * `name` hiện ở hộp thoại cài đặt của Chrome; `short_name` hiện dưới biểu tượng trên màn hình
 * chính (Android) và là `apple-mobile-web-app-title` (tên iOS đề xuất khi "Thêm vào Màn hình chính").
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
];
