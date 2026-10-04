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
    // M11 R9 (Task 7): App\Enums\MatterAiAccess — cờ "vụ việc lên AI" của tab Tổng quan. Nhãn
    // `allowed` cố ý không nói "khách đã đồng ý": vụ mở khi `MCP_MATTER_DEFAULT=allowed` nhận giá trị
    // này mà không ai xác nhận gì; lời xác nhận chỉ nằm ở dòng audit `matter_ai_access_changed`.
    'matter_ai_access' => [
        'allowed' => 'Cho phép AI truy cập',
        'denied' => 'Không cho AI truy cập',
    ],
    // M11 R5 (Task 7): App\Enums\CreatedVia — mốc hạn, nhật ký liên lạc tạo qua đường nào.
    'created_via' => [
        'web' => 'Nhập trên web',
        'mcp' => 'Tạo qua AI',
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
];
