<?php

/**
 * M7 Task 9 — trang "Tìm kiếm" của admin (SPEC §6.13). Một câu "không tìm thấy" cho MỌI trường hợp
 * không có dòng nào: không có hồ sơ khớp, và có hồ sơ khớp mà người này không được xem — hai trường
 * hợp đó không được phân biệt (R7).
 */
return [
    'navigation_label' => 'Tìm kiếm',
    'page_title' => 'Tìm kiếm hồ sơ',
    'input_label' => 'Tìm hồ sơ',
    'placeholder' => 'Mã hồ sơ, tên khách, tên một bên, số thụ lý…',
    'submit' => 'Tìm',
    'searching_in' => 'Tìm trong: :sources.',
    'too_short' => 'Gõ ít nhất :min ký tự để tìm.',
    'no_results' => 'Không tìm thấy hồ sơ nào khớp.',
    'truncated' => 'Chỉ hiện :limit hồ sơ mới nhất. Gõ cụ thể hơn để thu hẹp.',
    'matched_in' => 'Khớp',

    'sources' => [
        'code' => 'mã hồ sơ',
        'title' => 'tiêu đề vụ việc',
        'client_name' => 'tên khách hàng',
        'case_number' => 'số thụ lý',
        'party_name' => 'tên các bên',
        'document_title' => 'tiêu đề tài liệu',
    ],
];
