<?php

return [
    'label' => 'Loại vụ việc',
    'plural_label' => 'Loại vụ việc',
    'fields' => [
        'code' => 'Mã',
        'name' => 'Tên loại vụ việc',
        'description' => 'Mô tả',
        'is_active' => 'Đang dùng',
        'sort_order' => 'Thứ tự',
        'stages_count' => 'Số giai đoạn',
    ],
    'stages' => [
        'title' => 'Giai đoạn',
    ],
    'stage_fields' => [
        'key' => 'Định danh',
        'label' => 'Nhãn nội bộ',
        'client_label' => 'Nhãn cho khách',
        'client_description' => 'Giải thích cho khách',
        'sort_order' => 'Thứ tự',
        'is_terminal' => 'Giai đoạn kết thúc',
        'allowed_next' => 'Được chuyển tới',
        'default_next_update_days' => 'Số ngày dự kiến cập nhật tiếp theo',
    ],
];
