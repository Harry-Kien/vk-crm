<?php

/**
 * Nhãn tiếng Việt cho resource User (SPEC §7.4) — quản trị nhân sự nội bộ, gated bằng
 * settings.manage. Không có trường nào cho mật khẩu hiện tại hay hai lớp xác thực: mật khẩu chỉ
 * nhập mới (không hiện lại), hai lớp xác thực do người dùng tự quản lý ở hồ sơ của họ.
 */
return [
    'label' => 'Nhân sự',
    'plural_label' => 'Nhân sự',
    'fields' => [
        'name' => 'Họ tên',
        'email' => 'Email đăng nhập',
        'phone' => 'Điện thoại',
        'position' => 'Chức danh',
        'bar_number' => 'Số thẻ luật sư',
        'is_active' => 'Đang hoạt động',
        'password' => 'Mật khẩu',
        'last_login_at' => 'Đăng nhập gần nhất',
    ],
    'password_hint' => 'Để trống khi sửa nếu không muốn đổi mật khẩu.',
];
