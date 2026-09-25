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
    // Danh mục hồ sơ mẫu — M6.5 Task 15 (finding intake-02/checklist-02/roles-06/spec-gap-04).
    'checklist_templates' => [
        'title' => 'Danh mục hồ sơ mẫu',
        'fields' => [
            'name' => 'Tên mẫu',
            'is_active' => 'Đang dùng',
            'is_active_help' => 'Vụ việc mở mới thuộc loại này sẽ sao chép đầu mục từ mẫu ĐANG DÙNG mới nhất. Tắt mẫu không đổi danh mục của các vụ việc đã mở.',
            'items_count' => 'Số đầu mục',
        ],
        'item_fields' => [
            'name' => 'Tên đầu mục',
            'description' => 'Mô tả cho khách',
            'is_required' => 'Bắt buộc',
            'sort_order' => 'Thứ tự',
        ],
        'items' => [
            'title' => 'Đầu mục của mẫu',
            'add' => 'Thêm đầu mục',
        ],
    ],
];
