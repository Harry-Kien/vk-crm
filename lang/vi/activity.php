<?php

/**
 * Nhãn tiếng Việt cho activity log (SPEC §10.6). Các sự kiện dưới đây được ghi qua
 * App\Support\Audit::record() ở các task M3–M8 tạo ra chúng; task này chỉ khai báo trước
 * từ vựng chung để không lệch tên sự kiện giữa các nơi gọi.
 */
return [
    'log_name' => 'Nhật ký hệ thống',

    'events' => [
        'created' => 'Tạo mới',
        'updated' => 'Cập nhật',
        'deleted' => 'Xoá',
        'login_success' => 'Đăng nhập thành công',
        'login_failed' => 'Đăng nhập thất bại',
        'document_downloaded' => 'Tải tài liệu',
        'document_published' => 'Công bố tài liệu',
        'stage_log_published' => 'Công bố tiến độ',
        'permission_changed' => 'Đổi phân quyền',
        'portal_account_created' => 'Tạo tài khoản portal',
        'portal_account_deactivated' => 'Vô hiệu hoá tài khoản portal',
        'data_exported' => 'Xuất dữ liệu',
        // Bốn sự kiện M3 thực sự ghi qua Audit::record() (review fix round 1, Important #1):
        // app/Actions/{OpenMatter,TransitionMatterStage,AddMatterParty,RunConflictCheck}.php.
        'matter_opened' => 'Mở vụ việc',
        'matter_stage_transitioned' => 'Chuyển giai đoạn vụ việc',
        'matter_party_added' => 'Thêm bên trong vụ việc',
        'conflict_check_run' => 'Kiểm tra xung đột lợi ích',
    ],

    /** Trang xem SPEC §7.4, chỉ đọc, gated bằng auditLog.view. */
    'page' => [
        'title' => 'Nhật ký hệ thống',
        'navigation_label' => 'Nhật ký hệ thống',
        'columns' => [
            'created_at' => 'Thời gian',
            'log_name' => 'Nhóm',
            'event' => 'Sự kiện',
            'causer' => 'Người thực hiện',
            'subject' => 'Đối tượng',
            'description' => 'Diễn giải',
        ],
        'system_causer' => 'Hệ thống',
    ],
];
