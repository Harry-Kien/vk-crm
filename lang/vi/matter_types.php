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
    // Task 19 (rà soát cuối, "Cấu hình không phá dữ liệu đang chạy") — MatterTypePolicy::delete().
    'delete_blocked_in_use' => 'Còn :count hồ sơ đang dùng loại vụ việc này, không xoá được. Tắt "Đang dùng" nếu không muốn mở vụ mới thuộc loại này nữa.',
    'stages' => [
        'title' => 'Giai đoạn',
        // Task 19 (rà soát cuối, "Cấu hình không phá dữ liệu đang chạy") — MatterTypeStagePolicy::delete().
        'delete_blocked_in_use' => 'Còn :count hồ sơ đang đứng ở giai đoạn này, không xoá được. Chuyển các hồ sơ đó sang giai đoạn khác trước.',
        'delete_blocked_allowed_next' => 'Giai đoạn này còn nằm trong "Được chuyển tới" của: :labels. Bỏ nó khỏi danh sách đó trước khi xoá.',
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
        // Task 19: khoá đổi `key` khi hồ sơ hoặc dòng tiến độ đang dùng (xem MatterTypeStage::isKeyInUse()).
        'key_locked' => 'Không đổi được định danh: đang có hồ sơ hoặc dòng tiến độ dùng giai đoạn này. Tạo một giai đoạn mới nếu cần định danh khác.',
        // Task 19, vòng sửa 1 (Critical): nhánh riêng của key_locked, nêu tên giai đoạn đang trỏ tới qua allowed_next.
        'key_locked_allowed_next' => 'Không đổi được định danh: giai đoạn này còn nằm trong "Được chuyển tới" của: :labels. Bỏ nó khỏi danh sách đó trước khi đổi định danh.',
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
