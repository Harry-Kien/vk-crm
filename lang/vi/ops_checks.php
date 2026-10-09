<?php

/*
 * Làn fc — sửa sau đợt kiểm tra nghiệp vụ toàn hệ thống (2026-10-09). Tệp riêng để không chạm khối khoá
 * của `preflight.php`, `backup.php`, `widgets.php` mà các làn khác cũng sửa.
 */
return [
    // RunPreflight::backupEncryptionRow() / backupOffServerRow() — chỉ ở production.
    'preflight' => [
        'backup_encryption_missing' => 'Sao lưu đêm sẽ bị từ chối: :detail Không có bản sao lưu nào được tạo cho tới khi sửa '
            .'(docs/SAO-LUU-KHOI-PHUC.md, Bước 4).',
        'backup_encryption_ok' => 'Sao lưu có mật khẩu mã hoá (BACKUP_ARCHIVE_PASSWORD) và máy chủ mã hoá được archive.',
        'backup_off_server_missing' => 'Không có đích sao lưu ngoài máy chủ: BACKUP_RCLONE_REMOTE để trống và mọi đĩa trong '
            .'BACKUP_DISKS (:disks) nằm trên chính máy chủ này — máy chủ hỏng là mất cả dữ liệu lẫn mọi bản sao lưu. '
            .'Bật Google Drive theo docs/SAO-LUU-KHOI-PHUC.md (Bước 1–5).',
        'backup_off_server_ok' => 'Có đích sao lưu ngoài máy chủ.',
        // RunPreflight::mailSchemeRows() — mailer con của failover/roundrobin.
        'mail_scheme_unsupported_in' => 'Mailer ":mailer" (một nhánh của MAIL_MAILER=:parent): MAIL_SCHEME=:value không '
            .'phải giá trị Laravel nhận — thư đi qua nhánh này sẽ hỏng ngay lúc dựng kết nối. Chỉ có ba cách ghi: smtps '
            .'cho cổng 465, smtp hoặc để trống (null) cho cổng 587/25. Không ghi tls hay ssl (docs/CAI-DAT.md, Bước 3, mục 4).',
        'mail_scheme_ok_in' => 'MAIL_SCHEME hợp lệ cho mailer ":mailer" (một nhánh của MAIL_MAILER=:parent).',
    ],

    // CheckBackupDestinations::launchConditions() — `vkcrm:backup-check` không đối số, chỉ ở production.
    'backup_check' => [
        'encryption_failed' => 'Mã hoá bản sao lưu: LỖI — :detail',
        'encryption_ok' => 'Mã hoá bản sao lưu: OK — có BACKUP_ARCHIVE_PASSWORD, máy chủ mã hoá được archive.',
        'off_server_failed' => 'Bản sao ngoài máy chủ: LỖI — không có: BACKUP_RCLONE_REMOTE để trống và mọi đĩa '
            .'trong BACKUP_DISKS (:disks) nằm trên chính máy chủ này (docs/SAO-LUU-KHOI-PHUC.md, Bước 1–5).',
        'off_server_ok' => 'Bản sao ngoài máy chủ: OK — có đích ngoài máy chủ.',
    ],
];
