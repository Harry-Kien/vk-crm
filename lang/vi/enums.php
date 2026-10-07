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
    // M14 Task 1: App\Enums\DocumentStoreStatus (cột system_health.document_store_status).
    'document_store_status' => [
        'ok' => 'Hoạt động bình thường',
        'degraded' => 'Cần kiểm tra',
        'unavailable' => 'Tạm thời không truy cập được',
        'misconfigured' => 'Cấu hình sai',
    ],
    // M14 Task 1: App\Enums\DriveObjectRetirement (cột drive_objects.retired_reason).
    'drive_object_retirement' => [
        'trashed' => 'Đã cho vào thùng rác',
        'superseded' => 'Đã được thay khi dựng lại chỉ mục',
    ],
    // M14 Task 1: App\Enums\PushOutcome (kết quả một lượt đẩy tệp lên kho, không lưu CSDL).
    'push_outcome' => [
        'pushed' => 'Đã đẩy lên kho',
        'already_remote' => 'Đã ở trên kho từ trước',
        'gone' => 'Tài liệu không còn',
        'disabled' => 'Kho chưa được bật',
        'locked' => 'Đang có lượt đẩy khác',
        // M14 Task 3: khoá tệp lệch khuôn của kho, hay media trên một đĩa lạ — không đẩy.
        'rejected' => 'Không đẩy: tệp không đúng khuôn của kho',
    ],
    // M14 Task 6: App\Enums\OfficeReceiptOutcome (cột office_receipt_imports.outcome).
    'office_receipt_outcome' => [
        'imported' => 'Đã nhập',
        'rejected' => 'Bị từ chối',
    ],
];
