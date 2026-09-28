<?php

/**
 * Nhật ký thư đi ra (SPEC §4.15, §7.4). M6.5 Task 13 (notify-8, spec-gap-07): panel admin trước
 * đây không có màn hình nào đọc `outbound_messages`, dù bảng vẫn ghi đủ, kể cả thư lỗi.
 */
return [
    'label' => 'Thư đã gửi',
    'plural_label' => 'Thư đã gửi',

    'fields' => [
        'created_at' => 'Thời điểm mở',
        'recipient' => 'Người nhận',
        'channel' => 'Kênh',
        'template' => 'Mẫu thư',
        'status' => 'Trạng thái',
        'related' => 'Bản ghi liên quan',
        'sent_at' => 'Đã gửi lúc',
        'error' => 'Lý do lỗi',
        'subject' => 'Tiêu đề thư',
        // "—" khi không có: dòng chưa gửi xong (`sent_at` null) hoặc không lỗi (`error` null).
        'none' => '—',
        'no_related_record' => 'Không gắn vụ việc nào',
    ],

    'filters' => [
        'status' => 'Trạng thái',
        'template' => 'Mẫu thư',
        'matter' => 'Vụ việc',
        'created_from' => 'Từ ngày',
        'created_until' => 'Đến ngày',
        'created_between' => 'Khoảng ngày mở',
    ],

    // Mẫu thư đã có tên khai báo (SPEC §9); một mẫu chưa có ở đây thì cột hiện nguyên khoá thô
    // (OutboundMessage::TEMPLATE_UNDECLARED hoặc một mẫu M6/M7 sau này chưa được thêm vào đây).
    'templates' => [
        'client.otp' => 'Mã OTP đăng nhập cổng',
        'client.stage_update' => 'Cập nhật tiến độ cho khách',
        'staff.deadline_reminder' => 'Nhắc mốc thời hạn cho nhân sự',
        'undeclared' => 'Chưa khai báo mẫu',
    ],

    'matter_tab' => [
        'label' => 'Thư đã gửi',
    ],
];
