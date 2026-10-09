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

    // Tab "Mốc thời hạn" và hai Action AddMatterDeadline/UpdateDeadline trên vụ đã kết thúc.
    'deadlines' => [
        'closed_notice' => 'Vụ việc đã kết thúc: mốc thời hạn của vụ không còn được nhắc và không hiện trên trang chủ. Không thêm hay sửa mốc được nữa; muốn đặt mốc mới, nhờ quản trị viên mở lại vụ qua "Chuyển giai đoạn".',
        'closed_refused' => 'Vụ việc đã kết thúc nên mốc thời hạn sẽ không được nhắc: không thêm hay sửa mốc được. Nhờ quản trị viên mở lại vụ qua "Chuyển giai đoạn" rồi đặt mốc.',
    ],
];
