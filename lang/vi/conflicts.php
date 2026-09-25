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
];
