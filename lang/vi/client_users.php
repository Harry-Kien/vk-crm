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
        // Final review I2: khách có thể bị khoá CHỈ vì lần hỏng của người khác cùng địa chỉ (wifi
        // văn phòng, mạng chung của một công ty) — lần thử bị chặn không ghi dòng nào nên hệ thống
        // không biết khách đang ở địa chỉ nào. Khi còn một địa chỉ như vậy bị khoá, câu "đăng nhập
        // lại được ngay" là hứa suông; câu này nói đúng điều hệ thống biết. Xem
        // App\Actions\Concerns\ClearsNatSafeIpLocks.
        'unlock_login_success_other_address_locked' => 'Đã xoá khoá đếm của tài khoản này. Nhưng đang có địa chỉ mạng bị khoá tạm vì người khác gõ sai nhiều lần (ví dụ wifi văn phòng hay mạng chung của một công ty) — nếu khách đang dùng mạng đó thì vẫn bị chặn thêm tối đa :minutes phút, hoặc thử từ một mạng khác (ví dụ 4G) để vào ngay.',
    ],
];
