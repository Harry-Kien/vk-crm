<?php

/**
 * Thông điệp lỗi xác thực dữ liệu đầu vào của các Action (khác với lang/vi/exceptions.php,
 * dành cho các domain exception có tên riêng).
 */
return [
    // Tiêu đề chung của mọi thông báo "thao tác không thực hiện được" trên panel admin (xem
    // `App\Filament\Admin\Concerns\ReportsActionFailures`). Cố tình KHÔNG nói vì sao ở tiêu đề:
    // lý do nằm ở phần thân, nguyên văn câu mà Action đã viết cho đúng tình huống đó, và một
    // tiêu đề tự tóm tắt lại sẽ luôn tóm tắt sai một trong số chúng.
    'failed_title' => 'Chưa thực hiện được thao tác này',

    'transition_matter_stage' => [
        'public_content_too_short' => 'Nội dung công khai cho khách phải có ít nhất 30 ký tự khi công bố tiến độ.',
        'occurred_at_future' => 'Ngày xảy ra không được ở tương lai.',
    ],
    'open_matter' => [
        'client_role_required' => 'Phải chọn vai của khách hàng (nguyên đơn/bị đơn/...) trong vụ việc này trước khi mở vụ việc — không có mặc định, vì mặc định sai sẽ khiến kiểm tra xung đột lợi ích bỏ sót mức đỏ.',
    ],
];
