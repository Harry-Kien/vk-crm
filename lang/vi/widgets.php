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
    // M6.5 Task 14 (`deadlines/F5`, `spec-gap-05`): mục 2 của SPEC §7.1, rơi mất giữa các
    // milestone cho tới đây — xem docblock `App\Filament\Admin\Widgets\UpcomingDeadlinesWidget`.
    'upcoming_deadlines' => [
        'heading' => 'Mốc thời hạn 7 ngày tới',
        'description' => '7 ngày tới, cộng mốc quá hạn chưa xong.',
        'empty_state' => 'Không có mốc thời hạn nào sắp tới hoặc quá hạn.',
        'columns' => [
            'due_date' => 'Ngày đến hạn',
            'code' => 'Mã hồ sơ',
            'client' => 'Khách hàng',
            'name' => 'Nội dung',
            'severity' => 'Mức độ',
            'responsible' => 'Người phụ trách',
        ],
        'open' => 'Mở hồ sơ',
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
    'system_health' => [
        'never_ran' => 'Hệ thống nhắc việc chưa từng chạy',
        'never_ran_hint' => 'Chưa có dòng lịch tự động nào chạy trên máy chủ này, nên hệ thống chưa nhắc được mốc thời hạn nào. Nhờ người quản trị kiểm tra lại dòng cron.',
        'stale' => 'Hệ thống nhắc việc đã ngừng chạy',
        'stale_hint' => 'Lần chạy gần nhất là :at, quá :minutes phút trước. Trong lúc này hệ thống không nhắc mốc thời hạn và không gửi thư nào. Nhờ người quản trị kiểm tra lại dòng cron.',
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
