<?php

/*
 * Làn fa (kiểm tra nghiệp vụ 2026-10-09, mục A1) — người gọi đại diện cho một TỔ CHỨC. Tệp riêng để
 * không chạm khối câu của `intake.php` mà các làn khác cũng sửa.
 */
return [
    'attributes' => [
        'organization_name' => 'tên tổ chức',
        'organization_id_number' => 'mã số thuế của tổ chức',
        'parties.*.id_number' => 'số căn cước / mã số thuế của bên đối lập',
    ],

    'fields' => [
        'organization_name' => 'Tên tổ chức (nếu người gọi đại diện cho tổ chức)',
        'organization_name_help' => 'Công ty, hộ kinh doanh hay tổ chức mà người gọi thay mặt. Tổ chức này cũng được kiểm tra xung đột lợi ích như một bên phía khách; không có mã số thuế thì chỉ kiểm theo tên và ô câu chuyện cần bước xác nhận.',
        'organization_id_number' => 'Mã số thuế của tổ chức',
        'organization_id_number_help' => 'Không bắt buộc. Hệ thống chỉ lưu dạng mã hoá để kiểm tra xung đột, không lưu số gốc.',
        'organization_id_number_help_edit' => 'Để trống để giữ mã số thuế đã lưu dạng mã hoá (nếu có). Nhập mã mới để thay.',
        'party_id_number' => 'Số căn cước / mã số thuế',
    ],

    'convert' => [
        'organization_line' => 'Tổ chức: :name',
        'organization_tax_line' => 'Mã số thuế của tổ chức: đã ghi lúc tiếp nhận (chỉ lưu dạng mã hoá)',
        'organization_no_tax_line' => 'Mã số thuế của tổ chức: chưa có',
        'id_number_organization_help' => 'Khách là tổ chức: nhập mã số thuế của tổ chức (không bắt buộc). Nếu lúc tiếp nhận đã ghi mã số thuế thì số nhập ở đây phải trùng số đó. Người liên hệ thành người đại diện của hồ sơ khách.',
    ],

    // Mục nhỏ: bản Đỏ đã ghi đè, người xem không ghi đè được Đỏ (trang bản ghi, trang chuyển đổi).
    'override_needs_manager' => 'Bản ghi có xung đột mức Đỏ đã được trưởng phòng ghi đè. Khi chuyển thành vụ việc, hệ thống kiểm tra lại; nếu khớp Đỏ vẫn còn, việc chuyển đổi cần trưởng phòng hoặc quản trị thực hiện.',

    'errors' => [
        'convert_needs_organization' => 'Bản ghi chưa có tên tổ chức. Nhập tên tổ chức (và mã số thuế nếu có) ở trang bản ghi trước, để tổ chức được kiểm tra xung đột lợi ích, rồi chuyển đổi lại.',
        'convert_tax_code_mismatch' => 'Mã số thuế này không khớp mã số thuế của tổ chức đã ghi lúc tiếp nhận. Kiểm tra lại với người liên hệ; nếu số lúc tiếp nhận sai thì sửa ở trang bản ghi trước.',
        // Fix vòng 1, I1: hồ sơ khách đã có mà lần tra tìm ra khác loại với loại khách đang chuyển đổi —
        // không gắn, không hỏi xác nhận (khoá con = loại khách ĐANG chuyển đổi).
        'convert_existing_client_type_differs' => [
            'organization' => 'Mã số thuế này trùng hồ sơ khách hàng CÁ NHÂN :code — :name, không phải một tổ chức. Hệ thống không gắn công ty vào hồ sơ cá nhân. Kiểm tra lại mã số thuế với người liên hệ; nếu số đúng thì nhờ người quản lý hồ sơ khách hàng sửa số trên hồ sơ :code, rồi chuyển đổi lại.',
            'individual' => 'Số đã tra trùng khách hàng TỔ CHỨC :code — :name. Hệ thống không gắn người liên hệ cá nhân vào hồ sơ của một tổ chức. Nếu vụ này là của tổ chức đó, ghi tên tổ chức (và mã số thuế) ở trang bản ghi rồi chuyển đổi với loại khách "Tổ chức"; nếu là việc riêng của người liên hệ, nhờ người quản lý hồ sơ khách hàng tạo hồ sơ cá nhân cho người đó (kèm số căn cước), rồi nhập số căn cước đó ở ô này.',
        ],
        'convert_lead_lawyer_ineligible' => 'Người này không phụ trách được vụ việc: tài khoản đã bị vô hiệu hoá hoặc không có quyền chuyển giai đoạn vụ việc. Chọn một luật sư khác.',
    ],
];
