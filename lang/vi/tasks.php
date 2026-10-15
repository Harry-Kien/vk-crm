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
        // Vòng sửa 1 (I1): người vừa mất việc không còn xem được nó — câu chung, không tiêu đề, không mã vụ.
        'reassigned_away' => [
            'title' => 'Một việc anh/chị đang giữ đã được giao cho người khác',
            'body' => ':who đã giao lại cho người khác một việc anh/chị đang giữ. Anh/chị không cần làm tiếp '
                .'việc đó; nếu đã làm dở, hãy trao đổi với :who.',
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

    // ---------------------------------------------------------------------------------------------
    // Làn tk, Task 2 — "Nhận hồ sơ": một ghế trong đội ngũ vụ (thêm vào đội ngũ, hay nhận bàn giao làm
    // luật sư phụ trách) chờ người nhận bấm "Đã nhận hồ sơ" hoặc "Trả lại" kèm lý do.
    // ---------------------------------------------------------------------------------------------
    'seat' => [
        'status' => [
            'pending' => 'Chờ nhận hồ sơ',
            'accepted' => 'Đã nhận hồ sơ',
            'declined' => 'Đã trả lại',
        ],
        'columns' => [
            'status' => 'Nhận hồ sơ',
            'matter' => 'Mã hồ sơ',
            'title' => 'Vụ việc',
            'role' => 'Vai',
            'assigner' => 'Người giao',
            'assigned_at' => 'Giao lúc',
        ],
        'waiting_since' => 'Chờ nhận từ :since',
        'declined_note' => 'Lý do: :reason — cần phân công lại.',
        'declined_note_short' => 'Cần phân công lại.',
        'my' => [
            'heading' => 'Hồ sơ chờ tôi nhận',
            'description' => 'Anh/chị vừa được giao vào đội ngũ các hồ sơ này. Bấm "Đã nhận hồ sơ", hoặc "Trả lại" kèm lý do nếu không nhận được (quá tải, xung đột lợi ích…).',
            'empty' => 'Không có hồ sơ nào chờ anh/chị nhận.',
        ],
        'fields' => [
            'decline_reason_help' => 'Người giao và quản lý sẽ đọc lý do này — ví dụ đang quá tải, xung đột lợi ích. Anh/chị vẫn ở trong đội ngũ cho tới khi được phân công lại.',
        ],
        'actions' => [
            'accept' => 'Đã nhận hồ sơ',
            'accept_success' => 'Đã nhận hồ sơ. Người giao được báo.',
            'decline' => 'Trả lại',
            'decline_heading' => 'Trả lại hồ sơ này?',
            'decline_description' => 'Anh/chị không bị gỡ khỏi vụ ngay: người giao, luật sư phụ trách và quản lý được báo kèm lý do để phân công lại.',
            'decline_submit' => 'Trả lại',
            'decline_success' => 'Đã trả lại hồ sơ. Người giao và quản lý được báo kèm lý do.',
        ],
        'errors' => [
            // Một câu cho mọi lần không được thao tác (SPEC §10.10).
            'unavailable' => 'Hồ sơ này không còn mở được với anh/chị, hoặc anh/chị không còn trong đội ngũ của vụ. Hãy tải lại trang.',
            'not_in_state' => 'Ghế của anh/chị trong hồ sơ này hiện ở tình trạng ":status" nên thao tác này không làm được nữa. Hãy trao đổi với luật sư phụ trách hoặc quản lý.',
        ],
        // Chuông (App\Notifications\Staff\MatterAssignmentAlert). :code, :member, :who, :role, :reason, :since đã escape.
        'alert' => [
            'assigned' => [
                'title' => 'Anh/chị được giao hồ sơ :code',
                'body' => ':who đã giao anh/chị vào đội ngũ hồ sơ :code với vai :role. Bấm "Đã nhận hồ sơ" hoặc "Trả lại" ở mục Việc của tôi.',
            ],
            'accepted' => [
                'title' => 'Hồ sơ :code đã được nhận',
                'body' => ':member đã nhận hồ sơ :code (vai :role).',
            ],
            'declined' => [
                'title' => 'Hồ sơ :code bị trả lại — cần phân công lại',
                'body' => ':member trả lại hồ sơ :code (vai :role). Lý do: :reason. Họ vẫn ở trong đội ngũ cho tới khi được phân công lại.',
            ],
            'reminder_awaiting' => [
                'title' => 'Hồ sơ :code đang chờ anh/chị nhận',
                'body' => 'Hồ sơ :code (vai :role) đã chờ anh/chị nhận :since (tính theo giờ làm việc). Bấm "Đã nhận hồ sơ" hoặc "Trả lại" ở mục Việc của tôi.',
            ],
            'reminder_unaccepted' => [
                'title' => 'Hồ sơ :code chưa được nhận',
                'body' => ':member chưa nhận hồ sơ :code (vai :role) sau :since (tính theo giờ làm việc). Hãy trao đổi với họ hoặc phân công người khác.',
            ],
        ],
        // Thư (App\Mail\Staff\MatterAssignmentNotice). Tiêu đề thư không mang mã hồ sơ.
        'email' => [
            'subject' => [
                'declined' => ':name đã trả lại một hồ sơ — cần phân công lại',
                'reminder' => [
                    'awaiting' => 'Một hồ sơ đang chờ anh/chị nhận',
                    'unaccepted' => ':name chưa nhận một hồ sơ được giao',
                ],
            ],
            'headline' => [
                'declined' => ':name đã trả lại một hồ sơ được giao. Họ vẫn ở trong đội ngũ cho tới khi được phân công lại.',
                'reminder' => [
                    'awaiting' => 'Một hồ sơ đã chờ anh/chị nhận :since (tính theo giờ làm việc).',
                    'unaccepted' => ':name chưa nhận hồ sơ được giao sau :since (tính theo giờ làm việc).',
                ],
            ],
            'fields' => [
                'matter' => 'Mã hồ sơ',
                'member' => 'Người được giao',
                'role' => 'Vai',
                'reason' => 'Lý do trả lại',
            ],
            'action' => [
                'declined' => 'Mở hồ sơ — tab "Đội ngũ" để phân công lại',
                'reminder' => [
                    'awaiting' => 'Mở "Việc của tôi" để bấm "Đã nhận hồ sơ" hoặc "Trả lại"',
                    'unaccepted' => 'Mở hồ sơ — tab "Đội ngũ"',
                ],
            ],
        ],
    ],

    // Bàn giao vụ (một vụ hay hàng loạt): việc của lead cũ chuyển sang lead mới; thư tổng hợp bàn giao.
    'handover' => [
        'email' => [
            'tasks_heading' => 'Việc nội bộ chuyển sang anh/chị (chờ anh/chị bấm "Đã nhận"):',
            'task_line' => ':title — hạn :due',
            'accept_hint' => 'Hồ sơ vừa bàn giao hiện ở khối "Hồ sơ chờ tôi nhận" trong mục Việc của tôi: hãy bấm "Đã nhận hồ sơ", hoặc "Trả lại" kèm lý do nếu không nhận được.',
        ],
    ],

    // Lịch nhắc `tasks.remind` (App\Actions\Schedule\RemindPendingWork) — chuông TaskReminderAlert và thư
    // TaskReminder. :title, :who, :detail đã escape ở chuông.
    'reminder' => [
        'alert' => [
            'awaiting' => [
                'title' => 'Một việc đang chờ anh/chị nhận',
                'body' => 'Việc ":title" đã chờ anh/chị nhận :detail (tính theo giờ làm việc). Bấm "Đã nhận" hoặc "Trả lại".',
            ],
            'unaccepted' => [
                'title' => 'Việc anh/chị giao chưa được nhận',
                'body' => ':who chưa nhận việc ":title" sau :detail (tính theo giờ làm việc). Hãy trao đổi với họ hoặc giao lại.',
            ],
            'due_soon' => [
                'title' => 'Một việc của anh/chị sắp đến hạn',
                'body' => 'Việc ":title" đến hạn ngày :detail.',
            ],
            'overdue' => [
                'title' => 'Một việc của anh/chị đã quá hạn',
                'body' => 'Việc ":title" đã quá hạn :detail ngày. Hãy hoàn thành, hoặc báo người giao để dời hạn.',
            ],
            'overdue_assigner' => [
                'title' => 'Việc anh/chị giao đã quá hạn',
                'body' => 'Việc ":title" giao cho :who đã quá hạn :detail ngày.',
            ],
        ],
        'email' => [
            'assignee' => 'Người nhận việc',
            'subject' => [
                'awaiting' => 'Một việc đang chờ anh/chị nhận',
                'unaccepted' => 'Một việc anh/chị giao chưa được nhận',
                'due_soon' => 'Một việc của anh/chị sắp đến hạn',
                'overdue' => 'Một việc của anh/chị đã quá hạn',
                'overdue_assigner' => 'Một việc anh/chị giao đã quá hạn',
            ],
            'headline' => [
                'awaiting' => 'Việc dưới đây đã chờ anh/chị nhận :detail (tính theo giờ làm việc).',
                'unaccepted' => ':name chưa nhận việc anh/chị giao sau :detail (tính theo giờ làm việc).',
                'due_soon' => 'Việc dưới đây đến hạn ngày :detail.',
                'overdue' => 'Việc dưới đây đã quá hạn :detail ngày.',
                'overdue_assigner' => 'Việc anh/chị giao cho :name đã quá hạn :detail ngày.',
            ],
            'action' => [
                'awaiting' => 'Mở mục Công việc để bấm "Đã nhận" hoặc "Trả lại"',
                'unaccepted' => 'Mở mục Công việc — tab "Tôi đã giao"',
                'due_soon' => 'Mở mục Công việc — tab "Đang làm"',
                'overdue' => 'Mở mục Công việc — tab "Đang làm"',
                'overdue_assigner' => 'Mở mục Công việc — tab "Tôi đã giao"',
            ],
        ],
    ],

    // "Theo dõi đội ngũ" và trang của một người (App\Actions\Performance\BuildTaskWorkload).
    'workload' => [
        'heading' => 'Giao – nhận nội bộ',
        'columns' => [
            'tasksInProgress' => 'Việc đang làm',
            'tasksAwaiting' => 'Việc chờ nhận',
            'tasksOverdue' => 'Việc quá hạn',
            'tasksReturned' => 'Việc bị trả lại',
            'seatsAwaiting' => 'Hồ sơ chưa nhận',
            'seatsReturned' => 'Hồ sơ trả lại',
        ],
        'explain' => [
            'tasksInProgress' => 'Số việc giao nội bộ người này đã bấm "Đã nhận", chưa hoàn thành — trong các việc anh/chị được xem.',
            'tasksAwaiting' => 'Số việc giao cho người này chưa bấm "Đã nhận" hay "Trả lại".',
            'tasksOverdue' => 'Số việc người này đang giữ (chờ nhận hoặc đang làm) đã qua hạn.',
            'tasksReturned' => 'Số việc NGƯỜI NÀY ĐÃ GIAO bị người nhận trả lại, đang chờ người này giao lại hoặc huỷ.',
            'seatsAwaiting' => 'Số hồ sơ đang mở người này vừa được giao vào đội ngũ (hay nhận bàn giao làm luật sư phụ trách) mà chưa bấm "Đã nhận hồ sơ".',
            'seatsReturned' => 'Số hồ sơ đang mở người này đã trả lại kèm lý do — cần quản lý hoặc luật sư phụ trách phân công lại (gỡ khỏi đội ngũ, hay bàn giao vụ cho người khác).',
        ],
    ],

    // "Hiệu suất theo kỳ" (App\Actions\Performance\BuildTaskPerformance).
    'performance' => [
        'column' => 'Việc hoàn thành đúng hạn',
        'declined' => ':count lần trả lại việc',
        'explain' => 'Trong số việc giao nội bộ CÓ HẠN mà người này hoàn thành trong kỳ, số việc xong không muộn hơn hết ngày hạn, trên tổng số việc đó. Dưới 5 việc thì không tính tỉ lệ. Dòng dưới là số lần người này bấm "Trả lại" một việc trong kỳ (theo nhật ký, kể cả việc đã giao lại cho người khác). Không xếp hạng, không so người này với người khác.',
    ],

    // Bảng điều khiển (App\Filament\Admin\Widgets\MyWorkQueueWidget).
    'dashboard' => [
        'heading' => 'Việc cần xử lý của tôi',
        'description' => 'Bấm vào một ô để mở đúng danh sách.',
        'awaiting' => 'Việc chờ tôi nhận',
        'awaiting_hint' => 'Bấm "Đã nhận" hoặc "Trả lại"',
        'overdue' => 'Việc của tôi đã quá hạn',
        'overdue_hint' => 'Việc tôi đang giữ đã qua hạn',
        'seats' => 'Hồ sơ chờ tôi nhận',
        'seats_hint' => 'Bấm "Đã nhận hồ sơ" hoặc "Trả lại"',
        'returned' => 'Việc tôi giao bị trả lại',
        'returned_hint' => 'Giao lại cho người khác hoặc huỷ',
    ],
];
