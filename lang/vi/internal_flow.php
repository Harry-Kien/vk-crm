<?php

/**
 * Làn fa2 (kiểm tra luồng nội bộ 2026-10-16) — lời báo của đội ngũ, bàn giao, nghỉ việc, mốc hạn và trình duyệt
 * văn bản nội bộ. Mọi khoá của tệp này thuộc khối làn fa2.
 */
return [
    // làn fa2 — phán quyết 1: gỡ thành viên khỏi đội ngũ (App\Actions\Matter\RemoveTeamMember, TeamRelationManager),
    // chuông App\Notifications\Staff\TeamDepartureAlert (App\Jobs\SendTeamDepartureNotices). :matter, :member, :who,
    // :to đã escape ở chuông.
    'team_removal' => [
        'tasks_heading' => 'Việc nội bộ người này đang giữ trên hồ sơ (:count việc) — chọn người nhận thay, hoặc để "Cần giao lại":',
        'task_line' => '• :title — :status:due',
        'task_due' => ', hạn :date',
        'pending_seat' => 'Người này chưa bấm "Đã nhận hồ sơ" — gỡ khỏi đội ngũ là rút lại lời giao ghế này.',
        'recipient' => 'Chuyển các việc này cho',
        'recipient_hint' => 'Mỗi việc chuyển sang người được chọn ở trạng thái "Chờ nhận" (như nút "Giao lại"): người đó và người giao việc được báo. Chọn "Để Cần giao lại" thì các việc ở lại, hiện ở bộ lọc "Cần giao lại" của trang Công việc để giao sau.',
        'leave' => 'Để "Cần giao lại" (giao sau)',
        'choose_recipient' => 'Người này còn giữ :count việc nội bộ trên hồ sơ: hãy chọn người nhận thay, hoặc chọn "Để Cần giao lại".',
        'recipient_is_member' => 'Không thể chuyển việc cho chính người đang bị gỡ khỏi đội ngũ.',
        // làn fa2, vòng sửa 1 (minor 2): người bấm chọn chính mình — "Giao lại" không cho ai tự giao việc cho mình.
        'self_refused' => 'Việc nội bộ không giao được cho chính người đang thao tác (luật "không tự giao việc cho mình" của trang Công việc). Hãy chọn một đồng nghiệp khác, hoặc "Để Cần giao lại".',
        'needs_reassignment_marker' => 'Cần giao lại',
        'matter_named' => 'hồ sơ :code',
        'matter_hidden' => 'một hồ sơ',
        'open' => 'Mở',
        'member_left' => [
            'title' => 'Anh/chị đã rời đội ngũ một hồ sơ',
            'body_plain' => ':who đã gỡ anh/chị khỏi đội ngũ :matter.',
            'body_moved' => ':who đã gỡ anh/chị khỏi đội ngũ :matter — :count việc anh/chị đang giữ trên đó đã chuyển cho :to. Anh/chị không cần làm tiếp các việc này.',
            'body_left' => ':who đã gỡ anh/chị khỏi đội ngũ :matter — :count việc anh/chị đang giữ trên đó chờ được giao lại cho người khác. Anh/chị không cần làm tiếp các việc này.',
        ],
        'tasks_handled' => [
            'title' => 'Người giữ việc đã rời đội ngũ hồ sơ',
            'body_moved' => ':member đã rời đội ngũ :matter (do :who gỡ): :count việc họ đang giữ đã chuyển cho :to ở trạng thái "Chờ nhận".',
            'body_left' => ':member đã rời đội ngũ :matter (do :who gỡ): :count việc họ đang giữ hiện ở "Cần giao lại" — hãy giao lại ở tab "Công việc" của hồ sơ.',
            'body_plain' => ':member đã rời đội ngũ :matter (do :who gỡ).',
        ],
    ],

    // làn fa2 — phán quyết 2: việc nội bộ còn dở trong luật nghỉ việc / tạm khoá / xoá / đổi chức danh
    // (App\Support\OpenTaskWork, App\Actions\User\Concerns\GuardsStaffOffboarding, EditUser "Khoá truy cập ngay").
    'offboarding' => [
        'held_tasks' => ':count việc nội bộ đang giữ (chờ nhận hay đang làm) — chuyển ở phần "Việc nội bộ và ghế" của màn hình "Bàn giao hàng loạt", hoặc "Giao lại" trên trang Công việc',
        'assigned_tasks' => ':count việc nội bộ đã giao cho người khác còn mở — chuyển người giao ở phần "Việc nội bộ và ghế" của màn hình "Bàn giao hàng loạt" (hoặc bàn giao vụ nếu người này phụ trách vụ đó)',
        'pending_seats' => ':count hồ sơ được giao đang chờ người này bấm "Đã nhận hồ sơ" — chuyển ghế ở màn hình "Bàn giao hàng loạt", hoặc gỡ khỏi tab "Đội ngũ" của hồ sơ',
        'suspend_tasks' => 'Việc nội bộ cũng giữ nguyên tới khi chuyển: :held việc đang giữ, :assigned việc đã giao còn mở, :seats hồ sơ chờ nhận — chuyển ở phần "Việc nội bộ và ghế" của màn hình "Bàn giao hàng loạt".',
    ],

    // làn fa2 — phán quyết 2: phần "Việc nội bộ và ghế" của màn hình Bàn giao hàng loạt
    // (App\Filament\Admin\Pages\BulkReassign, App\Actions\Matter\TransferStaffWork).
    'staff_work' => [
        'heading' => 'Việc nội bộ và ghế của một nhân sự',
        'description' => 'Cho người sắp nghỉ hay bị khoá mà không (chỉ) là luật sư phụ trách — trợ lý, chuyên viên, luật sư cộng sự. Trên từng hồ sơ đang mở: người nhận vào đội ngũ thay ghế của người này (chờ người nhận bấm "Đã nhận hồ sơ"), việc nội bộ người này đang giữ chuyển sang người nhận ở trạng thái "Chờ nhận", việc người này đã giao đổi người giao, mốc hạn và yêu cầu của khách chuyển theo, rồi người này rời đội ngũ. Việc chung (không thuộc hồ sơ) người này đang giữ cũng chuyển. Hồ sơ người này phụ trách thì dùng phần "Bàn giao" ở trên.',
        'fields' => [
            'user_id' => 'Nhân sự cần chuyển việc',
            'recipient_id' => 'Người nhận việc',
        ],
        'summary' => 'Hiện có: :held việc đang giữ, :assigned việc đã giao còn mở, :seats hồ sơ chờ nhận, :matters hồ sơ có ghế hoặc việc của người này.',
        'submit' => 'Chuyển việc nội bộ và ghế',
        'results' => [
            'done' => 'Đã chuyển: :tasks việc đang giữ, :followed việc đã giao (đổi người giao), :deadlines mốc hạn, :requests yêu cầu của khách; :seats ghế mới trong đội ngũ.',
            'is_lead' => 'Người này đang phụ trách hồ sơ này — dùng phần "Bàn giao" ở trên (bàn giao vụ chuyển cả việc của luật sư phụ trách cũ).',
            'general' => 'Việc chung (không thuộc hồ sơ): đã chuyển :moved / :count việc đang giữ; :followed việc đã giao đổi người giao.',
            // làn fa2, vòng sửa 1 (minor 3): "Giao lại" từ chối một số việc (ví dụ người nhận là chính người bấm).
            'tasks_failed' => ':count việc nội bộ không chuyển được: :reason Các việc này ở "Cần giao lại" — hãy giao lại ở tab "Công việc" của hồ sơ.',
            'general_failed' => ':count việc chung không chuyển được: :reason Hãy giao lại trên trang Công việc.',
            // làn fa2, vòng sửa 1 (I1): hồ sơ người bấm không quản lý được đội ngũ — không mã, không tiêu đề, không id.
            'unmanaged' => 'Còn :count hồ sơ có ghế hoặc việc của người này mà anh/chị không quản lý được đội ngũ — cần quản trị viên chuyển.',
        ],
        'notification_title' => 'Đã chuyển việc nội bộ và ghế',
        'validation' => [
            'recipient_invalid' => 'Hãy chọn một người nhận khác: không phải chính người cần chuyển việc, và đang hoạt động.',
            'reason_required' => 'Phải nhập lý do chuyển việc (tối đa :max ký tự) — lý do được ghi vào nhật ký.',
        ],
        // làn fa2, vòng sửa 1 (minor 5): hộp xác nhận của nút "Chuyển việc nội bộ và ghế".
        'confirm' => [
            'heading' => 'Chuyển việc nội bộ và ghế?',
            'description' => 'Chuyển việc của :from sang :to. :summary Người này sẽ rời đội ngũ các hồ sơ đó; thao tác không hoàn tác được từ màn hình này.',
            'pick_first' => 'Hãy chọn nhân sự cần chuyển việc và người nhận việc trước.',
            'reason' => 'Lý do chuyển việc',
            'reason_hint' => 'Ghi vào nhật ký của nhân sự cần chuyển việc.',
            'submit' => 'Chuyển việc',
        ],
        // làn fa2, vòng sửa 1 (I2): MỘT lời báo cho cả lô — App\Notifications\Staff\StaffWorkTransferAlert và
        // App\Mail\Staff\StaffWorkTransferred (App\Jobs\SendStaffWorkTransferNotices). :who, :from, :to, :codes đã escape
        // ở chuông.
        'digest' => [
            'matters' => ':count hồ sơ (:codes)',
            'matters_with_hidden' => ':count hồ sơ (:codes và :hidden hồ sơ khác)',
            'matters_unnamed' => ':count hồ sơ',
            'tasks' => ':count việc nội bộ',
            'followed' => ':count việc đã giao (đổi người theo dõi)',
            'deadlines' => ':count mốc hạn',
            'requests' => ':count yêu cầu của khách',
            'seats' => ':count ghế mới trong đội ngũ',
            'open' => 'Mở',
            'received' => [
                'title' => 'Anh/chị nhận việc của một đồng nghiệp',
                'body' => ':who đã chuyển cho anh/chị việc của :from trên :matters: :items. Việc nội bộ chờ anh/chị bấm "Đã nhận" trên trang Công việc; ghế mới chờ anh/chị bấm "Đã nhận hồ sơ".',
                'body_general' => ':who đã chuyển cho anh/chị việc chung (không thuộc hồ sơ) của :from: :items. Việc nội bộ chờ anh/chị bấm "Đã nhận" trên trang Công việc.',
            ],
            'handed_over' => [
                'title' => 'Việc của anh/chị đã được chuyển cho người khác',
                'body' => ':who đã chuyển việc của anh/chị trên :matters cho :to: :items. Anh/chị đã rời đội ngũ các hồ sơ đó và không cần làm tiếp các việc này.',
                'body_general' => ':who đã chuyển việc chung (không thuộc hồ sơ) của anh/chị cho :to: :items. Anh/chị không cần làm tiếp các việc này.',
                'body_seats_only' => ':who đã gỡ anh/chị khỏi đội ngũ :matters khi chuyển việc của anh/chị cho :to.',
            ],
            'email' => [
                'subject' => 'Anh/chị nhận việc của một đồng nghiệp',
                'headline' => ':who đã chuyển cho anh/chị việc nội bộ, ghế trong đội ngũ, mốc hạn và yêu cầu của khách của :from. Mỗi việc, mỗi mốc không có thư riêng — thư này thay cho chúng.',
                'fields' => [
                    'from' => 'Việc của',
                    'matters' => 'Hồ sơ',
                    'tasks' => 'Việc nội bộ (Chờ nhận)',
                    'followed' => 'Việc đã giao (anh/chị theo dõi)',
                    'deadlines' => 'Mốc hạn',
                    'requests' => 'Yêu cầu của khách',
                    'seats' => 'Ghế mới trong đội ngũ',
                ],
                'action' => 'Mở trang Công việc',
            ],
        ],
    ],

    // làn fa2 — phán quyết 4: App\Mail\Staff\MatterAssignmentNotice::KIND_ASSIGNED (OpenMatter đặt người khác làm
    // luật sư phụ trách). Tiêu đề thư không mang mã hồ sơ (hiện trên màn hình khoá).
    'seat_assigned' => [
        'subject' => 'Anh/chị được giao phụ trách một hồ sơ mới',
        'headline' => ':who đã mở một hồ sơ mới và giao anh/chị làm luật sư phụ trách. Hồ sơ đang chờ anh/chị xác nhận.',
        'assigned_by' => 'Người giao',
        'action' => 'Mở "Việc của tôi" để bấm "Đã nhận hồ sơ" hoặc "Trả lại"',
        'action_team_tab' => 'Mở tab "Đội ngũ" của hồ sơ để bấm "Đã nhận hồ sơ" hoặc "Trả lại"',
    ],

    // làn fa2 — phán quyết 5: App\Notifications\Staff\LeadChangeAlert (App\Jobs\SendLeadChangeNotices). :code, :codes,
    // :from, :to, :who đã escape. "hidden": vụ hạn chế người nhận không còn mở được — không mã. "left": người nhận
    // đã rời đội ngũ (vụ thường — mã vẫn hiện, họ vừa phụ trách nó).
    'lead_change' => [
        'open' => 'Mở',
        'and_others' => ' và :count hồ sơ khác',
        'handed_away' => [
            'title' => 'Hồ sơ anh/chị phụ trách đã được giao cho người khác',
            'body_one' => 'Hồ sơ :code đã được :who giao cho :to làm luật sư phụ trách. Mốc hạn và yêu cầu của khách anh/chị đang giữ trên hồ sơ này đã chuyển cho :to.',
            'body_one_left' => 'Hồ sơ :code đã được :who giao cho :to làm luật sư phụ trách, và anh/chị đã rời đội ngũ hồ sơ này. Mốc hạn, yêu cầu của khách và việc nội bộ anh/chị đang giữ trên đó đã chuyển cho :to; anh/chị không cần làm tiếp.',
            'body_hidden' => 'Một hồ sơ anh/chị phụ trách đã được :who giao cho :to làm luật sư phụ trách. Anh/chị không còn mở được hồ sơ này; mốc hạn, yêu cầu của khách và việc nội bộ anh/chị đang giữ trên đó đã chuyển cho :to.',
            'body_many' => ':count hồ sơ anh/chị phụ trách đã được :who giao cho :to làm luật sư phụ trách: :codes. Mốc hạn và yêu cầu của khách anh/chị đang giữ trên các hồ sơ đó đã chuyển cho :to.',
            'body_many_left' => ':count hồ sơ anh/chị phụ trách đã được :who giao cho :to làm luật sư phụ trách: :codes. Mốc hạn và yêu cầu của khách anh/chị đang giữ trên các hồ sơ đó đã chuyển cho :to; hồ sơ nào anh/chị đã rời đội ngũ thì việc nội bộ anh/chị đang giữ trên đó cũng đã chuyển.',
            'body_many_hidden' => ':count hồ sơ anh/chị phụ trách đã được :who giao cho :to làm luật sư phụ trách. Anh/chị không còn mở được các hồ sơ này; mốc hạn, yêu cầu của khách và việc nội bộ anh/chị đang giữ trên đó đã chuyển cho :to.',
        ],
        'lead_changed' => [
            'title' => 'Hồ sơ đổi luật sư phụ trách',
            'body_one' => 'Hồ sơ :code đổi luật sư phụ trách: :from → :to (do :who bàn giao). Từ nay anh/chị báo cáo công việc của hồ sơ này cho :to.',
            'body_many' => ':count hồ sơ anh/chị tham gia đổi luật sư phụ trách từ :from sang :to (do :who bàn giao): :codes. Từ nay anh/chị báo cáo công việc của các hồ sơ này cho :to.',
        ],
    ],

    // làn fa2 — phán quyết 6: App\Notifications\Staff\DeadlineAssignedAlert và App\Mail\Staff\DeadlineAssigned
    // (App\Jobs\SendDeadlineAssignmentNotice). :name, :code, :due, :who đã escape ở chuông.
    'deadline_assigned' => [
        'title' => 'Anh/chị được giao một mốc thời hạn',
        'body' => ':who giao anh/chị phụ trách mốc ":name" của hồ sơ :code — :due.',
        'open' => 'Mở tab Mốc thời hạn',
        'due_in' => 'hạn :date, còn :days ngày',
        'due_today' => 'hạn hôm nay (:date)',
        'overdue' => 'hạn :date, đã quá hạn :days ngày',
        'email' => [
            'subject' => 'Anh/chị được giao một mốc thời hạn',
            'headline' => ':who giao anh/chị phụ trách một mốc thời hạn. Các lời nhắc theo lịch đã gửi trước đây sẽ không gửi lại, nên hãy ghi nhận hạn ngay từ thư này.',
            // làn fa2, vòng sửa 1 (minor 6): mốc vừa THÊM — chưa có lời nhắc nào để nói tới.
            'headline_new' => ':who vừa thêm một mốc thời hạn và giao anh/chị phụ trách. Hãy ghi nhận hạn ngay từ thư này.',
            'fields' => [
                'name' => 'Mốc',
                'matter' => 'Hồ sơ',
                'due' => 'Hạn',
                'severity' => 'Mức độ',
            ],
            'action' => 'Mở tab "Mốc thời hạn" của hồ sơ',
        ],
    ],

    // làn fa2 — phán quyết 7: vòng trình duyệt văn bản nội bộ nhóm B. App\Notifications\Staff\DocumentReviewAlert,
    // App\Mail\Staff\DocumentReviewRequest (App\Jobs\SendDocumentReviewNotice), ô lý do của "Trả về bản nháp".
    'document_review' => [
        'open' => 'Mở tab Tài liệu',
        'submitted' => [
            'title' => 'Một văn bản chờ anh/chị duyệt',
            'body' => ':who đã trình văn bản ":title" của hồ sơ :code. Hãy xem rồi bấm "Đánh dấu đã ký, đã nộp", hoặc "Trả về bản nháp" kèm lý do.',
        ],
        'approved' => [
            'title' => 'Văn bản anh/chị trình đã được duyệt',
            'body' => ':who đã đánh dấu văn bản ":title" của hồ sơ :code là đã ký, đã nộp.',
        ],
        'returned' => [
            'title' => 'Văn bản anh/chị trình bị trả về bản nháp',
            'body' => ':who đã trả văn bản ":title" của hồ sơ :code về bản nháp. Lý do: :reason',
        ],
        'fields' => [
            'reason' => 'Lý do trả về',
            'reason_hint' => 'Người đã trình văn bản nhận được lý do này trong thông báo, để biết cần sửa gì trước khi trình lại.',
        ],
        'validation' => [
            'reason_required' => 'Phải nhập lý do trả về (tối đa :max ký tự), để người trình biết cần sửa gì.',
        ],
        'email' => [
            'subject' => 'Một văn bản chờ anh/chị duyệt',
            'headline' => ':who đã trình một văn bản để anh/chị duyệt.',
            'fields' => [
                'title' => 'Văn bản',
                'matter' => 'Hồ sơ',
                'submitter' => 'Người trình',
            ],
            'action' => 'Mở tab "Tài liệu" của hồ sơ để duyệt',
        ],
    ],
];
