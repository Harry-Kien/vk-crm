<?php

/**
 * Chuỗi giao diện và thông báo lỗi của tiếp nhận khách tiềm năng (M10).
 */
return [
    /*
     * Câu thông báo người nhập đọc cho người gọi (R7a, Nghị định 356/2025/NĐ-CP: đồng ý phải lưu
     * lại và kiểm chứng được, cấm đánh dấu sẵn). `version` được lưu cùng thời điểm ghi nhận
     * (`intake_requests.privacy_notice_version`): đổi chữ ở `text` thì PHẢI đổi `version`, để biết
     * mỗi người liên hệ đã nghe bản nào.
     *
     * ĐÂY LÀ BẢN NHÁP: nội dung câu thông báo và căn cứ pháp lý lưu phần danh tính khi người gọi chưa
     * đồng ý là mục CHỜ LUẬT SƯ XÁC NHẬN (kế hoạch M10, "Còn cần chủ văn phòng hoặc luật sư xác
     * nhận", mục 1). Hậu tố `-nhap` trong `version` nhắc điều đó; bỏ hậu tố khi luật sư đã duyệt.
     */
    'privacy_notice' => [
        'version' => '2026-09-nhap',
        'text' => 'Văn phòng sẽ ghi lại họ tên, số điện thoại và nội dung anh/chị trình bày để tư vấn và để kiểm tra xung đột lợi ích trước khi nhận vụ việc. Thông tin này được bảo mật theo quy định của Luật Luật sư, được lưu tối đa 24 tháng nếu anh/chị không trở thành khách hàng, sau đó được ẩn danh. Anh/chị có quyền yêu cầu xoá thông tin bất cứ lúc nào.',
    ],

    'attributes' => [
        'contact_name' => 'tên người liên hệ',
        'contact_phone' => 'số điện thoại',
        'contact_email' => 'email',
        'contact_id_number' => 'số căn cước',
        'contact_role' => 'vai dự kiến',
        'source' => 'nguồn liên hệ',
        'referred_by' => 'người giới thiệu',
        'matter_type_id' => 'lĩnh vực',
        'quoted_amount' => 'phí đã báo',
        'assigned_to' => 'người phụ trách',
        'received_at' => 'thời điểm nhận',
        'parties' => 'bên đối lập',
        'parties.*.name' => 'tên bên đối lập',
        'parties.*.role' => 'vai của bên đối lập',
        'parties.*.phone' => 'số điện thoại của bên đối lập',
        'parties.*.id_number' => 'số căn cước của bên đối lập',
        'summary' => 'nội dung câu chuyện',
        'reason' => 'lý do',
    ],

    'errors' => [
        'contact_role_opposing_counsel' => 'Người liên hệ không thể mang vai luật sư đối phương.',
        'assignee_cannot_see' => 'Người được giao phải là nhân sự đang hoạt động và có quyền ghi nhận tiếp nhận.',
        'quoted_amount_invalid' => 'Phí đã báo không hợp lệ.',
        'privacy_notice_not_agreed' => 'Chỉ ghi nhận thông báo khi người liên hệ đã nghe và đồng ý.',
        'acknowledgement_not_needed' => 'Lần kiểm tra hiện tại không có gì cần xác nhận.',
        'override_not_red' => 'Bản ghi này không có xung đột mức đỏ nào đang chờ xử lý.',
        'override_reason_required' => 'Phải nhập lý do khi ghi đè xung đột mức đỏ.',
        'override_reason_too_long' => 'Lý do quá dài.',
        'summary_too_long' => 'Nội dung câu chuyện quá dài.',
        'summary_locked' => 'Chưa thể ghi nội dung câu chuyện: :blockers',
        'record_closed' => 'Bản ghi này đã được ẩn danh hoặc gộp vào bản khác nên không ghi thêm được nội dung.',
    ],
];
