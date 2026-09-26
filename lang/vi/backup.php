<?php

/**
 * Sao lưu (SPEC §10 mục 8, R3; M8a Task 1) — thông điệp chặn `backup:run` và nội dung thư báo
 * lỗi (`App\Mail\Staff\BackupAlert`, `App\Notifications\Backup\*`). KHÔNG BAO GIỜ thêm mật khẩu
 * hay bất kỳ bí mật nào vào các khoá dưới đây — thư báo lỗi nêu tên disk và thông điệp lỗi,
 * không nêu `BACKUP_ARCHIVE_PASSWORD`.
 */
return [
    'errors' => [
        'password_required_in_production' => 'Không thể chạy sao lưu ở môi trường production: '
            .'chưa cấu hình BACKUP_ARCHIVE_PASSWORD. Đặt biến môi trường này rồi chạy lại — '
            .'không được phép tạo bản sao lưu không mã hoá.',
        'encryption_unavailable_in_production' => 'Không thể chạy sao lưu ở môi trường production: '
            .'máy chủ không mã hoá được archive bằng thuật toán đã cấu hình (thường do thư viện '
            .'libzip của PHP quá cũ, thiếu AES-256). Nâng cấp libzip/PHP zip rồi chạy lại — không '
            .'được phép tạo bản sao lưu không mã hoá.',
    ],

    'email' => [
        'subject' => [
            'backup_failed' => 'Sao lưu thất bại — đĩa :disk',
            'cleanup_failed' => 'Dọn dẹp bản sao lưu cũ thất bại',
            'unhealthy' => 'Bản sao lưu không lành mạnh — đĩa :disk',
        ],
        'heading' => [
            'backup_failed' => 'Một lượt sao lưu đã thất bại.',
            'cleanup_failed' => 'Dọn dẹp bản sao lưu cũ đã thất bại.',
            'unhealthy' => 'Hệ thống giám sát phát hiện một bản sao lưu không lành mạnh.',
        ],
        'disk' => 'Đĩa: :disk',
        'detail' => 'Chi tiết lỗi: :detail',
        'action' => 'Anh/chị kiểm tra lại cấu hình sao lưu và đĩa lưu trữ liên quan càng sớm càng tốt.',
        'salutation' => 'Hệ thống VK-CRM — :office',
    ],
];
