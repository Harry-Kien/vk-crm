<?php

/*
 * Sửa sau kiểm tra nghiệp vụ toàn hệ thống (làn fb, 2026-10-09): khoá truy cập khẩn của nhân sự
 * (mục A5) và tên người đã nghỉ việc trong nhật ký (mục A6). Tệp riêng để không chạm khối của làn
 * khác.
 */
return [
    'departed_name' => ':name (đã nghỉ việc)',
    'deleted_client_user_name' => ':name (tài khoản đã xoá)',

    'suspend' => [
        'label' => 'Khoá truy cập ngay',
        'modal_heading' => 'Khoá truy cập của :name ngay bây giờ?',
        'modal_description' => 'Dùng khi nhân sự nghỉ đột xuất hoặc bị nghi lộ dữ liệu. :name bị đăng xuất khỏi mọi phiên, cookie "ghi nhớ đăng nhập" hết hiệu lực, mọi kết nối AI bị thu hồi, mọi máy nhận thông báo bị gỡ. Việc dở dang (:matters vụ phụ trách, :deadlines mốc hạn, :requests yêu cầu khách) giữ nguyên để bàn giao sau qua "Bàn giao hàng loạt". Mở lại bằng công tắc "Đang hoạt động" trên trang này.',
        'reason' => 'Lý do khoá',
        'reason_help' => 'Ít nhất 20 ký tự. Ghi vào nhật ký hệ thống.',
        'reason_too_short' => 'Lý do khoá cần ít nhất :min ký tự.',
        'already_inactive' => ':name đang bị vô hiệu hoá — không có gì để khoá thêm.',
        'success' => 'Đã khoá truy cập của :name. Việc dở dang vẫn đứng tên người này cho tới khi bàn giao.',
        'form_hint' => 'Cần chặn ngay mà chưa kịp bàn giao (nghỉ đột xuất, nghi lộ dữ liệu)? Dùng nút "Khoá truy cập ngay" ở đầu trang.',
    ],
];
