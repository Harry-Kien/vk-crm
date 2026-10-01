<?php

return [
    'level' => [
        'red' => 'Đỏ — chặn',
        'yellow' => 'Vàng — cảnh báo',
        'green' => 'Xanh — không tìm thấy',
    ],
    'tier' => [
        'hash' => 'Số căn cước',
        'phone' => 'Số điện thoại',
        'name' => 'Tên',
        'same_matter' => 'Cùng vụ việc, hai phía đối lập',
    ],
    // R13(e)/`conflict-04` (M6.5 Task 8): thông báo trong hệ thống khi sửa định danh khách hàng
    // làm lộ ra một xung đột MỚI trên một vụ việc đang mở (SyncClientPartyIdentities).
    'resync_notification' => [
        'title' => 'Xung đột lợi ích mức :level sau khi sửa hồ sơ khách hàng',
        'body' => 'Hồ sơ :code có bên trùng với khách hàng vừa được sửa định danh — mở lại kết quả kiểm tra xung đột trên hồ sơ để xem xét.',
    ],
    // Fix round 3, N1: job RecheckClientIdentityConflicts thất bại sau tất cả các lần thử lại —
    // phải hiện ra trong ứng dụng cho admin, không chỉ nằm trong laravel.log (SPEC §2).
    'recheck_failed_notification' => [
        'title' => 'Không thể rà lại xung đột lợi ích sau khi sửa hồ sơ khách hàng',
        'body' => 'Đã thử lại nhiều lần nhưng không thể rà lại xung đột lợi ích cho khách hàng #:client_id sau khi hồ sơ được sửa. Cần kiểm tra thủ công.',
    ],
    // M10 Task 2 (R1): nhãn của khớp từ NGUỒN THỨ HAI — một lần tiếp nhận chưa chuyển đổi. Đặt ở cột
    // "loại vụ việc" của bảng kết quả, cạnh mã `TN-…`; không kèm câu chuyện hay lĩnh vực dự kiến.
    'intake_contacted' => 'Đã liên hệ văn phòng ngày :date',
];
