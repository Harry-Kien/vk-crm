<?php

/**
 * Sao lưu (SPEC §10 mục 8, R3; M8a Task 1 và 2) — thông điệp chặn `backup:run` và nội dung thư báo
 * lỗi (`App\Mail\Staff\BackupAlert`, `App\Notifications\Backup\*`), lỗi lệnh `rclone`
 * (`App\Exceptions\RcloneCommandFailed`) và kết quả lệnh `vkcrm:backup-check`
 * (`App\Actions\Backup\CheckBackupDestinations`). KHÔNG BAO GIỜ thêm mật khẩu, token hay bất kỳ bí
 * mật nào vào các khoá dưới đây — thư báo lỗi và kết quả kiểm tra chỉ nêu tên disk/remote và
 * thông điệp lỗi, không nêu `BACKUP_ARCHIVE_PASSWORD` hay nội dung `rclone.conf`.
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
        'rclone_exit_code' => 'Lệnh rclone thất bại (mã thoát :code): :detail',
        'rclone_process_error' => 'Không chạy được lệnh rclone: :detail',
        'rclone_invalid_output' => 'Lệnh rclone trả về dữ liệu không đọc được (không phải JSON hợp lệ).',
        'rclone_no_output' => '(rclone không trả về thông điệp lỗi)',
        'rclone_verification_mismatch' => 'Không xác minh được archive ":file" đã lên đích rclone '
            .'(không thấy tệp trên remote, hoặc dung lượng không khớp).',
        'rclone_push_never_runs' => 'BACKUP_DISKS không có disk ":disk", nên lượt đẩy sao lưu lên '
            .'Google Drive mỗi đêm sẽ KHÔNG BAO GIỜ chạy dù BACKUP_RCLONE_REMOTE đã cấu hình đúng. '
            .'Thêm ":disk" vào BACKUP_DISKS.',
    ],

    'check' => [
        'disk_ok' => 'Đĩa ":disk": OK — ghi, đọc lại, xoá tệp thử đều thành công.',
        'disk_failed' => 'Đĩa ":disk": LỖI — :detail',
        'content_mismatch' => 'nội dung đọc lại không khớp nội dung đã ghi',
        'rclone_ok' => 'Đích rclone ":remote": OK — đẩy, liệt kê, xoá tệp thử đều thành công.',
        'rclone_failed' => 'Đích rclone ":remote": LỖI — :detail',
        'rclone_not_found_after_copy' => 'đã đẩy tệp thử lên nhưng không thấy trong danh sách remote',
        'target_not_found' => 'Không tìm thấy đích ":target". Các đích hợp lệ: :available.',
        'no_targets' => 'Không có đích sao lưu nào để kiểm tra.',
        'summary_ok' => 'Tất cả đích sao lưu đều ổn.',
        'summary_failed' => 'Có đích sao lưu bị lỗi — xem chi tiết ở trên.',
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
