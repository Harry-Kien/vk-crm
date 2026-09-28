<?php

/**
 * Tab "Đội ngũ" (SPEC §4.7, §5, §7.2) — M6.5 Task 3. Trước task này không màn hình nào ghi vào
 * `matter_user` ngoài `Matter::created()` (chỉ thêm luật sư phụ trách), nên trợ lý và luật sư
 * cộng sự không bao giờ thấy được một vụ mở qua giao diện (finding `intake-01`/`roles-03`/
 * `spec-gap-01`/`e2e-F4`, critical).
 */
return [
    'tab' => [
        'title' => 'Đội ngũ',
        'empty_state' => 'Vụ việc chưa có ai ngoài luật sư phụ trách.',
        'columns' => [
            'name' => 'Họ và tên',
            'role' => 'Vai trò',
            'joined_at' => 'Ngày tham gia',
        ],
        'fields' => [
            'role' => 'Vai trò trong vụ việc',
            'member' => 'Nhân sự',
        ],
        'actions' => [
            'add' => 'Thêm thành viên',
            'add_heading' => 'Thêm thành viên vào đội ngũ vụ việc',
            'add_submit' => 'Thêm',
            'add_success' => 'Đã thêm thành viên vào đội ngũ.',
            'remove' => 'Gỡ',
            'remove_heading' => 'Gỡ thành viên khỏi đội ngũ vụ việc',
            'remove_description' => 'Người này sẽ không còn thấy được vụ việc này qua đội ngũ nữa (trừ khi có quyền khác, ví dụ trưởng phòng hoặc quản trị viên).',
            'remove_success' => 'Đã gỡ thành viên khỏi đội ngũ.',
        ],
    ],
    // Câu mô tả một việc còn dở dang, dùng bởi App\Support\OpenWorkResult::describe() — ghép lại
    // thành danh sách "cần chuyển trước" trong lời từ chối gỡ thành viên (R6) và, sau này, từ
    // chối vô hiệu hoá/xoá tài khoản (Task 4, R7).
    'open_work' => [
        'lead_matter' => 'đang là luật sư phụ trách của vụ việc :code (vụ chưa đóng)',
        'deadline' => 'còn đứng tên mốc hạn ":name" của vụ việc :code, chưa hoàn thành',
        'client_request' => 'còn được giao yêu cầu khách ":subject" của vụ việc :code, chưa đóng',
    ],
];
