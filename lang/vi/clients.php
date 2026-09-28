<?php

/**
 * Nhãn tiếng Việt cho resource Client (SPEC §7.4). Danh sách rút gọn theo
 * ClientPolicy::view — ai không có client.manage chỉ thấy khách của vụ việc mình xem được.
 */
return [
    'label' => 'Khách hàng',
    'plural_label' => 'Khách hàng',
    'fields' => [
        'code' => 'Mã khách hàng',
        'type' => 'Loại',
        'name' => 'Tên / Tên tổ chức',
        'id_number' => 'Số CCCD / Mã số thuế',
        'phone' => 'Điện thoại',
        'email' => 'Email',
        'address' => 'Địa chỉ',
        'representative_name' => 'Người đại diện',
        'note' => 'Ghi chú nội bộ',
        'matters_count' => 'Số vụ việc',
    ],
    'note_hint' => 'Chỉ nội bộ, không bao giờ hiện cho khách trên portal.',
    'delete_blocked_open_matters' => 'Không thể xoá: khách hàng còn :count vụ việc đang mở.',
    // M6.5 Task 6 (R4, `intake-07`): cảnh báo trùng khi tạo khách hàng qua màn hình "Khách hàng".
    'duplicate' => [
        'warning' => 'Đã có một khách hàng khác mang cùng số điện thoại hoặc số CCCD vừa nhập.',
        'view_link' => 'Xem hồ sơ trùng: :code — :name',
        'confirm' => 'Vẫn tạo một hồ sơ khách hàng mới dù trùng định danh',
        'confirm_help' => 'Chỉ tích khi đã xem hồ sơ trùng ở trên và chắc chắn đây là hai người khác nhau.',
    ],
];
