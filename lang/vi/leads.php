<?php

/*
 * Làn kd (2026-10-14) — vai "Nhân viên kinh doanh" và việc chăm sóc lead: chuyển lead cho luật sư, ghi chú
 * chăm sóc, hẹn liên hệ lại, bảng điều khiển của nhân viên kinh doanh, báo cáo theo người phụ trách lead.
 * Không câu nào mang tên, số điện thoại hay câu chuyện của người liên hệ.
 */
return [
    'channels' => [
        'call' => 'Gọi điện',
        'zalo' => 'Zalo',
        'email' => 'Email',
        'meeting' => 'Gặp trực tiếp',
    ],

    'fields' => [
        'consulting_lawyer' => 'Luật sư tư vấn',
    ],

    'handoff' => [
        'action' => 'Chuyển cho luật sư',
        'heading' => 'Chuyển lead cho luật sư tư vấn',
        'description' => 'Luật sư nhận thông báo và thấy bản ghi này: họ kiểm tra lại xung đột, tư vấn và mở vụ việc khi khách đồng ý. Anh/chị vẫn theo dõi và chăm sóc lead như trước.',
        'lawyer' => 'Luật sư tư vấn',
        'lawyer_help' => 'Chỉ luật sư, trưởng phòng hoặc quản trị đang làm việc — người mở được vụ việc.',
        'done' => 'Đã chuyển lead cho :name.',
        'current' => 'Luật sư tư vấn: :name (chuyển lúc :at).',
        'errors' => [
            'not_a_lawyer' => 'Chỉ chuyển được lead cho luật sư, trưởng phòng hoặc quản trị đang làm việc — người mở được vụ việc.',
            'closed' => 'Bản ghi này đã xong việc, không chuyển cho luật sư được nữa.',
            'same_lawyer' => 'Lead này đang ở với luật sư đó rồi.',
        ],
    ],

    'follow_ups' => [
        'section' => 'Chăm sóc khách tiềm năng',
        'section_description' => 'Mỗi lần liên hệ lại khách là một dòng: kênh, thời gian, nội dung ngắn. Ghi việc đã làm và hẹn tiếp theo — không ghi nội dung vụ việc ở đây (câu chuyện chỉ ghi ở ô "Câu chuyện", sau kiểm tra xung đột).',
        'empty' => 'Chưa có lần chăm sóc nào.',
        'action' => 'Ghi lần chăm sóc',
        'channel' => 'Kênh',
        'occurred_at' => 'Thời gian liên hệ',
        'note' => 'Nội dung ngắn',
        'note_help' => 'Ví dụ: "Đã gửi báo phí qua Zalo, khách hẹn trả lời thứ Sáu". Tối đa 1.000 ký tự.',
        'next_follow_up_at' => 'Hẹn liên hệ lại',
        'next_follow_up_help' => 'Để trống nếu chưa hẹn. Lead hiện ở widget "Lead đến hẹn liên hệ lại" từ ngày này.',
        'saved' => 'Đã ghi lần chăm sóc.',
        'next' => 'Hẹn liên hệ lại: :at',
        'no_next' => 'Chưa hẹn liên hệ lại.',
        'row' => ':at — :channel — :author',
        'unknown_author' => 'Không rõ người ghi',
        'anonymised_note' => '(đã ẩn danh)',
        'errors' => [
            'closed' => 'Bản ghi này đã xong việc, không ghi thêm lần chăm sóc được nữa.',
            'note_required' => 'Hãy ghi nội dung ngắn của lần liên hệ.',
            'note_too_long' => 'Nội dung tối đa :max ký tự.',
            'occurred_invalid' => 'Thời gian liên hệ không hợp lệ.',
            'occurred_in_future' => 'Thời gian liên hệ không được ở tương lai.',
            'next_invalid' => 'Thời gian hẹn liên hệ lại không hợp lệ.',
            'next_in_past' => 'Thời gian hẹn liên hệ lại phải ở sau lúc này.',
            // làn fi (B2): LogIntakeFollowUp — thời gian liên hệ trước lúc nhận lead.
            'occurred_before_received' => 'Thời gian liên hệ không được trước lúc văn phòng nhận lead (:at).',
            // hết khối làn fi
        ],
    ],

    'conflict' => [
        'hidden_note' => 'Chi tiết các khớp (hồ sơ, lĩnh vực, tên bên trùng) chỉ người làm việc trên vụ việc (luật sư, trợ lý…) xem được. Chưa nghe nội dung vụ việc — hãy "Chuyển cho luật sư" để luật sư xem các khớp và quyết.',
        // Đỏ: luật sư được chuyển lead cũng chỉ thấy mã hồ sơ và vai của khớp Đỏ, và không xử lý được nó
        // (`AcknowledgeIntakeConflict` từ chối Đỏ; chỉ `resolveConflict` — trưởng phòng/quản trị).
        'hidden_note_red' => 'Mức Đỏ: chỉ trưởng phòng/quản trị xử lý được (từ chối, hoặc ghi đè kèm lý do) — luật sư cũng không. Không nghe nội dung vụ việc; hãy "Chuyển cho luật sư" và chọn trưởng phòng, hoặc báo trưởng phòng.',
        'gate_hint_acknowledgement' => 'Chỉ người xem được các khớp (luật sư, trợ lý…) xác nhận được. Hãy "Chuyển cho luật sư".',
    ],

    'decision' => [
        'converted' => 'Đã chuyển thành vụ việc.',
    ],

    'errors' => [
        'acknowledge_needs_lawyer' => 'Chỉ người xem được các khớp (luật sư, trợ lý…) mới xác nhận đã xem các khớp. Hãy chuyển lead cho luật sư.',
    ],

    'notifications' => [
        'handed' => [
            'title' => 'Anh/chị vừa nhận một lead cần tư vấn',
            'body' => 'Bản ghi :code (nguồn: :source) vừa được chuyển cho anh/chị làm luật sư tư vấn. Mở mục Tiếp nhận để xem và liên hệ khách.',
        ],
    ],

    'widgets' => [
        'my_leads' => [
            'heading' => 'Lead của tôi',
            'hint' => 'Lead anh/chị ghi, được giao hoặc được chuyển tới.',
        ],
        'follow_ups_due' => [
            'heading' => 'Lead đến hẹn liên hệ lại',
            'description' => 'Lead còn mở của anh/chị có hẹn liên hệ lại hôm nay hoặc đã qua hẹn.',
            'empty' => 'Không lead nào đến hẹn liên hệ lại.',
            'columns' => [
                'code' => 'Mã',
                'contact_name' => 'Người liên hệ',
                'status' => 'Trạng thái',
                'next_follow_up_at' => 'Hẹn liên hệ lại',
            ],
            'open' => 'Mở',
        ],
    ],

    'report' => [
        'by_owner' => [
            'heading' => 'Lead theo người phụ trách',
            'description' => 'Số lead nhận trong :range và số đã thành vụ việc, theo người phụ trách lead (người được giao; chưa giao ai thì người đã ghi). Không tính bản trùng đã gộp.',
            'series_total' => 'Số lead',
            'series_won' => 'Thành vụ việc',
            'row' => ':won/:total (:rate)',
            'rate' => ':rate %',
            'unknown' => 'Không rõ người phụ trách',
        ],
    ],
];
