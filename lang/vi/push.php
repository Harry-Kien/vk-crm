<?php

use App\Console\Commands\PushResetCommand;

/**
 * Thông báo đẩy trên điện thoại (M12). Nội dung đẩy (R11, Task 7) không bao giờ mang mã hồ sơ, tên
 * khách, tiêu đề vụ việc hay tài liệu — màn hình khoá không phải màn hình của văn phòng.
 */
return [
    // M12 Task 4 (R7) — {@see PushResetCommand}.
    'reset' => [
        'confirm' => 'Xoá :count đăng ký thông báo đẩy trên MỌI điện thoại (nhân sự và khách)? Chỉ '
            .'làm việc này ngay sau khi đổi khoá VAPID — mọi người sẽ phải bật lại thông báo trên '
            .'từng máy.',
        'cancelled' => 'Đã huỷ — không xoá đăng ký nào. Chạy lại với --force để bỏ bước hỏi.',
        'done' => 'Đã xoá :count đăng ký thông báo đẩy. Mọi người phải bật lại thông báo trên '
            .'từng máy của mình.',
    ],
];
