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
        // Vòng sửa 1 (I1): việc bị trả lại mà người giao đã nghỉ — `Task::reassignmentReason()` = assigner_gone.
        'assigner_gone' => 'Người giao đã nghỉ — cần giao lại hoặc huỷ',
        // Vòng sửa cuối 1 (F3, F4): ba mã mới của `Task::reassignmentReason()`.
        'assignee_seat_declined' => 'Người nhận đã trả lại hồ sơ này — cần giao lại',
        'assignee_off_access' => 'Người nhận không còn mở được hồ sơ — cần giao lại',
        'assigner_off_access' => 'Người giao không còn mở được hồ sơ — cần giao lại hoặc huỷ',
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
        'needs_reassignment' => 'Cần giao lại (người nhận đã nghỉ, rời đội ngũ, trả lại hay không còn mở được hồ sơ)',
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
        'assign_submit' => 'Giao việc',
        'assign_success' => 'Đã giao việc. Người nhận được báo bằng chuông và thư.',
        'accept' => 'Đã nhận',
        // Vòng sửa cuối 1 (F5): lời báo cho người giao đi qua `ResolveStaffRecipients::forTask()` — người giao đã
        // nghỉ hay không còn mở được hồ sơ thì không nhận, nên câu không hứa chắc.
        'accept_success' => 'Đã nhận việc. Người giao được báo nếu còn theo dõi việc này.',
        'decline' => 'Trả lại',
        'decline_heading' => 'Trả lại việc này cho người giao?',
        'decline_submit' => 'Trả lại',
        'decline_success' => 'Đã trả lại việc. Người giao (hay luật sư phụ trách hồ sơ, hoặc người quản lý hồ sơ, khi người giao không còn theo dõi việc) được báo kèm lý do.',
        'complete' => 'Hoàn thành',
        'complete_heading' => 'Báo việc này đã hoàn thành?',
        'complete_submit' => 'Hoàn thành',
        'complete_success' => 'Đã báo hoàn thành. Người giao được báo nếu còn theo dõi việc này.',
        'view' => 'Xem',
        'view_heading' => 'Chi tiết việc',
        'close' => 'Đóng',
        'edit' => 'Sửa',
        'edit_heading' => 'Sửa việc',
        'edit_submit' => 'Lưu',
        // Làn fa1 (phán quyết 1): người đang giữ việc được báo điều đã đổi.
        'edit_success' => 'Đã lưu thay đổi. Người đang giữ việc (nếu có) được báo bằng chuông và thư điều đã đổi.',
        // Vòng sửa 2 (c2): người giữ không còn giữ được việc — không ai được báo.
        'edit_success_needs_reassignment' => 'Đã lưu thay đổi. Người nhận không còn giữ được việc này nên không ai được báo: việc hiện "Cần giao lại" — hãy bấm "Giao lại".',
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
        // Vòng sửa 1 (I1): người giao đã rời vụ hay nghỉ việc, luật sư phụ trách hiện tại nhận thay
        // (`ResolveStaffRecipients::forTaskAssigner()`). :assigner đã escape.
        'declined_on_behalf' => [
            'title' => 'Một việc trên hồ sơ anh/chị phụ trách bị trả lại',
            'body' => ':who trả lại việc ":title" do :assigner giao. Lý do: :reason. :assigner không còn theo dõi '
                .'việc này nên anh/chị nhận thay: hãy giao lại hoặc huỷ ở tab "Công việc" của hồ sơ.',
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
            'declined_on_behalf' => ':name đã trả lại một việc trên hồ sơ anh/chị phụ trách',
        ],
        'headline' => [
            'assigned' => ':name vừa giao cho anh/chị một việc.',
            'accepted' => ':name đã nhận việc anh/chị giao.',
            'declined' => ':name đã trả lại việc anh/chị giao.',
            'completed' => ':name đã hoàn thành việc anh/chị giao.',
            'declined_on_behalf' => ':name đã trả lại việc do :assigner giao trên hồ sơ anh/chị phụ trách. :assigner '
                .'không còn theo dõi việc này nên anh/chị nhận thay.',
        ],
        'fields' => [
            'title' => 'Việc',
            'matter' => 'Vụ việc',
            'due_date' => 'Hạn',
            'priority' => 'Mức ưu tiên',
            'reason' => 'Lý do trả lại',
            'note' => 'Ghi chú',
            'assigner' => 'Người giao',
        ],
        'no_due_date' => 'Không đặt hạn',
        'action' => [
            'assigned' => 'Mở mục Công việc để bấm "Đã nhận" hoặc "Trả lại"',
            'accepted' => 'Mở mục Công việc — tab "Tôi đã giao"',
            'declined' => 'Mở mục Công việc để giao lại hoặc huỷ việc',
            'completed' => 'Mở mục Công việc — tab "Tôi đã giao"',
            'declined_on_behalf' => 'Mở tab Công việc của hồ sơ để giao lại hoặc huỷ việc',
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
        // Vòng sửa polish 1 (phán quyết controller (3)): ghế chờ nhận mà người được giao không còn nhận được hồ
        // sơ (đã nghỉ việc, không còn mở được hồ sơ — App\Actions\Matter\SeatsNeedingReassignment). Không là một
        // tình trạng của ghế (enum), chỉ là cách hiện.
        'needs_reassignment' => 'Cần phân công lại',
        'needs_reassignment_note' => 'Người được giao không còn nhận được hồ sơ (đã nghỉ việc hoặc không còn mở được hồ sơ) — gỡ khỏi đội ngũ, hay bàn giao vụ nếu là luật sư phụ trách.',
        'my' => [
            'heading' => 'Hồ sơ chờ tôi nhận',
            'description' => 'Anh/chị vừa được giao vào đội ngũ các hồ sơ này. Bấm "Đã nhận hồ sơ", hoặc "Trả lại" kèm lý do nếu không nhận được (quá tải, xung đột lợi ích…).',
            'empty' => 'Không có hồ sơ nào chờ anh/chị nhận.',
        ],
        'fields' => [
            'decline_reason_help' => 'Người phân công lại hồ sơ sẽ đọc lý do này — ví dụ đang quá tải, xung đột lợi ích. Anh/chị vẫn ở trong đội ngũ cho tới khi được phân công lại.',
        ],
        'actions' => [
            'accept' => 'Đã nhận hồ sơ',
            // Vòng sửa cuối 1 (F5): người nhận lời báo là người còn hoạt động và còn mở được hồ sơ
            // (`ResolveStaffRecipients::qualifies()`); vụ hạn chế thì "người quản lý vụ" là quản trị viên.
            'accept_success' => 'Đã nhận hồ sơ. Người giao được báo nếu còn theo dõi hồ sơ.',
            'decline' => 'Trả lại',
            'decline_heading' => 'Trả lại hồ sơ này?',
            'decline_description' => 'Anh/chị không bị gỡ khỏi vụ ngay: người giao (nếu còn theo dõi hồ sơ), luật sư phụ trách và người quản lý hồ sơ (trưởng phòng; với hồ sơ hạn chế truy cập là quản trị viên) được báo kèm lý do để phân công lại.',
            'decline_submit' => 'Trả lại',
            'decline_success' => 'Đã trả lại hồ sơ. Người giao (nếu còn theo dõi hồ sơ), luật sư phụ trách và người quản lý hồ sơ được báo kèm lý do.',
        ],
        'errors' => [
            // Một câu cho mọi lần không được thao tác (SPEC §10.10).
            'unavailable' => 'Hồ sơ này không còn mở được với anh/chị, hoặc anh/chị không còn trong đội ngũ của vụ. Hãy tải lại trang.',
            'not_in_state' => 'Ghế của anh/chị trong hồ sơ này hiện ở tình trạng ":status" nên thao tác này không làm được nữa. Hãy trao đổi với luật sư phụ trách hoặc quản lý.',
        ],
        // Vòng sửa cuối 1 (F2): chỗ bấm "Đã nhận hồ sơ" của người nhận (`App\Support\Tasks\SeatInbox::whereKey()`) —
        // quản trị viên, kế toán, nhân viên kinh doanh không mở được "Việc của tôi".
        'where' => [
            'my_work' => 'ở mục Việc của tôi',
            'team_tab' => 'trên dòng của anh/chị ở tab "Đội ngũ" của hồ sơ',
        ],
        // Chuông (App\Notifications\Staff\MatterAssignmentAlert). :code, :member, :who, :role, :reason, :since đã escape;
        // :where là một câu của `where` ở trên.
        'alert' => [
            'assigned' => [
                'title' => 'Anh/chị được giao hồ sơ :code',
                'body' => ':who đã giao anh/chị vào đội ngũ hồ sơ :code với vai :role. Bấm "Đã nhận hồ sơ" hoặc "Trả lại" :where.',
            ],
            // Vòng sửa polish 1: một chuông cho cả lô bàn giao hàng loạt, rung bởi thư tổng hợp của lô
            // (App\Jobs\SendReassignmentDigest). :codes là danh sách mã đã escape (tối đa 10, còn lại "…").
            'assigned_batch' => [
                'title' => 'Anh/chị được bàn giao :count hồ sơ',
                'body' => ':who đã bàn giao cho anh/chị :count hồ sơ với vai :role: :codes. Bấm "Đã nhận hồ sơ" hoặc "Trả lại" cho từng hồ sơ :where.',
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
                'body' => 'Hồ sơ :code (vai :role) đã chờ anh/chị nhận :since (tính theo giờ làm việc). Bấm "Đã nhận hồ sơ" hoặc "Trả lại" :where.',
            ],
            'reminder_unaccepted' => [
                'title' => 'Hồ sơ :code chưa được nhận',
                'body' => ':member chưa nhận hồ sơ :code (vai :role) sau :since (tính theo giờ làm việc). Hãy trao đổi với họ hoặc phân công người khác.',
            ],
            // Vòng sửa polish 1 (phán quyết controller (3)): hai lời nhắc trên GỘP cho nhiều hồ sơ của một người
            // trong một lượt lịch (App\Jobs\SendSeatReminderDigest). :codes đã escape (tối đa 10 dòng, còn lại "…");
            // ở lời báo người giao mỗi dòng là "mã (người được giao)".
            'reminder_awaiting_batch' => [
                'title' => ':count hồ sơ đang chờ anh/chị nhận',
                'body' => ':count hồ sơ đã chờ anh/chị nhận quá :since làm việc: :codes. Bấm "Đã nhận hồ sơ" hoặc "Trả lại" cho từng hồ sơ :where.',
            ],
            'reminder_unaccepted_batch' => [
                'title' => ':count hồ sơ chưa được nhận',
                'body' => ':count hồ sơ được giao chưa được nhận sau :since làm việc: :codes. Hãy trao đổi với người được giao hoặc phân công người khác.',
            ],
        ],
        // Thư (App\Mail\Staff\MatterAssignmentNotice). Tiêu đề thư không mang mã hồ sơ.
        'email' => [
            'subject' => [
                'declined' => ':name đã trả lại một hồ sơ — cần phân công lại',
                'reminder' => [
                    'awaiting' => 'Một hồ sơ đang chờ anh/chị nhận',
                    'unaccepted' => ':name chưa nhận một hồ sơ được giao',
                    // Vòng sửa polish 1: thư gộp nhiều hồ sơ (App\Jobs\SendSeatReminderDigest).
                    'awaiting_batch' => ':count hồ sơ đang chờ anh/chị nhận',
                    'unaccepted_batch' => ':count hồ sơ được giao chưa được nhận',
                ],
            ],
            'headline' => [
                'declined' => ':name đã trả lại một hồ sơ được giao. Họ vẫn ở trong đội ngũ cho tới khi được phân công lại.',
                'reminder' => [
                    'awaiting' => 'Một hồ sơ đã chờ anh/chị nhận :since (tính theo giờ làm việc).',
                    'unaccepted' => ':name chưa nhận hồ sơ được giao sau :since (tính theo giờ làm việc).',
                    'awaiting_batch' => ':count hồ sơ đã chờ anh/chị nhận quá :since làm việc:',
                    'unaccepted_batch' => ':count hồ sơ được giao chưa được nhận sau :since làm việc — hãy trao đổi với người được giao hoặc phân công người khác:',
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
                    // Vòng sửa cuối 1 (F2): người không mở được "Việc của tôi" (quản trị viên…) — `SeatInbox`.
                    'awaiting_team_tab' => 'Mở tab "Đội ngũ" của hồ sơ để bấm "Đã nhận hồ sơ" hoặc "Trả lại"',
                    'unaccepted' => 'Mở hồ sơ — tab "Đội ngũ"',
                ],
            ],
        ],
    ],

    // Bàn giao vụ (một vụ hay hàng loạt): việc của lead cũ chuyển sang lead mới; thư tổng hợp bàn giao.
    'handover' => [
        'email' => [
            // Vòng sửa cuối 1 (F5): danh sách dựng lại lúc gửi có cả việc lead mới đã bấm nhận — trạng thái trên từng dòng.
            'tasks_heading' => 'Việc nội bộ chuyển sang anh/chị (việc "Chờ nhận" cần anh/chị bấm "Đã nhận" hoặc "Trả lại" ở tab "Chờ tôi nhận" của trang Công việc):',
            'task_line' => ':title — :status, hạn :due',
            // Vòng sửa 1 (I1): việc lead cũ đã giao trên vụ — lead mới thành người giao (MoveTasksOnHandover).
            'followed_heading' => 'Việc luật sư phụ trách cũ đã giao trên hồ sơ, nay anh/chị là người giao (theo dõi ở tab "Tôi đã giao"):',
            'followed_line' => ':title — :assignee, :status, hạn :due',
            'accept_hint' => 'Hồ sơ vừa bàn giao hiện ở khối "Hồ sơ chờ tôi nhận" trong mục Việc của tôi: hãy bấm "Đã nhận hồ sơ", hoặc "Trả lại" kèm lý do nếu không nhận được.',
            // Vòng sửa cuối 1 (F2): người không mở được "Việc của tôi" (quản trị viên…) — `SeatInbox`.
            'accept_hint_team_tab' => 'Hồ sơ vừa bàn giao chờ anh/chị xác nhận: mở tab "Đội ngũ" của từng hồ sơ và bấm "Đã nhận hồ sơ" trên dòng của anh/chị, hoặc "Trả lại" kèm lý do nếu không nhận được.',
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
            // Vòng sửa 1 (I1): luật sư phụ trách hiện tại nhận thay người giao đã rời vụ hay nghỉ việc.
            'unaccepted_on_behalf' => [
                'title' => 'Một việc trên hồ sơ anh/chị phụ trách chưa được nhận',
                'body' => ':who chưa nhận việc ":title" do :assigner giao, sau :detail (tính theo giờ làm việc). :assigner '
                    .'không còn theo dõi việc này nên anh/chị nhận thay: hãy trao đổi với :who hoặc giao lại.',
            ],
            'overdue_assigner_on_behalf' => [
                'title' => 'Một việc trên hồ sơ anh/chị phụ trách đã quá hạn',
                'body' => 'Việc ":title" do :assigner giao cho :who đã quá hạn :detail ngày. :assigner không còn theo dõi '
                    .'việc này nên anh/chị nhận thay.',
            ],
            // làn fa1 (vòng sửa 1, I1): việc sắp đến hạn mà người nhận không còn làm được — báo người giao
            // (RemindPendingWork::DUE_SOON_ASSIGNER); câu "cần giao lại" nối sau.
            'due_soon_assigner' => [
                'title' => 'Một việc anh/chị giao sắp đến hạn mà không còn người làm',
                'body' => 'Việc ":title" giao cho :who đến hạn ngày :detail.',
            ],
            'due_soon_assigner_on_behalf' => [
                'title' => 'Một việc trên hồ sơ anh/chị phụ trách sắp đến hạn mà không còn người làm',
                'body' => 'Việc ":title" do :assigner giao cho :who đến hạn ngày :detail. :assigner không còn theo dõi '
                    .'việc này nên anh/chị nhận thay.',
            ],
            // hết khối làn fa1
        ],
        'email' => [
            'assignee' => 'Người nhận việc',
            'subject' => [
                'awaiting' => 'Một việc đang chờ anh/chị nhận',
                'unaccepted' => 'Một việc anh/chị giao chưa được nhận',
                'due_soon' => 'Một việc của anh/chị sắp đến hạn',
                'overdue' => 'Một việc của anh/chị đã quá hạn',
                'overdue_assigner' => 'Một việc anh/chị giao đã quá hạn',
                'unaccepted_on_behalf' => 'Một việc trên hồ sơ anh/chị phụ trách chưa được nhận',
                'overdue_assigner_on_behalf' => 'Một việc trên hồ sơ anh/chị phụ trách đã quá hạn',
                // làn fa1 (vòng sửa 1, I1)
                'due_soon_assigner' => 'Một việc anh/chị giao sắp đến hạn mà không còn người làm',
                'due_soon_assigner_on_behalf' => 'Một việc trên hồ sơ anh/chị phụ trách sắp đến hạn mà không còn người làm',
            ],
            'headline' => [
                'awaiting' => 'Việc dưới đây đã chờ anh/chị nhận :detail (tính theo giờ làm việc).',
                'unaccepted' => ':name chưa nhận việc anh/chị giao sau :detail (tính theo giờ làm việc).',
                'due_soon' => 'Việc dưới đây đến hạn ngày :detail.',
                'overdue' => 'Việc dưới đây đã quá hạn :detail ngày.',
                'overdue_assigner' => 'Việc anh/chị giao cho :name đã quá hạn :detail ngày.',
                'unaccepted_on_behalf' => ':name chưa nhận việc do :assigner giao trên hồ sơ anh/chị phụ trách, sau :detail '
                    .'(tính theo giờ làm việc). :assigner không còn theo dõi việc này nên anh/chị nhận thay.',
                'overdue_assigner_on_behalf' => 'Việc do :assigner giao cho :name trên hồ sơ anh/chị phụ trách đã quá hạn '
                    .':detail ngày. :assigner không còn theo dõi việc này nên anh/chị nhận thay.',
                // làn fa1 (vòng sửa 1, I1)
                'due_soon_assigner' => 'Việc anh/chị giao cho :name đến hạn ngày :detail.',
                'due_soon_assigner_on_behalf' => 'Việc do :assigner giao cho :name trên hồ sơ anh/chị phụ trách đến hạn ngày '
                    .':detail. :assigner không còn theo dõi việc này nên anh/chị nhận thay.',
            ],
            'action' => [
                'awaiting' => 'Mở mục Công việc để bấm "Đã nhận" hoặc "Trả lại"',
                'unaccepted' => 'Mở mục Công việc — tab "Tôi đã giao"',
                'due_soon' => 'Mở mục Công việc — tab "Đang làm"',
                'overdue' => 'Mở mục Công việc — tab "Đang làm"',
                'overdue_assigner' => 'Mở mục Công việc — tab "Tôi đã giao"',
                'unaccepted_on_behalf' => 'Mở tab Công việc của hồ sơ',
                'overdue_assigner_on_behalf' => 'Mở tab Công việc của hồ sơ',
                // làn fa1 (vòng sửa 1, I1)
                'due_soon_assigner' => 'Mở mục Công việc — tab "Tôi đã giao"',
                'due_soon_assigner_on_behalf' => 'Mở tab Công việc của hồ sơ',
            ],
        ],
    ],

    // "Theo dõi đội ngũ" và trang của một người (App\Actions\Performance\BuildTaskWorkload).
    'workload' => [
        'heading' => 'Giao – nhận nội bộ',
        // Vòng sửa polish 1: dòng trên bảng Theo dõi đội ngũ (App\Filament\Admin\Pages\TeamOverview::needsReassignment()),
        // chỉ cho quản lý/quản trị; trỏ trang Công việc, tab "Tất cả", bộ lọc "Cần giao lại".
        'needs_reassignment' => 'Việc cần giao lại: :count',
        'needs_reassignment_hint' => 'việc còn mở mà người đang giữ không làm được nữa (đã nghỉ việc, rời đội ngũ, trả lại hồ sơ, không còn mở được hồ sơ), hay việc bị trả lại mà người giao không còn theo dõi. Bấm để mở danh sách và giao lại.',
        // Vòng sửa polish 1 (phán quyết controller (3)): ghế chờ nhận của người không còn nhận được hồ sơ
        // (App\Actions\Matter\SeatsNeedingReassignment) — mỗi mã mở tab "Đội ngũ" của hồ sơ đó.
        'seats_needing_reassignment' => 'Hồ sơ cần phân công lại: :count',
        'seats_needing_reassignment_hint' => 'hồ sơ còn chờ một người bấm "Đã nhận hồ sơ" mà người đó đã nghỉ việc hay không còn mở được hồ sơ — gỡ họ khỏi đội ngũ ở tab "Đội ngũ", hay bàn giao vụ.',
        'columns' => [
            'tasksInProgress' => 'Việc đang làm',
            'tasksAwaiting' => 'Việc chờ nhận',
            'tasksOverdue' => 'Việc quá hạn',
            'tasksReturned' => 'Việc bị trả lại',
            'seatsAwaiting' => 'Hồ sơ chưa nhận',
            'seatsReturned' => 'Hồ sơ trả lại',
        ],
        'explain' => [
            // Làn fa1, vòng sửa 1 (m10): ba cột việc cùng một nghĩa "đang giữ" — không tính việc "Cần giao lại".
            'tasksInProgress' => 'Số việc giao nội bộ người này đã bấm "Đã nhận", chưa hoàn thành — trong các việc anh/chị được xem. Không tính việc "Cần giao lại" vì người này đã sang người theo dõi, trả lại hay không còn mở được hồ sơ.',
            'tasksAwaiting' => 'Số việc giao cho người này chưa bấm "Đã nhận" hay "Trả lại". Không tính việc "Cần giao lại" vì người này đã sang người theo dõi, trả lại hay không còn mở được hồ sơ.',
            'tasksOverdue' => 'Số việc người này đang giữ (chờ nhận hoặc đang làm) đã qua hạn — việc chung hay việc của hồ sơ còn mở; việc của hồ sơ đã kết thúc không tính (chỉ còn huỷ được, và không được nhắc), việc "Cần giao lại" cũng không.',
            'tasksReturned' => 'Số việc NGƯỜI NÀY ĐÃ GIAO bị người nhận trả lại, đang chờ người này giao lại hoặc huỷ.',
            'seatsAwaiting' => 'Số hồ sơ đang mở người này vừa được giao vào đội ngũ (hay nhận bàn giao làm luật sư phụ trách) mà chưa bấm "Đã nhận hồ sơ".',
            'seatsReturned' => 'Số hồ sơ đang mở người này đã trả lại kèm lý do — cần quản lý hoặc luật sư phụ trách phân công lại (gỡ khỏi đội ngũ, hay bàn giao vụ cho người khác).',
        ],
    ],

    // "Hiệu suất theo kỳ" (App\Actions\Performance\BuildTaskPerformance).
    'performance' => [
        'column' => 'Việc hoàn thành đúng hạn',
        'declined' => ':count lần trả lại việc',
        // Làn fa1 (phán quyết 6, 2026-10-16): mẫu số thêm việc còn giữ đã quá hạn, hạn trong kỳ.
        'explain' => 'Số việc giao nội bộ CÓ HẠN người này xong không muộn hơn hết ngày hạn, trên tổng của: mọi việc có hạn người này hoàn thành trong kỳ, cộng mọi việc người này còn giữ (chờ nhận hay đang làm) đã quá hạn mà hạn rơi trong kỳ — việc bỏ dở quá hạn tính là trễ. Không tính: việc đã huỷ, việc đã trả lại hay đã giao lại cho người khác (tính cho người đang giữ), việc của hồ sơ đã kết thúc, việc "Cần giao lại" vì người nhận đã rời đội ngũ hay trả lại hồ sơ. Việc được mở lại để làm lại không còn là "đã hoàn thành" cho tới khi xong lần nữa. Dưới 5 việc thì không tính tỉ lệ. Số của kỳ đã qua có thể đổi khi một việc quá hạn được hoàn thành, huỷ hay giao lại sau đó. Dòng dưới là số lần người này bấm "Trả lại" một việc trong kỳ (theo nhật ký, kể cả việc đã giao lại cho người khác). Không xếp hạng, không so người này với người khác.',
    ],

    // Bảng điều khiển (App\Filament\Admin\Widgets\MyWorkQueueWidget).
    'dashboard' => [
        'heading' => 'Việc cần xử lý của tôi',
        'description' => 'Bấm vào một ô để mở đúng danh sách.',
        'awaiting' => 'Việc chờ tôi nhận',
        'awaiting_hint' => 'Bấm "Đã nhận" hoặc "Trả lại"',
        'overdue' => 'Việc của tôi đã quá hạn',
        'overdue_hint' => 'Việc tôi đang giữ đã qua hạn, trên hồ sơ còn mở',
        'seats' => 'Hồ sơ chờ tôi nhận',
        'seats_hint' => 'Bấm "Đã nhận hồ sơ" hoặc "Trả lại"',
        'returned' => 'Việc tôi giao bị trả lại',
        'returned_hint' => 'Giao lại cho người khác hoặc huỷ',
        // Gộp integ-fa (rà soát chéo 2026-10-18, mục 17): mở trang "Tài liệu" lọc sẵn "Chờ tôi duyệt".
        'approvals' => 'Văn bản chờ tôi duyệt',
        'approvals_hint' => 'Bấm "Đánh dấu đã ký, đã nộp" hoặc "Trả về bản nháp"',
    ],

    // =============================================================================================
    // làn fa1 (2026-10-16) — sửa phát hiện của đợt kiểm tra luồng nội bộ: CÔNG VIỆC (giao – nhận – làm – xong).
    // Mọi khoá mới của làn nằm trong khối này.
    // =============================================================================================

    // Trang "Công việc": dòng mô tả đầu trang; câu thêm khi tab "Chờ tôi nhận" rỗng mà còn hồ sơ chờ nhận.
    // :where là một câu của `seat.where`.
    'page' => [
        'subheading' => 'Mọi việc anh/chị giao hoặc được giao; hồ sơ chờ nhận ở mục Việc của tôi.',
        // Vòng sửa 1 (m3): người không nhận hồ sơ ở "Việc của tôi" (quản trị viên, kế toán, kinh doanh — SeatInbox).
        'subheading_short' => 'Mọi việc anh/chị giao hoặc được giao.',
        'awaiting_empty_seats' => 'Anh/chị đang có :count hồ sơ chờ nhận — bấm "Đã nhận hồ sơ" :where.',
    ],

    // Nút "Giao việc" đầu trang Công việc (App\Filament\Admin\Resources\Tasks\Pages\ListTasks::assignTaskAction()).
    'assign' => [
        'matter' => 'Vụ việc',
        'matter_none' => 'Việc chung (không thuộc vụ nào)',
        'matter_help' => 'Để trống nếu là việc chung. Chỉ các vụ đang mở anh/chị được giao việc.',
        // Nhãn kèm tên mỗi người nhận — chỉ đếm việc anh/chị xem được (vòng sửa 1, I2: câu nói rõ phạm vi đó, và
        // `workload_scope` nối sau câu gợi ý của ô "Giao cho" — ProvidesTaskActions::assigneeHelp()).
        'workload' => 'đang giữ :held việc anh/chị thấy được, :overdue quá hạn',
        'workload_none' => 'không có việc nào anh/chị thấy được',
        // Sửa 2026-10-21: ô "Giao cho" rỗng — TaskAssignees::emptyListReason(). Không dấu hai chấm trong câu (Livewire
        // đọc dấu hai chấm của câu lỗi như tham số luật khi so trong test).
        'empty_matter_can_manage' => 'Chưa có ai nhận được việc này — việc của hồ sơ :code chỉ giao được cho người trong đội ngũ giữ việc (luật sư phụ trách, luật sư cộng sự, trợ lý), không tính anh/chị, người đang giữ chính việc này, người theo dõi và người đã trả lại hồ sơ. Thêm người vào tab "Đội ngũ" của hồ sơ rồi giao lại.',
        'empty_matter_ask' => 'Chưa có ai nhận được việc này — việc của hồ sơ :code chỉ giao được cho người trong đội ngũ giữ việc (luật sư phụ trách, luật sư cộng sự, trợ lý), không tính anh/chị, người đang giữ chính việc này, người theo dõi và người đã trả lại hồ sơ. Nhờ luật sư phụ trách hồ sơ hoặc trưởng phòng thêm người vào đội ngũ rồi giao lại.',
        'empty_matter_restricted' => 'Chưa có ai nhận được việc này — :code là hồ sơ hạn chế, chỉ luật sư phụ trách và quản trị viên trong đội ngũ của hồ sơ mở được nó, nên chỉ họ nhận được việc (không tính anh/chị và người đang giữ chính việc này). Muốn giao cho người khác, quản trị viên phải được thêm vào đội ngũ của hồ sơ.',
        'empty_general' => 'Chưa có ai nhận được việc này — không còn nhân sự nào khác đang làm việc.',
        'open_team_tab' => 'Mở tab Đội ngũ của hồ sơ',
        'workload_scope' => 'Số việc bên mỗi tên chỉ tính những việc anh/chị xem được — người đó có thể còn giữ việc trên hồ sơ khác.',
    ],

    // "Giao lại": ô hạn khi hạn cũ đã qua.
    'reassign' => [
        'due_past_help' => 'Hạn cũ :date đã qua — đã gợi ý một hạn mới (cùng số ngày người trước có); đổi nếu cần.',
    ],

    // "Mở lại" một việc đã hoàn thành (App\Actions\Task\ReopenTask).
    'reopen' => [
        'action' => 'Mở lại',
        'heading' => 'Yêu cầu làm lại việc này?',
        'description' => 'Việc trở về "Đang làm" cho cùng người nhận, và họ được báo kèm nhận xét của anh/chị. Nếu họ không còn giữ được việc (đã nghỉ, rời đội ngũ, trả lại hồ sơ), việc hiện "Cần giao lại" để anh/chị giao người khác.',
        'heading_own' => 'Mở lại việc anh/chị vừa báo hoàn thành?',
        'description_own' => 'Dùng khi bấm "Hoàn thành" nhầm. Việc trở về "Đang làm"; người giao được báo.',
        'reason_help' => 'Bắt buộc — điều chưa đạt, cần làm thêm gì.',
        'reason_help_own' => 'Không bắt buộc.',
        'submit' => 'Mở lại',
        'success' => 'Đã mở lại việc — việc trở về "Đang làm".',
        // Vòng sửa 1 (m2): người giữ không còn giữ được việc — không ai được báo.
        'success_needs_reassignment' => 'Đã mở lại việc, nhưng người nhận không còn giữ được việc này: việc hiện "Cần giao lại" — hãy bấm "Giao lại".',
        'errors' => [
            'matter_closed' => 'Hồ sơ của việc này đã kết thúc nên không mở lại được việc. Nếu còn việc phải làm, hãy mở lại hồ sơ trước (trưởng phòng hoặc quản trị viên).',
        ],
    ],

    // "Khôi phục" một việc đã huỷ (App\Actions\Task\RestoreTask).
    'restore' => [
        'action' => 'Khôi phục',
        'heading' => 'Khôi phục việc đã huỷ?',
        'description' => 'Việc trở về "Chờ nhận" cho người nhận cũ (nếu họ còn nhận được việc), và họ được báo kèm lý do. Việc bị huỷ khi đang "Bị trả lại" thì trở về "Bị trả lại" để anh/chị giao người khác.',
        'reason_help' => 'Bắt buộc — ví dụ huỷ nhầm, khách vẫn cần.',
        'submit' => 'Khôi phục',
        'success' => 'Đã khôi phục việc — người nhận cũ được báo.',
        // Vòng sửa 2 (c4): người bấm là chính người nhận cũ.
        'success_own' => 'Đã khôi phục việc — việc trở về "Đang làm" cho anh/chị.',
        'success_needs_reassignment' => 'Đã khôi phục việc, nhưng người nhận cũ không còn nhận được việc này: việc hiện "Cần giao lại" — hãy bấm "Giao lại".',
        // Vòng sửa 1 (m1): việc bị huỷ khi đang "Bị trả lại" — người đã trả lại không bị đẩy việc lần nữa.
        'success_declined' => 'Đã khôi phục việc về "Bị trả lại" (người nhận cũ đã trả lại việc này) — hãy bấm "Giao lại" để giao người khác.',
        'errors' => [
            'matter_closed' => 'Hồ sơ của việc này đã kết thúc nên không khôi phục được việc. Nếu còn việc phải làm, hãy mở lại hồ sơ trước (trưởng phòng hoặc quản trị viên).',
        ],
    ],

    // Lời nhắc người giao (chưa được nhận, quá hạn) về một việc "Cần giao lại" vì người nhận. :who đã escape ở chuông.
    'reminder_needs_reassignment' => ':who không còn giữ được việc này (đã nghỉ, rời đội ngũ, trả lại hồ sơ hoặc không còn mở được hồ sơ) — việc hiện "Cần giao lại": hãy giao lại.',

    // Chuông của các loại lời báo mới (App\Notifications\Staff\TaskAlert::UPDATED…). :title, :who, :reason, :changes,
    // :assignee, :assigner đã escape.
    'notice' => [
        'updated' => [
            'title' => 'Một việc anh/chị đang giữ vừa được sửa',
            'body' => ':who đã sửa việc ":title": :changes.',
        ],
        'reopened' => [
            'title' => 'Việc anh/chị đã hoàn thành được mở lại — cần làm tiếp',
            'body' => ':who đã mở lại việc ":title". Nhận xét: :reason',
        ],
        'reopened_by_holder' => [
            'title' => 'Việc anh/chị giao được mở lại',
            'body' => ':who đã mở lại việc ":title" vừa báo hoàn thành — việc trở về "Đang làm".',
        ],
        'reopened_by_holder_on_behalf' => [
            'title' => 'Một việc trên hồ sơ anh/chị phụ trách được mở lại',
            'body' => ':who đã mở lại việc ":title" do :assigner giao, vừa báo hoàn thành — việc trở về "Đang làm". :assigner không còn theo dõi việc này nên anh/chị nhận thay.',
        ],
        'restored' => [
            'title' => 'Một việc đã huỷ được khôi phục cho anh/chị',
            'body' => ':who đã khôi phục việc ":title" — việc lại chờ anh/chị bấm "Đã nhận" hoặc "Trả lại". Lý do: :reason',
        ],
        'reassigned_by_other' => [
            'title' => 'Việc anh/chị giao đã được giao lại cho người khác',
            'body' => ':who đã giao lại việc ":title" cho :assignee.',
        ],
        'cancelled_by_other' => [
            'title' => 'Việc anh/chị giao đã bị huỷ',
            'body' => ':who đã huỷ việc ":title". Lý do: :reason',
        ],
        // Vòng sửa 1 (m7): người khác khôi phục việc anh/chị giao (đối xứng với cancelled_by_other).
        'restored_by_other' => [
            'title' => 'Việc anh/chị giao đã được khôi phục',
            'body' => ':who đã khôi phục việc ":title" — việc trở lại mục "Tôi đã giao" của anh/chị. Lý do: :reason',
        ],
        'accepted_on_behalf' => [
            'title' => 'Một việc trên hồ sơ anh/chị phụ trách đã được nhận',
            'body' => ':who đã nhận việc ":title" do :assigner giao. :assigner không còn theo dõi việc này nên anh/chị nhận thay.',
        ],
        'completed_on_behalf' => [
            'title' => 'Một việc trên hồ sơ anh/chị phụ trách đã hoàn thành',
            'body' => ':who đã hoàn thành việc ":title" do :assigner giao. :assigner không còn theo dõi việc này nên anh/chị nhận thay.',
        ],
        // Các phần của :changes (App\Support\Tasks\TaskChangeSummary), nối bằng "; ".
        'changes' => [
            'due_date' => 'hạn :from → :to',
            'priority' => 'ưu tiên :from → :to',
            'title' => 'tiêu đề cũ ":from"',
            'description' => 'mô tả, hướng dẫn đã được sửa — xem ở khung Xem của việc',
        ],
    ],

    // Thư của các loại lời báo mới (App\Mail\Staff\TaskNotice). Tiêu đề thư không mang tên việc hay mã vụ.
    'notice_email' => [
        'subject' => [
            'updated' => ':name vừa sửa một việc anh/chị đang giữ',
            'reopened' => ':name đã mở lại một việc anh/chị đã hoàn thành',
            'restored' => ':name đã khôi phục một việc cho anh/chị',
            'accepted_on_behalf' => ':name đã nhận một việc trên hồ sơ anh/chị phụ trách',
            'completed_on_behalf' => ':name đã hoàn thành một việc trên hồ sơ anh/chị phụ trách',
        ],
        'headline' => [
            'updated' => ':name vừa sửa một việc anh/chị đang giữ — điều đã đổi ở dưới.',
            'reopened' => ':name đã mở lại một việc anh/chị đã báo hoàn thành: việc trở về "Đang làm", cần làm tiếp theo nhận xét dưới đây.',
            'restored' => ':name đã khôi phục một việc đã huỷ và giao lại cho anh/chị.',
            'accepted_on_behalf' => ':name đã nhận việc do :assigner giao trên hồ sơ anh/chị phụ trách. :assigner không còn theo dõi việc này nên anh/chị nhận thay.',
            'completed_on_behalf' => ':name đã hoàn thành việc do :assigner giao trên hồ sơ anh/chị phụ trách. :assigner không còn theo dõi việc này nên anh/chị nhận thay.',
        ],
        'action' => [
            'updated' => 'Mở mục Công việc',
            'reopened' => 'Mở mục Công việc — tab "Đang làm"',
            'restored' => 'Mở mục Công việc để bấm "Đã nhận" hoặc "Trả lại"',
            'accepted_on_behalf' => 'Mở tab Công việc của hồ sơ',
            'completed_on_behalf' => 'Mở tab Công việc của hồ sơ',
        ],
        'fields' => [
            'changes' => 'Điều đã đổi',
            'reason' => 'Lý do, nhận xét',
        ],
    ],

    // =============================================================================================================
    // Kiểm toán luồng Công việc 2026-10-21 — làn nhắc việc và con số (khối riêng của làn; không sửa chuỗi ở trên).
    // =============================================================================================================

    // Q3 — lời nhắc việc GỘP (App\Jobs\SendTaskReminderDigest): chuông TaskReminderAlert và thư TaskReminder với
    // `$batch`. :list đã escape ở chuông (mỗi việc: `"tiêu đề" (mã · hạn · …)`, nối bằng "; ").
    'reminder_digest' => [
        'due' => 'hạn :date',
        'no_due' => 'không đặt hạn',
        'by' => 'do :assigner giao',
        'reassign' => 'cần giao lại',
        'detail' => [
            'awaiting' => 'đã chờ :detail',
            'unaccepted' => 'chưa nhận sau :detail',
            'overdue' => 'quá hạn :detail ngày',
            'overdue_assigner' => 'quá hạn :detail ngày',
        ],
        'alert' => [
            'awaiting' => [
                'title' => ':count việc đang chờ anh/chị nhận',
                'body' => 'Các việc đã chờ anh/chị nhận quá 24 giờ làm việc: :list. Bấm "Đã nhận" hoặc "Trả lại" ở mục Công việc.',
            ],
            'unaccepted' => [
                'title' => ':count việc được giao chưa được nhận',
                'body' => 'Các việc chưa được nhận (tính theo giờ làm việc): :list. Hãy trao đổi với người nhận hoặc giao lại.',
            ],
            'due_soon' => [
                'title' => ':count việc của anh/chị sắp đến hạn',
                'body' => 'Các việc đến hạn từ hôm nay tới hết ngày làm việc kế tiếp: :list.',
            ],
            'overdue' => [
                'title' => ':count việc của anh/chị đã quá hạn',
                'body' => 'Các việc đã quá hạn: :list. Hãy hoàn thành, hoặc báo người giao để dời hạn.',
            ],
            'overdue_assigner' => [
                'title' => ':count việc anh/chị giao hoặc theo dõi đã quá hạn',
                'body' => 'Các việc đã quá hạn: :list.',
            ],
            'due_soon_assigner' => [
                'title' => ':count việc sắp đến hạn mà không còn người làm',
                'body' => 'Người giữ các việc dưới đây không còn làm được việc — hãy giao lại: :list.',
            ],
        ],
        'email' => [
            'item' => 'Việc',
            'subject' => [
                'awaiting' => ':count việc đang chờ anh/chị nhận',
                'unaccepted' => ':count việc được giao chưa được nhận',
                'due_soon' => ':count việc của anh/chị sắp đến hạn',
                'overdue' => ':count việc của anh/chị đã quá hạn',
                'overdue_assigner' => ':count việc anh/chị giao hoặc theo dõi đã quá hạn',
                'due_soon_assigner' => ':count việc sắp đến hạn mà không còn người làm',
            ],
            'headline' => [
                'awaiting' => ':count việc dưới đây đã chờ anh/chị nhận quá 24 giờ làm việc.',
                'unaccepted' => ':count việc dưới đây chưa được nhận (tính theo giờ làm việc) — hãy trao đổi với người nhận hoặc giao lại.',
                'due_soon' => ':count việc dưới đây đến hạn từ hôm nay tới hết ngày làm việc kế tiếp.',
                'overdue' => ':count việc dưới đây đã quá hạn — hãy hoàn thành, hoặc báo người giao để dời hạn.',
                'overdue_assigner' => ':count việc anh/chị giao (hoặc nhận thay người giao) dưới đây đã quá hạn.',
                'due_soon_assigner' => ':count việc dưới đây sắp đến hạn mà người giữ không còn làm được việc — hãy giao lại.',
            ],
            'action' => [
                'awaiting' => 'Mở mục Công việc để bấm "Đã nhận" hoặc "Trả lại"',
                'unaccepted' => 'Mở mục Công việc',
                'due_soon' => 'Mở mục Công việc — các việc anh/chị đang giữ',
                'overdue' => 'Mở mục Công việc — các việc quá hạn của anh/chị',
                'overdue_assigner' => 'Mở mục Công việc',
                'due_soon_assigner' => 'Mở mục Công việc',
            ],
        ],
    ],

    // N1, A7 — lời báo "cho người giao" tới người nhận THAY không phải luật sư phụ trách (App\Support\Tasks\StandIn):
    // người quản lý hồ sơ (`<loại>`) hay trưởng phòng / quản trị viên với việc chung (`<loại>_general`). :title, :who,
    // :reason, :assigner, :detail, :code đã escape ở chuông.
    'managed' => [
        'alert' => [
            'declined' => [
                'title' => 'Một việc trên hồ sơ :code của đội ngũ anh/chị quản lý bị trả lại',
                'body' => ':who trả lại việc ":title" do :assigner giao. Lý do: :reason. :assigner không còn theo dõi việc này '
                    .'và luật sư phụ trách không nhận thay được, nên anh/chị — người quản lý hồ sơ — nhận lời báo: hãy giao '
                    .'lại hoặc huỷ ở tab "Công việc" của hồ sơ.',
            ],
            'declined_general' => [
                'title' => 'Một việc chung bị trả lại mà người giao không còn theo dõi',
                'body' => ':who trả lại việc chung ":title" do :assigner giao. Lý do: :reason. :assigner không còn theo dõi '
                    .'việc này nên anh/chị nhận lời báo: hãy giao lại hoặc huỷ ở trang Công việc.',
            ],
        ],
        'email' => [
            'subject' => [
                'declined' => ':name đã trả lại một việc trên hồ sơ của đội ngũ anh/chị quản lý',
                'declined_general' => ':name đã trả lại một việc chung mà người giao không còn theo dõi',
            ],
            'headline' => [
                'declined' => ':name đã trả lại việc do :assigner giao trên hồ sơ :code của đội ngũ anh/chị quản lý. :assigner '
                    .'không còn theo dõi việc này và luật sư phụ trách không nhận thay được, nên anh/chị nhận lời báo.',
                'declined_general' => ':name đã trả lại việc chung do :assigner giao. :assigner không còn theo dõi việc này nên '
                    .'anh/chị nhận lời báo.',
            ],
            'action' => [
                'declined' => 'Mở tab Công việc của hồ sơ để giao lại hoặc huỷ việc',
                'declined_general' => 'Mở trang Công việc để giao lại hoặc huỷ việc',
            ],
        ],
        'reminder' => [
            'alert' => [
                'unaccepted' => [
                    'title' => 'Một việc trên hồ sơ :code của đội ngũ anh/chị quản lý chưa được nhận',
                    'body' => ':who chưa nhận việc ":title" do :assigner giao, sau :detail (tính theo giờ làm việc). :assigner '
                        .'không còn theo dõi việc này và luật sư phụ trách không nhận thay được, nên anh/chị nhận lời báo: hãy '
                        .'trao đổi với :who hoặc giao lại.',
                ],
                'unaccepted_general' => [
                    'title' => 'Một việc chung chưa được nhận mà người giao không còn theo dõi',
                    'body' => ':who chưa nhận việc chung ":title" do :assigner giao, sau :detail (tính theo giờ làm việc). '
                        .':assigner không còn theo dõi việc này nên anh/chị nhận lời báo: hãy trao đổi với :who hoặc giao lại.',
                ],
                'overdue_assigner' => [
                    'title' => 'Một việc trên hồ sơ :code của đội ngũ anh/chị quản lý đã quá hạn',
                    'body' => 'Việc ":title" do :assigner giao cho :who đã quá hạn :detail ngày. :assigner không còn theo dõi '
                        .'việc này và luật sư phụ trách không nhận thay được, nên anh/chị nhận lời báo.',
                ],
                'overdue_assigner_general' => [
                    'title' => 'Một việc chung đã quá hạn mà người giao không còn theo dõi',
                    'body' => 'Việc chung ":title" do :assigner giao cho :who đã quá hạn :detail ngày. :assigner không còn theo '
                        .'dõi việc này nên anh/chị nhận lời báo.',
                ],
                'due_soon_assigner' => [
                    'title' => 'Một việc trên hồ sơ :code của đội ngũ anh/chị quản lý sắp đến hạn mà không còn người làm',
                    'body' => 'Việc ":title" do :assigner giao cho :who đến hạn ngày :detail. :assigner không còn theo dõi việc '
                        .'này và luật sư phụ trách không nhận thay được, nên anh/chị nhận lời báo.',
                ],
                'due_soon_assigner_general' => [
                    'title' => 'Một việc chung sắp đến hạn mà không còn người làm',
                    'body' => 'Việc chung ":title" do :assigner giao cho :who đến hạn ngày :detail. :assigner không còn theo '
                        .'dõi việc này nên anh/chị nhận lời báo.',
                ],
            ],
            'email' => [
                'subject' => [
                    'unaccepted' => 'Một việc trên hồ sơ của đội ngũ anh/chị quản lý chưa được nhận',
                    'unaccepted_general' => 'Một việc chung chưa được nhận mà người giao không còn theo dõi',
                    'overdue_assigner' => 'Một việc trên hồ sơ của đội ngũ anh/chị quản lý đã quá hạn',
                    'overdue_assigner_general' => 'Một việc chung đã quá hạn mà người giao không còn theo dõi',
                    'due_soon_assigner' => 'Một việc trên hồ sơ của đội ngũ anh/chị quản lý sắp đến hạn mà không còn người làm',
                    'due_soon_assigner_general' => 'Một việc chung sắp đến hạn mà không còn người làm',
                ],
                'headline' => [
                    'unaccepted' => ':name chưa nhận việc do :assigner giao trên hồ sơ :code của đội ngũ anh/chị quản lý, sau '
                        .':detail (tính theo giờ làm việc). :assigner không còn theo dõi việc này và luật sư phụ trách không '
                        .'nhận thay được, nên anh/chị nhận lời báo.',
                    'unaccepted_general' => ':name chưa nhận việc chung do :assigner giao, sau :detail (tính theo giờ làm '
                        .'việc). :assigner không còn theo dõi việc này nên anh/chị nhận lời báo.',
                    'overdue_assigner' => 'Việc do :assigner giao cho :name trên hồ sơ :code của đội ngũ anh/chị quản lý đã '
                        .'quá hạn :detail ngày. :assigner không còn theo dõi việc này và luật sư phụ trách không nhận thay '
                        .'được, nên anh/chị nhận lời báo.',
                    'overdue_assigner_general' => 'Việc chung do :assigner giao cho :name đã quá hạn :detail ngày. :assigner '
                        .'không còn theo dõi việc này nên anh/chị nhận lời báo.',
                    'due_soon_assigner' => 'Việc do :assigner giao cho :name trên hồ sơ :code của đội ngũ anh/chị quản lý đến '
                        .'hạn ngày :detail. :assigner không còn theo dõi việc này và luật sư phụ trách không nhận thay được, '
                        .'nên anh/chị nhận lời báo.',
                    'due_soon_assigner_general' => 'Việc chung do :assigner giao cho :name đến hạn ngày :detail. :assigner '
                        .'không còn theo dõi việc này nên anh/chị nhận lời báo.',
                ],
                'action' => [
                    'unaccepted' => 'Mở tab Công việc của hồ sơ',
                    'unaccepted_general' => 'Mở trang Công việc',
                    'overdue_assigner' => 'Mở tab Công việc của hồ sơ',
                    'overdue_assigner_general' => 'Mở trang Công việc',
                    'due_soon_assigner' => 'Mở tab Công việc của hồ sơ',
                    'due_soon_assigner_general' => 'Mở trang Công việc',
                ],
            ],
        ],
    ],
];
