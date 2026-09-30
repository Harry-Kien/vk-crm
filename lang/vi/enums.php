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
    // M7 Task 4: App\Enums\HandoverPackageStatus.
    'handover_package_status' => [
        'generating' => 'Đang sinh',
        'ready' => 'Sẵn sàng',
        'failed' => 'Lỗi',
    ],
];
