<?php

/**
 * Nhãn tiếng Việt cho các widget trang chủ panel admin (SPEC §7.1). Chỉ mục 1 và 6 làm ở M3;
 * các mục còn lại cần dữ liệu chưa có (M4/M6).
 */
return [
    'stale_matters' => [
        'heading' => 'Hồ sơ quá hạn cập nhật',
        'description' => 'Chưa cập nhật cho khách quá 14 ngày.',
        'columns' => [
            'code' => 'Mã hồ sơ',
            'client' => 'Khách hàng',
            'title' => 'Tiêu đề',
            'stage' => 'Giai đoạn',
            'lead_lawyer' => 'Luật sư phụ trách',
            'last_client_update_at' => 'Cập nhật gần nhất cho khách',
        ],
        'empty_state' => 'Không có hồ sơ nào quá hạn cập nhật.',
    ],
    'matters_by_stage' => [
        'heading' => 'Thống kê nhanh',
        'description' => 'Số vụ việc đang mở theo giai đoạn.',
    ],
];
