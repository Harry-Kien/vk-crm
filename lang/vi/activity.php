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
        // Final review C-M2: LogsActivity ghi sự kiện này khi khôi phục một bản ghi đã xoá mềm.
        'restored' => 'Khôi phục',
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

        /*
         * M6.5 Task 20: `tests/Feature/ActivityLogEventTranslationsTest.php` quét MỌI literal
         * `Audit::record('…')` trong app/ và đòi có mặt ở đây — bổ sung phần còn thiếu tính tới
         * lúc rà soát (2026-09-24), gộp theo Action đã ghi chúng. Task 9 và Task 11 chạy song
         * song có thể thêm khoá sự kiện MỚI sau khi làn này merge; nếu bộ test sau merge đỏ, lỗi
         * sẽ nêu đích danh khoá còn thiếu — thêm đúng khoá đó vào đây, không cần đọc lại toàn bộ
         * danh sách.
         */
        // app/Actions/Deadline/*.php
        'deadline_added' => 'Thêm mốc hạn',
        'deadline_responsible_changed' => 'Đổi người phụ trách mốc hạn',
        'deadline_completion_set' => 'Cập nhật hoàn thành mốc hạn',
        'deadline_publication_set' => 'Đổi công bố mốc hạn',
        // M6.5 Task 14: app/Actions/Deadline/{UpdateDeadline,DeleteDeadline}.php.
        'deadline_updated' => 'Sửa mốc thời hạn',
        'deadline_deleted' => 'Xoá mốc thời hạn',
        // app/Actions/Document/*.php
        'checklist_item_added' => 'Thêm đầu mục danh mục',
        'checklist_item_marked_not_applicable' => 'Đánh dấu không áp dụng đầu mục danh mục',
        'checklist_item_reviewed' => 'Duyệt đầu mục danh mục',
        'document_signed_filed' => 'Đánh dấu đã ký, đã nộp',
        'document_regrouped' => 'Đổi nhóm tài liệu',
        'document_returned_to_draft' => 'Trả tài liệu về nháp',
        'document_submitted' => 'Khách nộp tài liệu',
        'document_submitted_for_approval' => 'Trình duyệt tài liệu',
        'document_uploaded' => 'Tải tài liệu lên',
        // app/Actions/Matter/*.php
        'matter_cancelled' => 'Huỷ vụ việc',
        'matter_reassigned' => 'Bàn giao vụ việc',
        'matter_details_updated' => 'Sửa thông tin vụ việc',
        // Task 14 (bước đầu tiên, theo chỉ đạo controller): năm khoá dưới đây đã được ghi qua
        // Audit::record() từ trước nhưng chưa có nhãn — ActivityLogEventTranslationsTest đỏ ngay
        // trước khi task này bắt đầu vì đúng lý do đó. Bổ sung trước, không đụng gì khác, để bộ
        // test xanh lại trước khi làm việc chính của task.
        // app/Actions/RemoveMatterParty.php:126, app/Actions/UpdateMatterParty.php:226
        'matter_party_removed' => 'Gỡ bên khỏi vụ việc',
        'matter_party_updated' => 'Sửa thông tin bên trong vụ việc',
        // app/Actions/Client/CreateClient.php:152, app/Actions/Client/FindClientByIdentifier.php:72,88
        // (R4: tra định danh khi mở vụ cho khách mới — trúng hay trượt, không ghi số thô)
        'client_lookup' => 'Tra cứu định danh khách hàng',
        'client_lookup_throttled' => 'Tra cứu định danh khách hàng bị khoá tạm (thử quá nhiều lần)',
        // app/Actions/Portal/*.php
        'client_request_opened' => 'Mở yêu cầu của khách',
        'client_request_assigned' => 'Giao yêu cầu của khách',
        'client_request_status_changed' => 'Đổi trạng thái yêu cầu của khách',
        'client_request_replied_by_client' => 'Khách trả lời yêu cầu',
        'client_request_answered_by_staff' => 'Nhân sự trả lời yêu cầu',
        'portal_login_unlocked' => 'Mở khoá đăng nhập cổng khách hàng',
        // app/Actions/SyncClientPartyIdentities.php, app/Jobs/RecheckClientIdentityConflicts.php
        'client_identity_conflict_detected' => 'Phát hiện xung đột khi đồng bộ định danh',
        'client_identity_recheck_failed' => 'Kiểm tra lại xung đột lỗi',
        // app/Jobs/SendDeadlineReminderMail.php:198 — job nhắc mốc hỏng hẳn sau hết lượt thử lại.
        'deadline_reminder_failed' => 'Gửi thư nhắc mốc thời hạn thất bại hẳn',
        // Task 20: app/Filament/Admin/Resources/Users/Pages/EditUser.php.
        'user_password_reset' => 'Đặt lại mật khẩu nhân sự',
        // M8 Task 2 (R2): app/Actions/User/ResetStaffTwoFactor.php.
        'staff_two_factor_reset' => 'Đặt lại 2FA nhân sự',
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
        // Task 20 (phát hiện "trang Nhật ký hệ thống ... không hiện properties"): nút mở modal
        // xem chi tiết một dòng, và tiêu đề của modal đó.
        'actions' => [
            'view_properties' => 'Xem chi tiết',
        ],
        'properties' => [
            'modal_heading' => 'Chi tiết nhật ký',
            'empty' => 'Không có dữ liệu chi tiết.',
            // App\Support\SensitivePropertyFilter thay giá trị của mọi khoá định danh thô bằng
            // đúng câu này — không phải xoá khoá, để người xem vẫn biết trường đó CÓ ghi nhận,
            // chỉ là bị ẩn có chủ đích (Controller decision Task 20: "không bao giờ hiện
            // id_number hay bất kỳ định danh cá nhân thô nào").
            'redacted' => '••• (đã ẩn — định danh cá nhân thô)',
        ],
    ],
];
