<?php

/**
 * Nhãn tiếng Việt cho các widget trang chủ panel admin (SPEC §7.1). Mục 1 và 6 làm ở M3, mục 3
 * và 4 ở M4 (cần bảng `matter_checklist_items` có dữ liệu thật); mục 2, 5 và 7 cần mốc thời hạn,
 * `stage_log_views` và heartbeat — M6.
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
    'pending_checklist_reviews' => [
        'heading' => 'Tài liệu chờ duyệt',
        'description' => 'Khách đã nộp, chưa ai xem.',
        'columns' => [
            'code' => 'Mã hồ sơ',
            'client' => 'Khách hàng',
            'item' => 'Giấy tờ',
            'submitted_at' => 'Khách nộp lúc',
        ],
        'empty_state' => 'Không có giấy tờ nào đang chờ duyệt.',
        'never_submitted' => 'Chưa có tệp nào',
        'open' => 'Mở danh mục hồ sơ',
    ],
    'matters_missing_documents' => [
        'heading' => 'Hồ sơ thiếu giấy tờ quá 14 ngày',
        'description' => 'Hồ sơ đang tắc vì khách chưa nộp.',
        'columns' => [
            'code' => 'Mã hồ sơ',
            'client' => 'Khách hàng',
            'title' => 'Tiêu đề',
            'lead_lawyer' => 'Luật sư phụ trách',
            'outstanding' => 'Giấy tờ còn thiếu',
            'missing_since' => 'Thiếu từ',
        ],
        'empty_state' => 'Không có hồ sơ nào thiếu giấy tờ quá 14 ngày.',
        'open' => 'Mở danh mục hồ sơ',
    ],
    'matter_counts' => [
        'total' => 'Tổng số hồ sơ',
        'total_hint' => 'Toàn bộ hồ sơ anh/chị được xem.',
        'open' => 'Đang xử lý',
        'open_hint' => 'Chưa đóng hồ sơ.',
        'closed' => 'Đã kết thúc',
        'closed_hint' => 'Đã đóng hồ sơ.',
        'opened_this_month' => 'Mở trong tháng này',
        'opened_this_month_hint' => 'Tính từ ngày đầu tháng.',
    ],
    'matters_by_stage' => [
        'heading' => 'Thống kê nhanh',
        'description' => 'Số vụ việc đang mở theo giai đoạn.',
        // Hai loại vụ việc có thể đặt trùng nhãn giai đoạn ("Chuẩn bị hồ sơ" chẳng hạn); nhãn cột
        // vì vậy luôn kèm tên loại, nếu không hai cột khác nhau trông y hệt nhau.
        'stage_label' => ':type — :stage',
    ],
];
