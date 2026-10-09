<?php

/*
 * Sửa sau kiểm tra nghiệp vụ toàn hệ thống (làn fb, mục B — "trang Nhật ký hệ thống không tra
 * được"): bộ lọc và nhãn tiếng Việt của trang Nhật ký hệ thống (`ActivityLogPage`). Tệp riêng để
 * không chạm khối của làn khác trong lang/vi/activity.php.
 */
return [
    'filters' => [
        'causer' => 'Người thực hiện',
        'event' => 'Sự kiện',
        'created_between' => 'Khoảng ngày',
        'created_from' => 'Từ ngày',
        'created_until' => 'Đến ngày',
        'matter' => 'Hồ sơ',
    ],

    // Nhãn của LOẠI đối tượng (bí danh morph trong `AppServiceProvider::enforceMorphMap()`).
    'subjects' => [
        'user' => 'Nhân sự',
        'client_user' => 'Tài khoản cổng khách',
        'stage_log' => 'Cập nhật tiến độ',
        'document' => 'Tài liệu',
        'matter' => 'Vụ việc',
        'deadline' => 'Mốc thời hạn',
        'client_request' => 'Yêu cầu của khách',
        'client_request_reply' => 'Trả lời yêu cầu của khách',
        'client' => 'Khách hàng',
        'matter_party' => 'Bên trong vụ việc',
        'matter_checklist_item' => 'Đầu mục giấy tờ',
        'matter_type' => 'Loại vụ việc',
        'contract' => 'Hợp đồng dịch vụ',
        'instalment' => 'Đợt thanh toán',
        'payment' => 'Khoản thu',
        'contract_amendment' => 'Phụ lục hợp đồng',
        'time_entry' => 'Ghi giờ làm',
        'intake_request' => 'Bản ghi tiếp nhận',
        'intake_party' => 'Bên đối lập (tiếp nhận)',
        'communication_log' => 'Nhật ký liên lạc',
        'performance_snapshot' => 'Ảnh chụp số liệu hiệu suất',
    ],

    'log_names' => [
        'default' => 'Chung',
    ],
];
