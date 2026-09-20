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

    // Câu DUY NHẤT cho mọi lần `Gate` từ chối bên trong một Action (`AuthorizationException`).
    // Ba nguyên nhân thật — hồ sơ bị xoá mềm, người nộp bị gỡ khỏi đội ngũ, quyền công bố bị thu
    // hồi — cố ý KHÔNG phân biệt được với nhau ở đây, và câu này cũng không khẳng định bản ghi
    // có tồn tại hay không: SPEC §10.10 cấm mọi thông điệp lỗi tiết lộ sự tồn tại của một bản
    // ghi, và một câu riêng cho từng nguyên nhân chính là một máy dò. Xem
    // `App\Filament\Admin\Concerns\ReportsActionFailures`.
    'unauthorized' => 'Màn hình bạn đang mở không còn khớp với dữ liệu và quyền hiện tại, nên thao tác đã dừng lại và không có gì được lưu. Hãy tải lại trang rồi thử lại; nếu vẫn không được, nhờ người phụ trách hồ sơ hoặc quản trị viên.',

    'transition_matter_stage' => [
        'public_content_too_short' => 'Nội dung công khai cho khách phải có ít nhất 30 ký tự khi công bố tiến độ.',
        'occurred_at_future' => 'Ngày xảy ra không được ở tương lai.',
    ],
    'open_matter' => [
        'client_role_required' => 'Phải chọn vai của khách hàng (nguyên đơn/bị đơn/...) trong vụ việc này trước khi mở vụ việc — không có mặc định, vì mặc định sai sẽ khiến kiểm tra xung đột lợi ích bỏ sót mức đỏ.',
    ],
];
