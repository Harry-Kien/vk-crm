<?php

/**
 * Thông điệp lỗi xác thực dữ liệu đầu vào của các Action (khác với lang/vi/exceptions.php,
 * dành cho các domain exception có tên riêng).
 */
return [
    'transition_matter_stage' => [
        'public_content_too_short' => 'Nội dung công khai cho khách phải có ít nhất 30 ký tự khi công bố tiến độ.',
    ],
    'open_matter' => [
        'client_role_required' => 'Phải chọn vai của khách hàng (nguyên đơn/bị đơn/...) trong vụ việc này trước khi mở vụ việc — không có mặc định, vì mặc định sai sẽ khiến kiểm tra xung đột lợi ích bỏ sót mức đỏ.',
    ],
];
