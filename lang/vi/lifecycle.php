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

    // A4 — "Huỷ hồ sơ mở nhầm" (CancelMatter, EditMatter) và danh sách/khôi phục hồ sơ đã huỷ.
    'cancel' => [
        'consequences' => 'Hồ sơ đã huỷ biến khỏi mọi danh sách, cổng khách hàng, nhắc hạn và trang chủ. Chỉ quản trị viên khôi phục được, ở danh sách vụ việc với bộ lọc "Hồ sơ đã huỷ".',
        'closed_refused' => 'Vụ việc này đã từng kết thúc nên không phải hồ sơ mở nhầm: huỷ nó sẽ đưa hồ sơ ra khỏi chính sách lưu trữ và tiêu huỷ. Hồ sơ đã kết thúc được giữ lại theo hạn lưu trữ.',
        'filter' => 'Hồ sơ đã huỷ',
        'filter_without' => 'Không gồm hồ sơ đã huỷ',
        'filter_only' => 'Chỉ hồ sơ đã huỷ',
        'filter_with' => 'Gồm cả hồ sơ đã huỷ',
    ],
    'restore' => [
        'action' => 'Khôi phục',
        'modal_heading' => 'Khôi phục hồ sơ đã huỷ',
        'modal_description' => 'Hồ sơ trở lại như lúc bị huỷ: hiện lại trên danh sách, cổng khách hàng (nếu đang bật công bố), nhắc hạn và trang chủ. Lý do được ghi vào nhật ký.',
        'submit' => 'Khôi phục',
        'reason' => 'Lý do khôi phục',
        'reason_required' => 'Hãy nhập lý do khôi phục.',
        'reason_max' => 'Lý do khôi phục dài tối đa :max ký tự.',
        'not_cancelled' => 'Hồ sơ này không ở trạng thái đã huỷ.',
        'success' => 'Đã khôi phục hồ sơ.',
    ],

    // A5 — hạn khách tra cứu hồ sơ đã kết thúc (khối "Lưu trữ hồ sơ", nút gia hạn, hộp công bố).
    'access' => [
        'until_label' => 'Khách tra cứu được tới hết ngày',
        'expired_hint' => 'Đã hết hạn tra cứu: khách không còn thấy vụ này trên cổng và không nhận thư về vụ. Gia hạn bằng nút "Gia hạn tra cứu cho khách".',
        'action' => 'Gia hạn tra cứu cho khách',
        'modal_heading' => 'Gia hạn tra cứu cho khách',
        'modal_description' => 'Hạn hiện tại: hết ngày :date. Khách thấy lại vụ trên cổng (nếu vụ đang bật công bố) tới hết ngày mới. Nếu tài khoản cổng của khách đã bị hệ thống tự tắt vì không còn vụ nào, hãy bật lại ở mục Tài khoản cổng. Lý do được ghi vào nhật ký.',
        'submit' => 'Gia hạn',
        'until' => 'Gia hạn tới hết ngày',
        'reason' => 'Lý do gia hạn',
        'success' => 'Đã gia hạn tra cứu cho khách.',
        'not_extendable' => 'Chỉ gia hạn được vụ việc đã kết thúc, có hồ sơ lưu trữ và chưa ghi quyết định tiêu huỷ.',
        'until_invalid' => 'Ngày gia hạn không hợp lệ.',
        'until_too_early' => 'Ngày gia hạn phải sau ngày :date.',
        'until_too_late' => 'Mỗi lần chỉ gia hạn tối đa :days ngày kể từ hôm nay.',
        'reason_required' => 'Hãy nhập lý do gia hạn.',
        'reason_max' => 'Lý do gia hạn dài tối đa :max ký tự.',
        'publish_warning' => 'Vụ việc đã hết hạn tra cứu từ sau ngày :date: khách sẽ không thấy tài liệu này và không nhận thư. Gia hạn tra cứu ở trang vụ trước khi công bố.',
        'published_not_visible' => 'Đã công bố, nhưng khách chưa thấy tài liệu vì vụ đã hết hạn tra cứu. Gia hạn tra cứu ở trang vụ để khách xem được.',
    ],

    // Tab "Mốc thời hạn" và hai Action AddMatterDeadline/UpdateDeadline trên vụ đã kết thúc.
    'deadlines' => [
        'closed_notice' => 'Vụ việc đã kết thúc: mốc thời hạn của vụ không còn được nhắc và không hiện trên trang chủ. Không thêm hay sửa mốc được nữa; muốn đặt mốc mới, nhờ quản trị viên mở lại vụ qua "Chuyển giai đoạn".',
        'closed_refused' => 'Vụ việc đã kết thúc nên mốc thời hạn sẽ không được nhắc: không thêm hay sửa mốc được. Nhờ quản trị viên mở lại vụ qua "Chuyển giai đoạn" rồi đặt mốc.',
    ],
];
