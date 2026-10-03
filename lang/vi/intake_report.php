<?php

/**
 * Chuỗi giao diện của trang "Bức tranh đầu vào" (M10 Task 6) — báo cáo tiếp nhận trên panel admin.
 * Tệp riêng (không trộn vào `intake.php` hay `widgets.php`) để các làn M10 chạy song song không sửa
 * cùng một chỗ.
 *
 * Không chuỗi nào ở đây mang dữ liệu của người liên hệ: trang chỉ đếm, và chỉ liệt kê nhân sự.
 */
return [
    'navigation_label' => 'Báo cáo tiếp nhận',
    'title' => 'Bức tranh đầu vào',
    'scope_note' => 'Số liệu gồm các bản ghi tiếp nhận anh/chị được xem, tính theo ngày nhận liên hệ.',

    'filters' => [
        'period' => 'Kỳ',
        'period_options' => [
            'this_month' => 'Tháng này',
            'this_quarter' => 'Quý này',
            'this_year' => 'Năm nay',
            'custom' => 'Tuỳ chọn (từ – đến)',
        ],
        'date_from' => 'Từ ngày',
        'date_to' => 'Đến ngày',
        'receiver' => 'Người tiếp nhận',
        'receiver_help' => 'Nhân sự đã ghi bản ghi tiếp nhận (không phải người được giao xử lý).',
    ],

    'by_source' => [
        'heading' => 'Số liên hệ theo nguồn',
        'description' => 'Liên hệ nhận trong kỳ (:range). Mỗi bản ghi tiếp nhận là một người liên hệ; bản trùng đã gộp vào bản ghi khác không tính lại. Gồm cả bản ghi đã ẩn danh.',
        'series' => 'Số liên hệ',
        'total' => 'Tổng',
    ],

    'conversion' => [
        'heading' => 'Tỉ lệ chuyển thành vụ việc',
        'description' => 'Trong các liên hệ nhận trong kỳ (:range), trừ bản trùng đã gộp, số đã chuyển thành vụ việc: :overall. Bản ghi còn đang xử lý vẫn tính ở mẫu số, nên một kỳ vừa qua trông thấp hơn khi việc chưa ngã ngũ.',
        'series' => 'Tỉ lệ chuyển thành vụ việc (%)',
        'row' => ':won/:total (:rate)',
        'rate' => ':rate %',
        'overall' => 'Toàn bộ',
        'no_contacts' => 'Chưa có liên hệ',
    ],

    'response_time' => [
        'heading' => 'Thời gian phản hồi lần đầu (trung vị)',
        'description' => 'Từ lúc nhận liên hệ tới lần đầu bản ghi rời trạng thái "Mới", với các liên hệ nhận trong kỳ (:range), trừ bản trùng đã gộp. :clock_note Trung vị toàn bộ: :median.',
        'clock_note' => 'Tính theo giờ đồng hồ, kể cả đêm và cuối tuần — khác ngưỡng nhắc việc, vốn tính theo giờ làm việc.',
        'series' => 'Trung vị (giờ)',
        'overall' => 'Trung vị toàn bộ',
        'responded' => 'Đã phản hồi (tính vào trung vị)',
        'unanswered' => 'Chưa phản hồi (không tính vào trung vị)',
        'source_row' => ':duration (:count bản ghi)',
        'none' => 'Chưa có bản ghi đã phản hồi',
        'duration' => [
            'days' => ':count ngày',
            'hours' => ':count giờ',
            'minutes' => ':count phút',
        ],
    ],

    'outcomes' => [
        'heading' => 'Lý do không thành vụ việc, theo nhóm',
        'description' => 'Liên hệ nhận trong kỳ (:range) đã kết thúc mà không thành vụ việc, kể cả bản trùng đã gộp. Chỉ đếm theo nhóm; lý do ghi bằng chữ không hiện ở đây.',
        'series' => 'Số bản ghi',
        'groups' => [
            'declined_conflict' => 'Văn phòng từ chối — xung đột lợi ích',
            'declined_other' => 'Văn phòng từ chối — lý do khác',
        ],
        'total' => 'Tổng',
    ],
];
