<?php

return [
    'user_position' => [
        'lawyer' => 'Luật sư',
        'assistant' => 'Trợ lý',
        'accountant' => 'Kế toán',
        'manager' => 'Trưởng phòng',
        'admin' => 'Quản trị',
    ],
    'client_type' => [
        'individual' => 'Cá nhân',
        'organization' => 'Tổ chức',
    ],
    'matter_role' => [
        'lead' => 'Luật sư phụ trách',
        'associate' => 'Luật sư cộng sự',
        'assistant' => 'Trợ lý',
        'observer' => 'Theo dõi',
    ],
    'confidentiality' => [
        'normal' => 'Thông thường',
        'restricted' => 'Hạn chế',
    ],
    'checklist_item_status' => [
        'missing' => 'Chưa nộp',
        'pending_review' => 'Chờ kiểm tra',
        'accepted' => 'Đã nhận',
        'rejected' => 'Cần nộp lại',
        'not_applicable' => 'Không cần',
    ],
    'document_group' => [
        'A' => 'Khách hàng cung cấp',
        'B' => 'Văn bản đã phát hành',
        'C' => 'Văn bản của cơ quan nhà nước',
        'D' => 'Hồ sơ công việc nội bộ',
    ],
    'document_status' => [
        'internal_draft' => 'Bản thảo nội bộ',
        'pending_approval' => 'Chờ duyệt',
        'signed_filed' => 'Đã ký, đã nộp',
        'published' => 'Đã công bố',
    ],
    'deadline_severity' => [
        'normal' => 'Thông thường',
        'critical' => 'Không thể gia hạn',
    ],
    'client_request_status' => [
        'new' => 'Mới',
        'in_progress' => 'Đang xử lý',
        'answered' => 'Đã trả lời',
        'closed' => 'Đã đóng',
    ],
    'outbound_channel' => [
        'email' => 'Email',
        'zns' => 'Zalo ZNS',
        'sms' => 'SMS',
    ],
    'outbound_status' => [
        'queued' => 'Chờ gửi',
        'sent' => 'Đã gửi',
        'failed' => 'Gửi lỗi',
    ],
    'party_role' => [
        'plaintiff' => 'Nguyên đơn',
        'defendant' => 'Bị đơn',
        'related' => 'Người có quyền lợi, nghĩa vụ liên quan',
        'third_party' => 'Bên thứ ba',
        'opposing_counsel' => 'Luật sư đối phương',
    ],
    'communication_type' => [
        'call_in' => 'Khách gọi đến',
        'call_out' => 'Gọi cho khách',
        'meeting' => 'Buổi làm việc',
        'email' => 'Email',
        'letter' => 'Công văn, thư',
        'court_visit' => 'Làm việc tại toà',
    ],
    'contract_status' => [
        'draft' => 'Nháp',
        'active' => 'Đang hiệu lực',
        'completed' => 'Đã hoàn tất',
        'cancelled' => 'Đã huỷ',
    ],
    'billing_model' => [
        'fixed_fee' => 'Trọn gói',
        'hourly' => 'Theo giờ',
        'mixed' => 'Kết hợp',
    ],
    'instalment_trigger' => [
        'on_signing' => 'Khi ký hợp đồng',
        'due_date' => 'Theo ngày cụ thể',
        'stage' => 'Theo giai đoạn vụ việc',
    ],
    'instalment_status' => [
        'pending' => 'Đang chờ thu',
        'paid' => 'Đã thu đủ',
        'waived' => 'Đã miễn',
        'cancelled' => 'Đã huỷ',
    ],
    'instalment_state' => [
        'scheduled' => 'Chưa lên lịch',
        'due' => 'Đến hạn',
        'overdue' => 'Quá hạn',
        'partially_paid' => 'Đã thu một phần',
        'paid' => 'Đã thu đủ',
        'waived' => 'Đã miễn',
        'cancelled' => 'Đã huỷ',
    ],
    'payment_method' => [
        'bank_transfer' => 'Chuyển khoản',
        'cash' => 'Tiền mặt',
        'card' => 'Thẻ',
        'offset' => 'Cấn trừ',
        'other' => 'Khác',
    ],
    // M10: tiếp nhận khách tiềm năng.
    'intake_status' => [
        'new' => 'Mới, chưa ai gọi lại',
        'contacted' => 'Đã liên hệ lại',
        'consulting' => 'Đang tư vấn',
        'quoted' => 'Đã báo phí',
        'won' => 'Đã nhận việc',
        'declined' => 'Văn phòng từ chối',
        'lost' => 'Khách không theo tiếp',
        'merged' => 'Đã gộp vào bản ghi khác',
    ],
    'intake_source' => [
        'phone' => 'Điện thoại',
        'zalo' => 'Zalo',
        'walk_in' => 'Đến văn phòng',
        'referral' => 'Người quen giới thiệu',
        'website_form' => 'Form website',
        'other' => 'Khác',
    ],
    // M10 Task 2: điều đang khoá ô câu chuyện của một lần tiếp nhận (App\Actions\Intake\IntakeSummaryGate).
    'intake_summary_blocker' => [
        'privacy_notice' => 'chưa ghi nhận người liên hệ đã nghe thông báo và đồng ý',
        'conflict_unchecked' => 'chưa kiểm tra xung đột lợi ích cho danh tính hiện tại',
        'conflict_acknowledgement' => 'cần xác nhận đã xem các khớp xung đột đang hiện',
        'conflict_red' => 'xung đột mức đỏ, cần trưởng phòng hoặc quản trị xử lý',
    ],
];
