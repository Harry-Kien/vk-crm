<?php

/**
 * Nhãn tiếng Việt cho resource ClientUser (SPEC §7.4) — tài khoản đăng nhập portal của khách,
 * gated bằng clientUser.manage (ClientUserPolicy).
 */
return [
    'label' => 'Tài khoản portal',
    'plural_label' => 'Tài khoản portal',
    'fields' => [
        'client' => 'Khách hàng',
        'name' => 'Họ tên',
        'email' => 'Email đăng nhập',
        'phone' => 'Điện thoại',
        'password' => 'Mật khẩu',
        'is_active' => 'Đang hoạt động',
        'must_change_password' => 'Bắt buộc đổi mật khẩu lần đầu',
        'activated_at' => 'Ngày kích hoạt',
        'last_login_at' => 'Đăng nhập gần nhất',
    ],
    // Task 7 (R12, phát hiện `portal/portal-4`): nút "Mở khoá đăng nhập" trên trang sửa tài
    // khoản cổng — xem App\Actions\Portal\UnlockPortalLogin.
    'actions' => [
        'unlock_login' => 'Mở khoá đăng nhập',
        'unlock_login_success' => 'Đã xoá khoá đếm của tài khoản này. Khách đăng nhập lại được ngay.',
        // "Nếu khoá theo IP vẫn còn, câu trả về cho nhân sự nói rõ điều đó" (R12): mở khoá chỉ
        // xoá chiều TÀI KHOẢN, không đụng chiều địa chỉ mạng — câu này không hứa suông một cánh
        // cổng chỉ mở một nửa.
        'unlock_login_success_ip_still_locked' => 'Đã xoá khoá đếm của tài khoản này. Nhưng địa chỉ mạng khách vừa dùng vẫn còn bị khoá tạm — nếu khách thử lại từ đúng địa chỉ đó thì vẫn phải đợi hết giờ khoá; đổi sang mạng khác (ví dụ 4G) thì vào được ngay.',
    ],
];
