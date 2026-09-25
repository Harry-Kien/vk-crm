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
        // Fix round 1: hai sửa so với vòng đầu.
        // (1) M1 — "địa chỉ mạng khách vừa dùng" giả định người gõ sai là chính khách; sau khi
        //     UnlockPortalLogin xét NAT-an toàn (I2), địa chỉ vẫn còn khoá đúng là địa chỉ CÓ
        //     người khác (có thể không phải khách) cũng gõ sai — chữ "liên quan" không giả định
        //     ai đã gõ.
        // (2) Thêm :minutes — trước đây chỉ nói "hết giờ khoá" chung chung; giờ có con số thật từ
        //     UnlockPortalLoginResult::$minutesRemaining, cùng thành ngữ portal.login.throttled.
        'unlock_login_success_ip_still_locked' => 'Đã xoá khoá đếm của tài khoản này. Nhưng địa chỉ mạng liên quan tới lần khoá này vẫn còn bị khoá tạm — xin đợi thêm :minutes phút, hoặc thử từ một mạng khác (ví dụ 4G) để vào ngay.',
    ],
];
