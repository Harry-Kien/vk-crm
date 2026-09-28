<?php

use App\Jobs\SendReassignmentDigest;

/**
 * Bàn giao vụ việc (`App\Actions\Matter\ReassignMatter`, SPEC §6.11; M6.5 Task 4, R7) — header
 * action "Bàn giao" trên `ViewMatter`.
 */
return [
    'action' => [
        'label' => 'Bàn giao',
        'modal_heading' => 'Bàn giao vụ việc cho luật sư phụ trách mới',
        'submit' => 'Bàn giao',
        'success' => 'Đã bàn giao vụ việc.',
        // Spec gap (fix round 1) — SPEC §6.11 bước 4: chỉ một GỢI Ý trên giao diện, không bao giờ
        // tự soạn hay tự gửi. Chỉ hiện khi vụ việc đã công bố portal (ViewMatter::reassignAction()).
        'suggest_introduction_title' => 'Nên giới thiệu luật sư mới cho khách',
        'suggest_introduction_body' => 'Vụ việc này đã công bố trên cổng khách hàng. Anh/chị có thể soạn một dòng cập nhật ở tab Tiến độ giới thiệu luật sư phụ trách mới — hệ thống không tự gửi, việc này cần người quyết định.',
    ],
    'fields' => [
        'new_lead_id' => 'Luật sư phụ trách mới',
        'keep_old_lead_as_associate' => 'Giữ luật sư cũ trong đội ngũ với vai luật sư cộng sự',
        'keep_old_lead_as_associate_hint' => 'Nếu tắt, luật sư cũ sẽ bị gỡ hẳn khỏi đội ngũ vụ việc này.',
        'reason' => 'Lý do bàn giao',
    ],
    'stage_log' => [
        'internal_note' => 'Đã bàn giao vụ việc từ :from sang :to. Lý do: :reason',
    ],
    'validation' => [
        'same_lead' => 'Không thể bàn giao: người được chọn đã đang là luật sư phụ trách của chính vụ việc này.',
        'new_lead_inactive' => 'Không thể bàn giao cho người này: tài khoản đã bị vô hiệu hoá hoặc đã nghỉ việc.',
        // I3 (fix round 1): chỉ luật sư hoặc trưởng phòng đứng tên phụ trách được — cùng tập vai
        // AddTeamMember::eligibleForRole() chấp nhận cho vai "Luật sư cộng sự".
        'new_lead_not_eligible' => 'Không thể bàn giao cho người này: chỉ luật sư hoặc trưởng phòng mới đứng tên phụ trách một vụ việc được.',
        'reason_required' => 'Phải nhập lý do bàn giao.',
        // Vụ `restricted`: Matter::isListableBy() nhánh đó chỉ cho admin hoặc chính lead_lawyer_id
        // xem được — một lead cũ ở lại với vai associate sẽ không bao giờ mở lại được vụ việc này.
        'old_lead_would_not_see_matter' => 'Không thể giữ luật sư cũ trong đội ngũ: vụ việc đang ở chế độ hạn chế, người này sẽ không còn xem được vụ việc với vai luật sư cộng sự. Hãy tắt công tắc "Giữ luật sư cũ trong đội ngũ" rồi bàn giao lại.',
        // Minor (fix round 1): lead_lawyer_id trỏ vào một hàng không còn tồn tại — một lý do KHÁC
        // hẳn "trùng lead", không được gộp chung một câu.
        'no_current_lead' => 'Không thể bàn giao: vụ việc này hiện không có luật sư phụ trách hợp lệ. Liên hệ quản trị viên để kiểm tra lại hồ sơ.',
        // Fix round 1, finding 1 (M7 Task 2): $expectedLeadId khác lead hiện tại dưới khoá, hoặc
        // vụ việc đã đóng, giữa lúc màn hình bàn giao hàng loạt đang mở.
        'stale_or_closed' => 'Vụ việc đã được bàn giao cho người khác hoặc đã đóng.',
    ],

    /*
     * M7 Task 1 — mẫu thư `staff.matter_reassigned` (SPEC §6.11 bước 3, R10): thư tổng hợp mốc
     * hạn cho lead mới, dựng để dùng lại được cho cả lô (App\Jobs\SendReassignmentDigest,
     * App\Mail\Staff\MatterReassigned). Tiêu đề KHÔNG nêu mã hay tiêu đề vụ nào (phán quyết
     * controller Task 1) — chỉ số lượng vụ việc.
     */
    'email' => [
        'subject' => 'Anh/chị vừa được bàn giao :count vụ việc',
        'greeting' => 'Kính gửi :name,',
        'intro' => 'Anh/chị vừa được bàn giao :count vụ việc. Dưới đây là những gì đã chuyển sang cho anh/chị ở từng vụ.',
        'matter' => 'Hồ sơ: :code — :title',
        'client' => 'Khách hàng: :name',
        'reason' => 'Lý do bàn giao: :reason',
        'deadlines_heading' => 'Mốc thời hạn đã chuyển:',
        'deadline_line' => ':name — hạn :date (:severity)',
        'no_deadlines' => 'Không có mốc hạn nào được chuyển.',
        'client_requests_moved' => 'Đã chuyển :count yêu cầu khách hàng chưa đóng.',
        'action' => 'Anh/chị mở từng vụ việc trên hệ thống để xem đầy đủ chi tiết.',
        'salutation' => ':office',
    ],

    /**
     * Thông báo trong ứng dụng khi {@see SendReassignmentDigest} hỏng HẲN (hết mọi
     * lượt thử) — cùng hình dạng `lang/vi/deadlines.php:reminder_failed_notification`.
     */
    'digest_failed_notification' => [
        'title' => 'Không gửi được thư tổng hợp bàn giao vụ việc',
        'body' => 'Đã thử lại nhiều lần nhưng không gửi được thư tổng hợp mốc hạn bàn giao cho anh/chị. Cần kiểm tra thủ công.',
    ],

    /*
     * M7 Task 2 — trang `App\Filament\Admin\Pages\BulkReassign` (admin/manager) và Action
     * `App\Actions\Matter\ReassignMatters`.
     */
    'bulk' => [
        'page_title' => 'Bàn giao hàng loạt',
        'navigation_label' => 'Bàn giao hàng loạt',
        'action_label' => 'Mở màn hình Bàn giao hàng loạt',
        'fields' => [
            'lead_lawyer_id' => 'Luật sư đang phụ trách',
            'matter_ids' => 'Chọn vụ việc cần bàn giao',
            'no_matters' => 'Người này hiện không có vụ việc đang mở nào mà anh/chị có quyền bàn giao.',
            'new_lead_id' => 'Luật sư phụ trách mới',
            'reason' => 'Lý do bàn giao',
            'keep_old_lead_as_associate' => 'Giữ luật sư cũ trong đội ngũ với vai luật sư cộng sự',
            'keep_old_lead_as_associate_hint' => 'Áp dụng cho vụ việc thường. Vụ việc hạn chế luôn gỡ luật sư cũ khỏi đội ngũ, bất kể công tắc này.',
        ],
        'submit' => 'Bàn giao các vụ đã chọn',
        'validation' => [
            'no_matters_selected' => 'Phải chọn ít nhất một vụ việc để bàn giao.',
        ],
        // Fix round 1, finding 4: tiêu đề/màu thông báo tổng kết PHẢI khớp kết quả thật —
        // reassignSelected() chọn đúng một trong ba khoá này theo $successCount/$failureCount,
        // không bao giờ dùng cứng 'reassign.action.success' (câu đó đúng cho nút MỘT vụ, luôn
        // thành công khi chạy tới đó — sai khi dùng cho cả lô có thể thất bại một phần hoặc toàn
        // bộ, vì nó luôn hứa "Đã bàn giao vụ việc." dù không vụ nào thật sự chuyển).
        'notification_titles' => [
            'success' => 'Đã bàn giao thành công cả lô.',
            'partial' => 'Bàn giao một phần: có vụ thất bại.',
            'failure' => 'Bàn giao thất bại: không vụ nào được chuyển.',
        ],
        // Thông báo tổng kết SAU vòng lặp (khác lời văn từng dòng ở 'results' bên dưới) —
        // ':failure' có thể bằng 0, câu vẫn đọc được bình thường ("0 vụ thất bại").
        'notification_body' => 'Thành công :success vụ, thất bại :failure vụ. Xem chi tiết từng vụ bên dưới.',
        'results_heading' => 'Kết quả bàn giao',
        // Kết quả từng vụ (App\Actions\Matter\BulkReassignMatterResult) — báo riêng từng vụ, kể
        // cả vụ thất bại (phán quyết controller Task 2), không một thông điệp chung cho cả lô.
        'results' => [
            'not_found' => 'Vụ việc không còn tồn tại.',
            'unauthorized' => 'Bạn không có quyền bàn giao vụ việc này.',
            'success' => 'Đã bàn giao thành công.',
            // Fix round 1, finding 3 — mọi lỗi không thuộc bốn họ đã liệt kê ở trên (ví dụ CSDL
            // bận đúng lúc, kết nối rớt giữa lô).
            'unexpected_error' => 'Có lỗi không xác định khi bàn giao vụ việc này. Vui lòng thử lại; nếu còn lỗi, báo quản trị viên.',
        ],
        // SPEC §6.11 bước 4 — chỉ GỢI Ý, không tự soạn/gửi (cùng lời văn với
        // `reassign.action.suggest_introduction_body`, nói riêng cho MỘT dòng kết quả của lô).
        'suggest_introduction' => 'Vụ việc này đã công bố trên cổng khách hàng — nên giới thiệu luật sư mới cho khách ở tab Tiến độ.',
    ],
];
