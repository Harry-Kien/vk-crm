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
        // Ghi qua Audit::record() ở app/Actions/SyncClientPartyIdentities.php khi hồ sơ khách
        // hàng đổi số căn cước/điện thoại và ảnh chụp định danh của các bên được đồng bộ lại.
        'client_identity_resynced' => 'Đồng bộ lại định danh các bên',
        // App\Actions\SetMatterPortalPublication (fix round 2 review, task 2).
        'matter_portal_publication_set' => 'Đổi trạng thái công bố portal',
        // App\Actions\Matter\{AddTeamMember,RemoveTeamMember} (M6.5 Task 3, fix round 1, "also
        // fix": thiếu hai khoá này khiến ActivityLogPage hiện nguyên văn khoá sự kiện tiếng Anh).
        'team_member_added' => 'Thêm thành viên đội ngũ',
        'team_member_removed' => 'Gỡ thành viên đội ngũ',
        // App\Actions\Billing\{DraftContract,ActivateContract,AmendContract,CompleteContract,
        // CancelContract} (M9 Task 4).
        'contract_drafted' => 'Soạn hợp đồng dịch vụ',
        'contract_activated' => 'Kích hoạt hợp đồng dịch vụ',
        'contract_amended' => 'Ký phụ lục hợp đồng',
        'contract_completed' => 'Hoàn tất hợp đồng dịch vụ',
        'contract_cancelled' => 'Huỷ hợp đồng dịch vụ',
        // Lượt rà soát cuối M9, I3: bốn sự kiện tiền còn thiếu nhãn —
        // App\Actions\Billing\{UpdateDraftContract,RecordPayment,VoidPayment,WaiveInstalment}.
        // tests/Feature/ActivityLogEventTranslationsTest.php (cùng nội dung với tệp của M6.5 Task 20)
        // quét MỌI literal Audit::record('…') trong app/ và đòi có mặt ở đây.
        'contract_draft_updated' => 'Sửa hợp đồng nháp',
        'payment_recorded' => 'Ghi khoản thu',
        'payment_voided' => 'Huỷ khoản thu',
        'instalment_waived' => 'Miễn đợt thanh toán',

        /*
         * Các sự kiện ngoài M9 đã ghi qua Audit::record() từ trước nhưng chưa có nhãn ở nhánh này
         * — cùng nhãn M6.5 Task 20 đã đặt trên nhánh của nó, để lúc merge chỉ còn chọn một bản.
         */
        // app/Actions/Deadline/*.php
        'deadline_added' => 'Thêm mốc hạn',
        'deadline_completion_set' => 'Cập nhật hoàn thành mốc hạn',
        'deadline_publication_set' => 'Đổi công bố mốc hạn',
        // app/Actions/Document/*.php
        'checklist_item_marked_not_applicable' => 'Đánh dấu không áp dụng đầu mục danh mục',
        'checklist_item_reviewed' => 'Duyệt đầu mục danh mục',
        'document_regrouped' => 'Đổi nhóm tài liệu',
        'document_submitted' => 'Khách nộp tài liệu',
        'document_uploaded' => 'Tải tài liệu lên',
        // app/Actions/Portal/*.php
        'client_request_opened' => 'Mở yêu cầu của khách',
        'client_request_assigned' => 'Giao yêu cầu của khách',
        'client_request_status_changed' => 'Đổi trạng thái yêu cầu của khách',
        'client_request_replied_by_client' => 'Khách trả lời yêu cầu',
        'client_request_answered_by_staff' => 'Nhân sự trả lời yêu cầu',
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
