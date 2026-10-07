<?php

/**
 * M7 Task 8 — tab "Liên lạc" (nhật ký liên lạc, SPEC §4.17, §7.2) và tab "Nhật ký" của riêng một
 * vụ việc. Tên kênh liên lạc nằm ở `enums.communication_type` (có từ M1); nhãn hai sự kiện audit
 * mới nằm ở `activity.events`.
 */
return [
    'label' => 'nhật ký liên lạc',
    'plural_label' => 'nhật ký liên lạc',

    // Một câu cho MỌI lý do không ghi/xoá được (SPEC §10.10): không có quyền, vụ việc đã xoá mềm,
    // dòng đã xoá, tài khoản đã bị vô hiệu hoá.
    'unavailable' => 'Không thể ghi vào nhật ký liên lạc của hồ sơ này.',

    'tab' => [
        'title' => 'Liên lạc',
        'empty_state' => 'Chưa ghi cuộc liên lạc nào với hồ sơ này.',
        'columns' => [
            'occurred_at' => 'Thời điểm',
            'type' => 'Kênh',
            'counterpart' => 'Với ai',
            'summary' => 'Nội dung',
            'duration_minutes' => 'Số phút',
            'author' => 'Người ghi',
        ],
        'fields' => [
            'type' => 'Kênh',
            'summary' => 'Nội dung',
            'summary_placeholder' => 'Đã trao đổi gì, hẹn làm gì tiếp.',
            'details' => 'Thời điểm, người liên lạc, thời lượng',
            'details_description' => 'Đã điền sẵn: bây giờ, khách hàng của hồ sơ. Chỉ sửa khi khác.',
            'occurred_at' => 'Thời điểm',
            'counterpart' => 'Nói chuyện với ai',
            'duration_minutes' => 'Thời lượng (phút)',
            'delete_reason' => 'Lý do xoá',
        ],
        'actions' => [
            'add' => 'Ghi liên lạc',
            'add_heading' => 'Ghi một cuộc liên lạc',
            'add_submit' => 'Ghi',
            'add_success' => 'Đã ghi vào nhật ký liên lạc.',
            'delete' => 'Xoá',
            'delete_heading' => 'Xoá dòng nhật ký liên lạc',
            'delete_description' => 'Nhật ký liên lạc là bằng chứng: dòng này được ẩn đi chứ không mất, và lý do xoá được lưu vào nhật ký hệ thống.',
            'delete_success' => 'Đã xoá dòng nhật ký liên lạc.',
        ],
    ],

    'validation' => [
        'summary_required' => 'Hãy ghi nội dung cuộc liên lạc.',
        'summary_too_long' => 'Nội dung dài quá :max ký tự.',
        'counterpart_too_long' => 'Tên người liên lạc dài quá :max ký tự.',
        'occurred_at_invalid' => 'Thời điểm không đọc được.',
        'occurred_at_future' => 'Thời điểm liên lạc không thể ở tương lai.',
        'duration_out_of_range' => 'Thời lượng phải từ 0 đến :max phút.',
        'delete_reason_required' => 'Hãy ghi lý do xoá.',
        'delete_reason_too_long' => 'Lý do xoá dài quá :max ký tự.',
    ],

    // Tab "Nhật ký" của riêng vụ việc (SPEC §7.2) — cột và nút giống trang Nhật ký hệ thống, nên
    // nhãn cột dùng lại `activity.page.columns.*`; ở đây chỉ có tiêu đề tab và câu trạng thái rỗng.
    'activity_tab' => [
        'title' => 'Nhật ký',
        'empty_state' => 'Chưa có dòng nhật ký nào của hồ sơ này.',
    ],
];
