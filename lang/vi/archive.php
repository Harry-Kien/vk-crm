<?php

use App\Actions\Matter\RecordMatterDestruction;
use App\Actions\Schedule\FlagRetentionExpiry;

/**
 * Hạn lưu trữ và quyết định tiêu huỷ hồ sơ (M7 Task 6, R5). Hệ thống KHÔNG BAO GIỜ tự xoá hồ sơ:
 * {@see FlagRetentionExpiry} chỉ cảnh báo quản trị khi hồ sơ quá hạn lưu trữ, và
 * {@see RecordMatterDestruction} chỉ GHI LẠI quyết định tiêu huỷ (đã lập biên bản ngoài hệ thống).
 * Các câu dưới đây phải nói đúng điều đó với người đọc.
 */
return [
    // Khối "Lưu trữ hồ sơ" trên tab Tổng quan của trang vụ việc.
    'section' => [
        'heading' => 'Lưu trữ hồ sơ',
        'description' => 'Hạn lưu trữ tính từ ngày vụ việc kết thúc. Hệ thống không bao giờ tự xoá hồ sơ.',
        'fields' => [
            'retention_until' => 'Lưu trữ tới hết ngày',
            'destroyed_at' => 'Đã ghi quyết định tiêu huỷ lúc',
            'destroyed_by' => 'Người ghi quyết định',
            'destruction_record_no' => 'Số biên bản tiêu huỷ',
            'destruction_reason' => 'Lý do tiêu huỷ',
        ],
        'not_destroyed' => 'Chưa có quyết định tiêu huỷ',
        'retention_expired_hint' => 'Hồ sơ đã quá hạn lưu trữ. Nếu văn phòng quyết định tiêu huỷ, hãy lập biên bản; quản trị viên ghi quyết định đó trên trang này.',
    ],

    'destruction' => [
        'action' => [
            'label' => 'Ghi quyết định tiêu huỷ',
            'modal_heading' => 'Ghi quyết định tiêu huỷ hồ sơ',
            'modal_description' => 'Thao tác này chỉ GHI LẠI quyết định tiêu huỷ đã được lập biên bản; hệ thống không xoá vụ việc, tài liệu hay tệp nào. Việc huỷ hồ sơ giấy và tệp làm ngoài hệ thống theo biên bản. Quyết định đã ghi không sửa được.',
            'submit' => 'Ghi quyết định',
            'success' => 'Đã ghi quyết định tiêu huỷ hồ sơ.',
        ],
        'fields' => [
            'destruction_record_no' => 'Số biên bản tiêu huỷ',
            'destruction_record_no_hint' => 'Ví dụ: BB-TH-2026-001',
            'destruction_reason' => 'Lý do tiêu huỷ',
            'destruction_reason_hint' => 'Tối thiểu 20 ký tự: căn cứ, người phê duyệt, cách thức tiêu huỷ.',
        ],
        'validation' => [
            'reason_min' => 'Lý do tiêu huỷ cần ít nhất :min ký tự.',
            'reason_max' => 'Lý do tiêu huỷ không được dài quá :max ký tự.',
            'record_no_required' => 'Cần nhập số biên bản tiêu huỷ.',
            'record_no_max' => 'Số biên bản tiêu huỷ không được dài quá :max ký tự.',
        ],
        'exceptions' => [
            'matter_deleted' => 'Vụ việc này đã bị xoá nên chưa ghi được quyết định tiêu huỷ. Khôi phục vụ việc trước rồi ghi lại.',
            'no_archive' => 'Vụ việc này chưa có hồ sơ lưu trữ (chưa từng kết thúc), nên không có gì để tiêu huỷ.',
            'not_closed' => 'Vụ việc này đang được xử lý (đã được mở lại), nên chưa ghi được quyết định tiêu huỷ.',
            'retention_not_expired' => 'Hồ sơ còn trong hạn lưu trữ tới hết ngày :date, nên chưa ghi được quyết định tiêu huỷ.',
            'already_recorded' => 'Quyết định tiêu huỷ của hồ sơ này đã được ghi trước đó và không ghi lại được.',
        ],
    ],

    // Thông báo trong hệ thống (chuông của panel admin) — FlagRetentionExpiry.
    'retention_alert' => [
        'title' => 'Hồ sơ đã quá hạn lưu trữ',
        'body' => 'Vụ việc :code đã hết hạn lưu trữ ngày :date. Hệ thống không tự xoá gì. Nếu văn phòng quyết định tiêu huỷ, hãy lập biên bản rồi ghi quyết định trên trang vụ việc.',
        'open' => 'Mở vụ việc',
    ],

    // Rà soát cuối M7 (I3): cảnh báo trên form "Chuyển giai đoạn"/"Thêm cập nhật" khi khách có tài
    // khoản nhưng vụ không còn trên cổng của họ (vụ đã kết thúc và quá hạn tra cứu).
    'stage_update' => [
        'not_on_portal_warning' => 'Khách không còn xem được vụ việc này trên cổng khách hàng (vụ đã hết hạn tra cứu) — cập nhật sẽ không hiện cho khách và sẽ không ai nhận thư.',
    ],
];
