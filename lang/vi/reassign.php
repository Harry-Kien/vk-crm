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
];
