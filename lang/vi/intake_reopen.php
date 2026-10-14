<?php

/*
 * Làn fa (kiểm tra nghiệp vụ 2026-10-09, mục nhỏ) — mở lại một lần tiếp nhận đã "Khách không theo
 * tiếp" hay "Văn phòng từ chối", và xác nhận trước khi đặt "Khách không theo tiếp". Tệp riêng để không
 * chạm `intake.php` mà các làn khác cũng sửa.
 */
return [
    'action' => 'Mở lại',
    'heading' => 'Mở lại bản ghi',
    'description' => 'Bản ghi về "Đã liên hệ" và không còn bị xoá dữ liệu khi hết hạn lưu. Nếu văn phòng đã từ chối vì xung đột, bản ghi vẫn khoá như một Đỏ chờ xử lý cho tới khi trưởng phòng hoặc quản trị ghi đè kèm lý do.',
    'reason' => 'Lý do mở lại',
    'reason_help' => 'Ví dụ: khách gọi lại muốn tiếp tục; bấm nhầm. Ghi vào nhật ký.',
    'success' => 'Đã mở lại bản ghi.',
    'confirm_lost' => 'Tôi xác nhận khách không theo tiếp',
    'confirm_lost_help' => '"Khách không theo tiếp" là trạng thái cuối: bản ghi đóng lại và bị xoá dữ liệu khi hết hạn lưu. Chỉ trưởng phòng hoặc quản trị mở lại được.',
    'errors' => [
        'reason_length' => 'Lý do mở lại cần từ :min đến :max ký tự.',
        'not_reopenable' => 'Không mở lại được một bản ghi ở trạng thái ":status", hoặc đã gộp, đã ẩn danh, đã chuyển thành vụ việc.',
        // Sửa giờ nhận (mục nhỏ, cùng làn): chỉ trưởng phòng/quản trị.
        'received_at_invalid' => 'Thời điểm nhận không hợp lệ.',
        'received_at_after_response' => 'Thời điểm nhận không được sau lần phản hồi đầu (:at).',
    ],
];
