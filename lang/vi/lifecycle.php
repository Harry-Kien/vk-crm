<?php

/*
 * Làn fm — sửa sau kiểm tra nghiệp vụ toàn hệ thống (2026-10-09), luồng vòng đời vụ việc. Tệp riêng
 * để không đụng chuỗi của các làn khác chạy song song.
 */
return [
    // App\Support\MatterOpenWork — việc còn dở, hiện trên hộp thoại đóng vụ và huỷ hồ sơ.
    'open_work' => [
        'deadline' => 'Mốc thời hạn chưa xong: :name (hạn :date)',
        'more_deadlines' => 'Và :count mốc thời hạn chưa xong khác',
        'client_requests' => ':count yêu cầu của khách chưa đóng',
        'checklist_pending' => ':count giấy tờ khách đã nộp đang chờ duyệt',
        'balance' => 'Còn phải thu :amount trên hợp đồng đang hiệu lực',
        'documents' => ':count tài liệu trong hồ sơ',
        'on_portal' => 'Vụ đang được công bố trên cổng khách hàng: khách sẽ không còn thấy vụ này',
        'none' => 'Không còn việc dở nào được ghi nhận trên hệ thống.',
    ],

    // Hộp thoại "Chuyển giai đoạn" khi giai đoạn đích là giai đoạn kết thúc.
    'close' => [
        'heading' => 'Trước khi kết thúc vụ việc',
        'open_work_intro' => 'Những việc sau vẫn đang dở:',
        'consequences' => 'Sau khi kết thúc, mốc thời hạn của vụ không còn được nhắc và không hiện trên trang chủ. Chỉ quản trị viên mở lại được vụ đã kết thúc.',
        'confirm' => 'Tôi đã xem các việc còn dở và xác nhận kết thúc vụ việc',
        'confirm_required' => 'Hãy tích xác nhận trước khi kết thúc vụ việc.',
    ],

    // A2 — "Rút khỏi cổng" một dòng tiến độ đã công bố (App\Actions\Matter\RetractStageLog). Lý do
    // là chữ NỘI BỘ: dòng biến hẳn khỏi cổng, khách không đọc thấy gì về nó nữa.
    'stage_log' => [
        'action' => 'Rút khỏi cổng',
        'modal_heading' => 'Rút dòng tiến độ khỏi cổng khách hàng',
        'modal_description' => 'Khách sẽ không còn thấy dòng này trên cổng, và nó không vào mục lục gói bàn giao. Dòng vẫn nằm trong sổ tiến độ nội bộ (không xoá, không sửa). Thư báo đã gửi thì không thu hồi được. Muốn khách đọc nội dung đúng, hãy đăng một cập nhật mới.',
        'submit' => 'Rút khỏi cổng',
        'success' => 'Đã rút dòng tiến độ khỏi cổng khách hàng.',
        'reason' => 'Lý do rút (chỉ nội bộ)',
        'reason_help' => 'Khách không đọc được lý do này. Tối thiểu :min ký tự.',
        'reason_min' => 'Lý do rút cần tối thiểu :min ký tự.',
        'reason_max' => 'Lý do rút dài tối đa :max ký tự.',
        'retracted_marker' => 'Đã rút khỏi cổng lúc :date bởi :by',
        'retracted_reason' => 'Lý do: :reason',
        'unknown_actor' => 'tài khoản đã xoá',
        'not_published' => 'Dòng tiến độ này chưa công bố cho khách nên không có gì để rút.',
        'already_retracted' => 'Dòng tiến độ này đã được rút khỏi cổng trước đó.',
        'missing' => 'Không mở được dòng tiến độ này.',
    ],

    // A3 — tab Tổng quan và trang Sửa vụ việc (UpdateMatterDetails): ghi chú nội bộ, ngày mở hồ sơ.
    'details' => [
        'internal_only' => 'Chỉ nội bộ',
        'internal_note_too_long' => 'Ghi chú nội bộ dài tối đa :max ký tự.',
        'opened_at_invalid' => 'Ngày mở hồ sơ không hợp lệ.',
        'opened_at_future' => 'Ngày mở hồ sơ không được sau hôm nay.',
        'opened_at_after_closed' => 'Ngày mở hồ sơ không được sau ngày vụ việc kết thúc.',
    ],

    // Tab "Mốc thời hạn" và hai Action AddMatterDeadline/UpdateDeadline trên vụ đã kết thúc.
    'deadlines' => [
        'closed_notice' => 'Vụ việc đã kết thúc: mốc thời hạn của vụ không còn được nhắc và không hiện trên trang chủ. Không thêm hay sửa mốc được nữa; muốn đặt mốc mới, nhờ quản trị viên mở lại vụ qua "Chuyển giai đoạn".',
        'closed_refused' => 'Vụ việc đã kết thúc nên mốc thời hạn sẽ không được nhắc: không thêm hay sửa mốc được. Nhờ quản trị viên mở lại vụ qua "Chuyển giai đoạn" rồi đặt mốc.',
    ],
];
