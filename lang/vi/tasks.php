<?php

/**
 * Làn tk — giao, nhận, xử lý việc NỘI BỘ giữa luật sư, chuyên viên và nhân sự (chủ văn phòng,
 * 2026-10-10). Mọi chuỗi giao diện, thư và thông báo của tính năng nằm ở tệp này.
 */
return [
    'label' => 'công việc',
    'plural_label' => 'công việc',
    'navigation_label' => 'Công việc',
    'title' => 'Công việc',

    'status' => [
        'pending' => 'Chờ nhận',
        'accepted' => 'Đang làm',
        'declined' => 'Bị trả lại',
        'completed' => 'Hoàn thành',
        'cancelled' => 'Đã huỷ',
    ],

    'priority' => [
        'normal' => 'Thường',
        'high' => 'Cao',
        'urgent' => 'Gấp',
    ],

    // Trang "Công việc": bốn tab. "Chờ tôi nhận" đứng đầu — việc cần MỘT cú bấm của người đang xem.
    'tabs' => [
        'awaiting' => 'Chờ tôi nhận',
        'active' => 'Đang làm',
        'assigned' => 'Tôi đã giao',
        'all' => 'Tất cả',
    ],

    'columns' => [
        'title' => 'Việc',
        'matter' => 'Vụ việc',
        'general' => 'Việc chung',
        'assignee' => 'Người nhận',
        'assigner' => 'Người giao',
        'due_date' => 'Hạn',
        'priority' => 'Ưu tiên',
        'status' => 'Trạng thái',
        'assigned_at' => 'Giao lúc',
        'assignee_inactive' => 'Người nhận đã nghỉ — cần giao lại',
        'assignee_off_team' => 'Người nhận không còn trong đội ngũ vụ — cần giao lại',
    ],

    'due' => [
        'none' => 'Không đặt hạn',
        'overdue' => 'Quá hạn :count ngày',
        'today' => 'Hạn hôm nay',
        'tomorrow' => 'Hạn ngày mai',
        'in_days' => 'Còn :count ngày',
        'done' => 'Đã xong',
    ],

    'waiting' => 'Chờ nhận từ :since',

    'filters' => [
        'status' => 'Trạng thái',
        'matter' => 'Vụ việc',
        'assignee' => 'Người nhận',
        'assigner' => 'Người giao',
        'priority' => 'Ưu tiên',
        'overdue' => 'Chỉ việc quá hạn',
        'needs_reassignment' => 'Cần giao lại (người nhận đã nghỉ hoặc rời đội ngũ vụ)',
    ],

    'empty' => [
        'awaiting' => 'Không có việc nào chờ anh/chị nhận.',
        'active' => 'Anh/chị không có việc nào đang làm.',
        'assigned' => 'Anh/chị không có việc nào đã giao đang mở hay vừa hoàn thành.',
        'all' => 'Chưa có việc nào.',
        'matter' => 'Vụ việc này chưa có việc nào được giao.',
        'matter_hint' => 'Bấm "Giao việc" để giao một việc cho người trong đội ngũ của vụ.',
    ],

    'fields' => [
        'title' => 'Việc cần làm',
        'title_help' => 'Viết ngắn, bắt đầu bằng động từ — ví dụ "Soạn đơn khởi kiện", "Nộp hồ sơ ở toà".',
        'assignee' => 'Giao cho',
        'assignee_help_matter' => 'Chỉ người trong đội ngũ của vụ (không gồm người theo dõi) và mở được vụ.',
        'assignee_help_general' => 'Bất kỳ nhân sự nào đang làm việc.',
        'due_date' => 'Hạn',
        'due_date_help' => 'Để trống nếu không có hạn.',
        'priority' => 'Mức ưu tiên',
        'description' => 'Mô tả, hướng dẫn',
        'reason' => 'Lý do',
        'decline_reason_help' => 'Người giao sẽ đọc lý do này — ví dụ đang quá tải, xung đột lợi ích, cần thêm thông tin.',
        'cancel_reason_help' => 'Người đang giữ việc sẽ được báo việc đã huỷ.',
        'note' => 'Ghi chú khi hoàn thành',
        'note_help' => 'Không bắt buộc — ví dụ kết quả, nơi để tài liệu.',
    ],

    'actions' => [
        'assign' => 'Giao việc',
        'assign_heading' => 'Giao một việc',
        'assign_general_heading' => 'Giao một việc chung',
        'assign_submit' => 'Giao việc',
        'assign_success' => 'Đã giao việc. Người nhận được báo bằng chuông và thư.',
        'accept' => 'Đã nhận',
        'accept_success' => 'Đã nhận việc. Người giao được báo.',
        'decline' => 'Trả lại',
        'decline_heading' => 'Trả lại việc này cho người giao?',
        'decline_submit' => 'Trả lại',
        'decline_success' => 'Đã trả lại việc. Người giao được báo kèm lý do.',
        'complete' => 'Hoàn thành',
        'complete_heading' => 'Báo việc này đã hoàn thành?',
        'complete_submit' => 'Hoàn thành',
        'complete_success' => 'Đã báo hoàn thành. Người giao được báo.',
        'view' => 'Xem',
        'view_heading' => 'Chi tiết việc',
        'close' => 'Đóng',
        'edit' => 'Sửa',
        'edit_heading' => 'Sửa việc',
        'edit_submit' => 'Lưu',
        'edit_success' => 'Đã lưu thay đổi.',
        'reassign' => 'Giao lại',
        'reassign_heading' => 'Giao lại việc này cho người khác',
        'reassign_submit' => 'Giao lại',
        'reassign_success' => 'Đã giao lại. Người nhận mới được báo.',
        'cancel' => 'Huỷ việc',
        'cancel_heading' => 'Huỷ việc này?',
        'cancel_submit' => 'Huỷ việc',
        'cancel_success' => 'Đã huỷ việc.',
    ],

    // Khung "Chi tiết việc".
    'detail' => [
        'matter' => 'Vụ việc',
        'general' => 'Việc chung (không thuộc vụ nào)',
        'assignee' => 'Người nhận',
        'assigner' => 'Người giao',
        'status' => 'Trạng thái',
        'priority' => 'Mức ưu tiên',
        'due_date' => 'Hạn',
        'assigned_at' => 'Giao lúc',
        'accepted_at' => 'Nhận lúc',
        'declined_at' => 'Trả lại lúc',
        'completed_at' => 'Hoàn thành lúc',
        'cancelled_at' => 'Huỷ lúc',
        'description' => 'Mô tả, hướng dẫn',
        'decline_reason' => 'Lý do trả lại',
        'completion_note' => 'Ghi chú khi hoàn thành',
        'cancel_reason' => 'Lý do huỷ',
        'none' => '—',
    ],

    // "Việc của tôi" (trang của chính mình, M13): ba khối.
    'my' => [
        'awaiting' => [
            'heading' => 'Chờ tôi nhận',
            'description' => 'Bấm "Đã nhận" để nhận việc, hoặc "Trả lại" kèm lý do.',
        ],
        'active' => [
            'heading' => 'Đang làm',
            'description' => 'Việc anh/chị đã nhận — việc quá hạn tô đỏ, sắp đến hạn tô vàng.',
        ],
        'assigned' => [
            'heading' => 'Tôi đã giao',
            'description' => 'Việc anh/chị giao còn mở — việc bị trả lại cần giao lại hoặc huỷ. Việc hoàn thành trong :days ngày qua vẫn hiện, kèm ghi chú kết quả.',
        ],
        'open_all' => 'Mở trang Công việc',
    ],

    'errors' => [
        // Một câu cho mọi lần không được thao tác — không phân biệt "không có quyền" với "không tồn tại"
        // (SPEC §10.10).
        'unavailable' => 'Việc này không còn mở được với anh/chị. Hãy tải lại trang; nếu vẫn không được, nhờ người giao việc hoặc quản lý.',
        'not_in_state' => 'Việc này hiện ở trạng thái ":status" nên thao tác này không làm được nữa. Hãy tải lại trang để thấy tình trạng mới nhất.',
    ],

    'validation' => [
        'title_required' => 'Hãy ghi việc cần làm.',
        'too_long' => 'Nội dung dài quá :max ký tự.',
        'reason_required' => 'Hãy ghi lý do.',
        'due_date_invalid' => 'Hạn không phải một ngày hợp lệ.',
        'due_date_past' => 'Hạn không được là một ngày đã qua.',
        'self_assign' => 'Không giao việc cho chính mình.',
        'assignee_ineligible' => 'Người này không nhận được việc này: chỉ nhân sự đang làm việc, và với việc của một vụ thì phải trong đội ngũ của vụ (không gồm người theo dõi) và mở được vụ.',
        'same_assignee' => 'Người này đang giữ việc này rồi. Chọn một người khác.',
        'changed_meanwhile' => 'Việc này vừa được thay đổi sau khi anh/chị mở khung sửa. Hãy đóng khung, tải lại trang rồi sửa lại.',
    ],

    // Chuông trong hệ thống (App\Notifications\Staff\TaskAlert). :title, :who, :reason đã được escape.
    'alert' => [
        'open' => 'Mở',
        'on_matter' => '(vụ :code)',
        'assigned' => [
            'title' => 'Anh/chị được giao một việc mới',
            'body' => ':who giao: ":title". Bấm "Đã nhận" hoặc "Trả lại" ở mục Công việc.',
        ],
        'accepted' => [
            'title' => 'Việc anh/chị giao đã được nhận',
            'body' => ':who đã nhận việc ":title".',
        ],
        'declined' => [
            'title' => 'Việc anh/chị giao bị trả lại',
            'body' => ':who trả lại việc ":title". Lý do: :reason',
        ],
        'completed' => [
            'title' => 'Việc anh/chị giao đã hoàn thành',
            'body' => ':who đã hoàn thành việc ":title".',
        ],
        'cancelled' => [
            'title' => 'Một việc của anh/chị đã được huỷ',
            'body' => ':who đã huỷ việc ":title". Lý do: :reason',
        ],
    ],

    // Thư (App\Mail\Staff\TaskNotice). Tiêu đề thư không mang tên việc hay mã vụ.
    'email' => [
        'greeting' => 'Chào :name,',
        'subject' => [
            'assigned' => ':name vừa giao cho anh/chị một việc',
            'accepted' => ':name đã nhận việc anh/chị giao',
            'declined' => ':name đã trả lại việc anh/chị giao',
            'completed' => ':name đã hoàn thành việc anh/chị giao',
        ],
        'headline' => [
            'assigned' => ':name vừa giao cho anh/chị một việc.',
            'accepted' => ':name đã nhận việc anh/chị giao.',
            'declined' => ':name đã trả lại việc anh/chị giao.',
            'completed' => ':name đã hoàn thành việc anh/chị giao.',
        ],
        'fields' => [
            'title' => 'Việc',
            'matter' => 'Vụ việc',
            'due_date' => 'Hạn',
            'priority' => 'Mức ưu tiên',
            'reason' => 'Lý do trả lại',
            'note' => 'Ghi chú',
        ],
        'no_due_date' => 'Không đặt hạn',
        'action' => [
            'assigned' => 'Mở mục Công việc để bấm "Đã nhận" hoặc "Trả lại"',
            'accepted' => 'Mở mục Công việc — tab "Tôi đã giao"',
            'declined' => 'Mở mục Công việc để giao lại hoặc huỷ việc',
            'completed' => 'Mở mục Công việc — tab "Tôi đã giao"',
        ],
        'salutation' => 'Thư tự động từ hệ thống quản lý hồ sơ của :office.',
    ],
];
