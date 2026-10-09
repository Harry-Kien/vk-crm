<?php

/*
 * M11 Task 12 — bước của người trong `/admin` với thứ AI đã soạn hoặc đã tạo: nháp dòng tiến độ (tab
 * "Tiến độ"), nháp trả lời yêu cầu của khách (tab "Yêu cầu từ khách"), mốc tạo qua AI (tab "Mốc thời
 * hạn") và nhật ký liên lạc tạo qua AI (tab "Liên lạc"). Tệp riêng để làn m11b gộp vào làn m11 không
 * đụng tệp ngôn ngữ của ai.
 */
return [
    // Một câu cho mọi lần từ chối vì quyền, vì vụ/cuộc trao đổi không còn, hay vì nháp không thuộc
    // trang đang mở (SPEC §10.10).
    'unavailable' => 'Không tìm thấy nháp này, hoặc bạn không có quyền dùng nó.',
    'not_pending' => 'Nháp này đã được dùng hoặc đã bị bỏ. Hãy tải lại trang để xem tình trạng mới nhất.',

    'created_by' => 'Soạn qua AI bởi :name lúc :at',
    'unknown_author' => 'một nhân sự',

    'actions' => [
        'open' => 'Mở nháp',
        'discard' => 'Bỏ nháp',
        'discard_heading' => 'Bỏ nháp do AI soạn',
        'discard_description' => 'Nháp không bị xoá: nó được giữ lại cùng tên bạn và lý do bỏ.',
        'discard_submit' => 'Bỏ nháp',
        'discard_success' => 'Đã bỏ nháp.',
    ],

    'fields' => [
        'reason' => 'Lý do bỏ',
        'public_content' => 'Nội dung gửi khách',
        'next_step' => 'Bước tiếp theo',
        'client_action' => 'Khách cần làm gì',
        'expected_next_update_at' => 'Dự kiến có tin tiếp theo',
        'internal_note' => 'Ghi chú nội bộ',
        'empty' => '(trống)',
    ],

    'validation' => [
        'reason_required' => 'Hãy ghi lý do bỏ nháp.',
        'reason_max' => 'Lý do bỏ nháp tối đa :max ký tự.',
    ],

    'stage_log' => [
        'heading' => 'Nháp từ AI (:count)',
        'description' => 'Nháp chưa tới tay khách. Mở nháp để sửa và gửi như một lần "Thêm cập nhật", hoặc bỏ nháp kèm lý do.',
        'open_heading' => 'Mở nháp từ AI — thêm cập nhật',
        'open_success' => 'Đã thêm cập nhật từ nháp.',
    ],

    'reply' => [
        'heading' => 'Nháp trả lời từ AI (:count)',
        'description' => 'Nháp chưa tới tay khách. Mở nháp để sửa và gửi như một câu trả lời, hoặc bỏ nháp kèm lý do.',
        'for_request' => 'Trả lời cho yêu cầu: :subject',
        'open_heading' => 'Mở nháp từ AI — trả lời khách',
        'closed' => 'Yêu cầu này đã đóng: không gửi được, chỉ bỏ được nháp.',
    ],

    'deadline' => [
        'column' => 'Nguồn',
        'unconfirmed' => 'Tạo qua AI, chưa xác nhận',
        'confirmed' => 'Tạo qua AI, đã xác nhận',
        'confirm' => 'Xác nhận',
        'confirm_heading' => 'Xác nhận mốc tạo qua AI',
        'confirm_description' => 'Xác nhận rằng tên, hạn và người phụ trách của mốc này đúng. Mốc vẫn được nhắc hạn như mọi mốc khác, đã xác nhận hay chưa.',
        'confirm_success' => 'Đã xác nhận mốc.',
        'not_from_ai' => 'Mốc này được nhập trên web, không có gì để xác nhận.',
    ],

    'communication' => [
        'column' => 'Nguồn',
    ],
];
