<?php

/**
 * Nhãn tiếng Việt cho các widget trang chủ panel admin (SPEC §7.1). Mục 1 và 6 làm ở M3, mục 3
 * và 4 ở M4 (cần bảng `matter_checklist_items` có dữ liệu thật); mục 2, 5 và 7 cần mốc thời hạn,
 * `stage_log_views` và heartbeat — M6.
 */
return [
    'stale_matters' => [
        'heading' => 'Hồ sơ quá hạn cập nhật',
        'description' => 'Chưa cập nhật cho khách quá 14 ngày.',
        'columns' => [
            'code' => 'Mã hồ sơ',
            'client' => 'Khách hàng',
            'title' => 'Tiêu đề',
            'stage' => 'Giai đoạn',
            'lead_lawyer' => 'Luật sư phụ trách',
            'last_client_update_at' => 'Cập nhật gần nhất cho khách',
        ],
        'empty_state' => 'Không có hồ sơ nào quá hạn cập nhật.',
    ],
    'pending_checklist_reviews' => [
        'heading' => 'Tài liệu chờ duyệt',
        'description' => 'Khách đã nộp, chưa ai xem.',
        'columns' => [
            'code' => 'Mã hồ sơ',
            'client' => 'Khách hàng',
            'item' => 'Giấy tờ',
            'submitted_at' => 'Khách nộp lúc',
        ],
        'empty_state' => 'Không có giấy tờ nào đang chờ duyệt.',
        'never_submitted' => 'Chưa có tệp nào',
        'open' => 'Mở danh mục hồ sơ',
    ],
    'matters_missing_documents' => [
        'heading' => 'Hồ sơ thiếu giấy tờ quá 14 ngày',
        'description' => 'Hồ sơ đang tắc vì khách chưa nộp.',
        'columns' => [
            'code' => 'Mã hồ sơ',
            'client' => 'Khách hàng',
            'title' => 'Tiêu đề',
            'lead_lawyer' => 'Luật sư phụ trách',
            'outstanding' => 'Giấy tờ còn thiếu',
            'missing_since' => 'Thiếu từ',
        ],
        'empty_state' => 'Không có hồ sơ nào thiếu giấy tờ quá 14 ngày.',
        'open' => 'Mở danh mục hồ sơ',
    ],
    'system_health' => [
        'never_ran' => 'Hệ thống nhắc việc chưa từng chạy',
        'never_ran_hint' => 'Chưa có dòng lịch tự động nào chạy trên máy chủ này, nên hệ thống chưa nhắc được mốc thời hạn nào. Nhờ người quản trị kiểm tra lại dòng cron.',
        'stale' => 'Hệ thống nhắc việc đã ngừng chạy',
        'stale_hint' => 'Lần chạy gần nhất là :at, quá :minutes phút trước. Trong lúc này hệ thống không nhắc mốc thời hạn và không gửi thư nào. Nhờ người quản trị kiểm tra lại dòng cron.',
    ],
    'matter_counts' => [
        'total' => 'Tổng số hồ sơ',
        'total_hint' => 'Toàn bộ hồ sơ anh/chị được xem.',
        'open' => 'Đang xử lý',
        'open_hint' => 'Chưa đóng hồ sơ.',
        'closed' => 'Đã kết thúc',
        'closed_hint' => 'Đã đóng hồ sơ.',
        'opened_this_month' => 'Mở trong tháng này',
        'opened_this_month_hint' => 'Tính từ ngày đầu tháng.',
    ],
    'matters_by_stage' => [
        'heading' => 'Thống kê nhanh',
        'description' => 'Số vụ việc đang mở theo giai đoạn.',
        // Hai loại vụ việc có thể đặt trùng nhãn giai đoạn ("Chuẩn bị hồ sơ" chẳng hạn); nhãn cột
        // vì vậy luôn kèm tên loại, nếu không hai cột khác nhau trông y hệt nhau.
        'stage_label' => ':type — :stage',
    ],

    // Trang doanh thu (M9 Task 9) — sáu widget, KHÔNG trên trang chủ §7.1.
    'revenue_dashboard' => [
        'navigation_label' => 'Doanh thu',
        'title' => 'Doanh thu',
        'number_table_toggle' => 'Xem bảng số',
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
            'lawyer' => 'Luật sư phụ trách',
            'practice_area' => 'Lĩnh vực',
            'by_count' => 'Đếm theo số vụ',
            'by_count_help' => 'Bật để đếm theo số vụ việc thay vì tổng giá trị. Chỉ đổi con số được đo trên biểu đồ "Cơ cấu vụ việc theo lĩnh vực", không thêm trục nào khác.',
        ],
        'donut' => [
            'heading' => 'Đã thu / còn phải thu / quá hạn',
            'description' => 'Việc đã ký trong kỳ (:range), hợp đồng đang hiệu lực hoặc đã hoàn tất — theo contracts.signed_at. Ba lát tính TẠI HÔM NAY. Bộ lọc luật sư mang hai nghĩa: "Đã thu" theo payments.attributed_lawyer_id (luật sư lúc thu); "còn phải thu"/"quá hạn" theo matters.lead_lawyer_id (luật sư phụ trách hiện tại).',
            'description_lawyer_filtered' => 'Đang lọc theo một luật sư cụ thể: hai tập vụ việc trên có thể khác nhau sau một lần bàn giao — xem lại nghĩa của từng lát ở trên trước khi so sánh với tổng đã ký.',
            'slices' => [
                'collected' => 'Đã thu: :amount',
                'not_yet_due' => 'Còn phải thu, chưa tới hạn: :amount',
                'overdue' => 'Quá hạn: :amount',
            ],
            'table' => [
                'signed_total' => 'Tổng giá trị đã ký trong kỳ (hợp đồng còn hiệu lực/đã hoàn tất)',
                'written_off' => 'Đã miễn (phần còn lại thật sự bị xoá)',
                'cancelled_total' => 'Hợp đồng đã huỷ trong kỳ (không tính vào công nợ)',
                'collected' => 'Đã thu',
                'not_yet_due' => 'Còn phải thu, chưa tới hạn',
                'overdue' => 'Quá hạn',
            ],
        ],
        'over_time' => [
            'heading' => 'Doanh thu theo thời gian',
            'description' => 'Tiền về trong kỳ (:range) — theo payments.paid_on. Bộ lọc luật sư: luật sư phụ trách LÚC THU (payments.attributed_lawyer_id).',
            'filter_month' => 'Theo tháng',
            'filter_quarter' => 'Theo quý',
            'filter_year' => 'Theo năm',
            'series' => 'Doanh thu đã thu',
        ],
        'by_stage' => [
            'heading' => 'Doanh thu đã thu theo đợt/giai đoạn',
            'description' => 'Tiền về trong kỳ (:range) — theo payments.paid_on, gộp theo giai đoạn kích hoạt đợt. Bộ lọc luật sư: luật sư phụ trách LÚC THU (payments.attributed_lawyer_id).',
            'bucket_label' => ':type — :stage',
            'on_signing_bucket' => 'Tạm ứng khi ký hợp đồng (mọi loại vụ việc)',
            'due_date_bucket' => 'Đến hạn theo ngày cụ thể, không theo giai đoạn (mọi loại vụ việc)',
            'series' => 'Đã thu',
        ],
        'mix_by_practice_area' => [
            'heading' => 'Cơ cấu vụ việc theo lĩnh vực',
            'description' => 'Việc đã ký trong kỳ (:range) — theo contracts.signed_at. Bộ lọc luật sư: luật sư phụ trách HIỆN TẠI (matters.lead_lawyer_id). Công tắc "đếm theo số vụ" đổi số vụ/số tiền được đo, xếp giảm dần theo giá trị đó.',
            'series_amount' => 'Giá trị đã ký',
            'series_count' => 'Số vụ',
        ],
        'load_per_lawyer' => [
            'heading' => 'Tải theo luật sư',
            'description' => 'Ảnh chụp HIỆN TẠI: số vụ đang mở của mỗi luật sư phụ trách hiện tại (matters.lead_lawyer_id). KHÔNG phụ thuộc bộ lọc thời gian của trang. Bộ lọc luật sư (matters.lead_lawyer_id) thu hẹp còn đúng một cột.',
            'series' => 'Số vụ đang mở',
        ],
        'closed_with_balance' => [
            'heading' => 'Hồ sơ đã kết thúc còn công nợ',
            'description' => 'KHÔNG phụ thuộc bộ lọc thời gian hay công tắc đếm — luôn liệt kê mọi vụ việc anh/chị được xem đã kết thúc mà còn dư nợ. Bộ lọc luật sư (matters.lead_lawyer_id) và lĩnh vực vẫn thu hẹp phạm vi vụ việc.',
            'columns' => [
                'matter_code' => 'Mã hồ sơ',
                'client' => 'Khách hàng',
                'closed_at' => 'Kết thúc ngày',
                'outstanding' => 'Còn phải thu',
            ],
            'empty_heading' => 'Không có hồ sơ đã kết thúc nào còn công nợ.',
        ],
    ],
];
