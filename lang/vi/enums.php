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
    // M11 R5 (Task 13): App\Enums\McpDraftState — trạng thái của một nháp do AI soạn (suy từ cột).
    'mcp_draft_state' => [
        'pending' => 'Đang chờ người duyệt trên web',
        'used' => 'Đã gửi từ nháp',
        'discarded' => 'Đã bỏ',
    ],
    // M11 R5 (Task 7): App\Enums\CreatedVia — mốc hạn, nhật ký liên lạc tạo qua đường nào.
    'created_via' => [
        'web' => 'Nhập trên web',
        'mcp' => 'Tạo qua AI',
    ],
    // M11 R2 (Task 6): App\Enums\AiAccessMode — công tắc truy cập qua AI theo người (`users.ai_access`).
    'ai_access_mode' => [
        'off' => 'Tắt',
        'read' => 'Chỉ đọc',
        'read_write' => 'Đọc và ghi',
    ],
    // M11 R2/R12 (Task 6, Task 4): App\Enums\McpAccessRefusal — vì sao một tài khoản chưa dùng được máy
    // chủ AI, hay chưa đồng ý được một kết nối. Màn hình đồng ý OAuth (Task 4) hiện nguyên câu cho chính
    // người đó, nên mỗi câu nói điều người đó làm được tiếp theo.
    'mcp_access_refusal' => [
        'not_staff' => 'Chỉ tài khoản nhân sự của văn phòng mới kết nối được trợ lý AI.',
        'inactive' => 'Tài khoản của anh/chị đang bị vô hiệu hoá.',
        'two_factor_not_set_up' => 'Anh/chị cần cài xác thực hai bước (2FA) trên trang quản trị trước khi kết nối trợ lý AI.',
        'ai_access_off' => 'Quản trị chưa bật truy cập qua AI cho tài khoản của anh/chị.',
        'no_matter_view' => 'Vai trò hiện tại của anh/chị không xem được nội dung vụ việc, nên không dùng được trợ lý AI. Hỏi quản trị nếu vai trò này chưa đúng.',
        'server_disabled' => 'Máy chủ AI của văn phòng đang tắt.',
        'policy_not_acknowledged' => 'Anh/chị chưa cam kết chính sách dùng AI phiên bản hiện hành (trang "Kết nối AI của tôi").',
    ],
    // M11 Task 4: App\Enums\McpPlatform — nền tảng AI suy từ host redirect của một kết nối (màn hình
    // đồng ý, nhật ký). Loopback không đoán tên ứng dụng: cổng không nói gì về ứng dụng.
    'mcp_platform' => [
        'claude' => 'Claude',
        'chatgpt' => 'ChatGPT',
        'local_app' => 'Ứng dụng trên máy tính này',
        'vscode' => 'VS Code',
        'cursor' => 'Cursor',
        'antigravity' => 'Antigravity',
        'other' => 'Ứng dụng khác',
    ],
    // M11 R8 (Task 6): App\Enums\AiRevocationReason — vì sao mọi kết nối AI của một nhân sự bị thu hồi.
    'ai_revocation_reason' => [
        'ai_access_off' => 'Tắt truy cập qua AI',
        'deactivated' => 'Vô hiệu hoá tài khoản',
        'role_changed' => 'Đổi chức danh hoặc vai trò',
        'password_changed' => 'Đổi mật khẩu',
        'two_factor_reset' => 'Đặt lại 2FA',
        'deleted' => 'Xoá tài khoản',
        'revoked_by_admin' => 'Quản trị thu hồi kết nối',
        'revoked_by_self' => 'Nhân sự tự thu hồi kết nối',
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
        // M7 Task 7: RetractDocument — trạng thái thứ năm, tài liệu đã rút khỏi cổng khách.
        'retracted' => 'Đã rút lại',
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
        // M12 R13 — dòng của `App\Actions\Notification\RecordOutboundPush`, mỗi máy một dòng.
        'push' => 'Thông báo đẩy',
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
    // M12 R10 — `App\Enums\PushTopic`. Giá trị có dấu chấm (`client.stage_update`) nên khoá lồng theo
    // từng đoạn: `__('enums.push_topic.client.stage_update')` đi đúng mảng con.
    'push_topic' => [
        'client' => [
            'stage_update' => 'Cập nhật tiến độ hồ sơ',
            'document_published' => 'Tài liệu mới cho khách',
            'document_rejected' => 'Giấy tờ khách nộp chưa đạt',
            'request_answered' => 'Văn phòng trả lời yêu cầu',
        ],
        'staff' => [
            'deadline_reminder' => 'Nhắc mốc thời hạn',
            'new_client_request' => 'Khách gửi yêu cầu',
            'new_client_document' => 'Khách nộp giấy tờ',
            'instalment_overdue' => 'Đợt thanh toán quá hạn',
            'handover_ready' => 'Gói bàn giao đã sẵn sàng',
        ],
        'push' => [
            'test' => 'Thông báo thử',
        ],
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
        'declined' => 'văn phòng đã từ chối bản ghi này',
    ],
    // M7 Task 4: App\Enums\HandoverPackageStatus.
    'handover_package_status' => [
        'generating' => 'Đang sinh',
        'ready' => 'Sẵn sàng',
        'failed' => 'Lỗi',
    ],
];
