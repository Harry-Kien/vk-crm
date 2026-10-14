<?php

/*
 * Làn fa (kiểm tra nghiệp vụ 2026-10-09, mục nhỏ) — hai thông báo trong hệ thống của tiếp nhận. Chỉ mã
 * bản ghi và nguồn: không tên, số điện thoại hay câu chuyện của người liên hệ.
 */
return [
    'assigned' => [
        'title' => 'Anh/chị vừa được giao một lần tiếp nhận',
        'body' => 'Bản ghi :code (nguồn: :source) vừa được giao cho anh/chị. Mở mục Tiếp nhận để gọi lại người liên hệ.',
    ],
    'red_pending' => [
        'title' => 'Một lần tiếp nhận có xung đột mức Đỏ chờ xử lý',
        'body' => 'Bản ghi :code vừa ra xung đột mức Đỏ. Chỉ trưởng phòng hoặc quản trị xử lý được: từ chối, hoặc ghi đè kèm lý do. Mục Tiếp nhận, bộ lọc "Đỏ chờ trưởng phòng xử lý".',
    ],
];
