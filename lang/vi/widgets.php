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
    // M6.5 Task 14 (`deadlines/F5`, `spec-gap-05`): mục 2 của SPEC §7.1, rơi mất giữa các
    // milestone cho tới đây — xem docblock `App\Filament\Admin\Widgets\UpcomingDeadlinesWidget`.
    'upcoming_deadlines' => [
        'heading' => 'Mốc thời hạn 7 ngày tới',
        'description' => '7 ngày tới, cộng mốc quá hạn chưa xong.',
        'empty_state' => 'Không có mốc thời hạn nào sắp tới hoặc quá hạn.',
        'columns' => [
            'due_date' => 'Ngày đến hạn',
            'code' => 'Mã hồ sơ',
            'client' => 'Khách hàng',
            'name' => 'Nội dung',
            'severity' => 'Mức độ',
            'responsible' => 'Người phụ trách',
        ],
        'open' => 'Mở hồ sơ',
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
    // M10 Task 5 (R5), SPEC §7.1 đính chính M10 — đặt ngay dưới "Tài liệu chờ duyệt". Không cột tên hay
    // số điện thoại của người liên hệ, có chủ đích (xem docblock `UnansweredIntakesWidget`).
    'unanswered_intakes' => [
        'heading' => 'Liên hệ chưa ai gọi lại',
        'description' => 'Chưa ai gọi lại sau :hours giờ làm việc kể từ lúc nhận. Đổi trạng thái bản ghi khi đã gọi lại.',
        'columns' => [
            'code' => 'Mã bản ghi',
            'source' => 'Nguồn',
            'received_at' => 'Nhận lúc',
            'waited' => 'Đã chờ (giờ làm việc)',
            'assignee' => 'Người được giao',
        ],
        'unassigned' => 'Chưa giao',
        'empty_state' => 'Không có lần liên hệ nào chờ quá hạn.',
        'open' => 'Mở bản ghi',
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
            // Làn rv: chữ này nằm sau nút ⓘ cạnh công tắc, không còn in dưới công tắc.
            'by_count_help' => 'Bật để đếm theo số vụ việc thay vì tổng giá trị. Chỉ đổi con số được đo trên biểu đồ "Cơ cấu theo lĩnh vực", không thêm trục nào khác.',
        ],
        // làn rv (2026-10-15): hàng ô số đầu trang — RevenueKpiWidget.
        'kpi' => [
            'comparison' => 'so với :range',
            'collected' => [
                'label' => 'Đã thu',
                'explanation' => 'Tiền về trong kỳ (:range), tính theo ngày tiền về, kể cả tiền đã thu của hợp đồng sau đó bị huỷ; đã gồm thuế GTGT nếu hợp đồng có thuế. Bộ lọc luật sư: luật sư phụ trách lúc tiền về. So với cùng khoảng đã qua của kỳ liền trước (kỳ "Tuỳ chọn": khoảng dài bằng nhau ngay trước đó); "—" khi kỳ trước không có đồng nào.',
            ],
            'outstanding' => [
                'label' => 'Còn phải thu',
                // Rà soát cuối làn rv, I1: ô này không theo Kỳ — dòng phụ in trên ô phải nói vậy.
                'detail' => ':count đợt chưa thu xong, tại hôm nay',
                'explanation' => 'Tại hôm nay: phần còn lại chưa thu của mọi đợt trên các hợp đồng đang hiệu lực, kể cả đợt đã quá hạn — cùng danh sách với trang "Công nợ". Không phụ thuộc kỳ đang lọc. Bộ lọc luật sư: luật sư phụ trách hiện tại của vụ. Bấm ô để mở trang "Công nợ" — chỉ khi không lọc theo luật sư hay lĩnh vực (trang đó không có hai bộ lọc này).',
            ],
            'overdue' => [
                'label' => 'Quá hạn',
                'detail' => ':count đợt quá hạn, tại hôm nay',
                // Rà soát cuối làn rv, I2: ô "Đã thu" chỉ đếm tiền về TRONG kỳ, nên phần đã thu của đợt
                // quá hạn có thể không nằm ở ô nào — không hứa nó nằm ở "Đã thu".
                'explanation' => 'Tại hôm nay: phần còn lại chưa thu của các đợt đã qua hạn thanh toán, kể cả đợt đã thu một phần — đợt đó chỉ tính phần còn lại, phần đã thu không tính ở ô này. Không phụ thuộc kỳ đang lọc. Bộ lọc luật sư: luật sư phụ trách hiện tại của vụ. Bấm ô để mở trang "Công nợ" — chỉ khi không lọc theo luật sư hay lĩnh vực (trang đó không có hai bộ lọc này).',
            ],
            'signed' => [
                'label' => 'Hợp đồng ký mới',
                'value' => ':count hợp đồng',
                'detail' => 'Tổng giá trị :amount',
                'explanation' => 'Hợp đồng ký trong kỳ (:range), tính theo ngày ký hợp đồng, chỉ hợp đồng đang hiệu lực hoặc đã hoàn tất (không tính hợp đồng đã huỷ). Bộ lọc luật sư: luật sư phụ trách hiện tại của vụ. Số hợp đồng so với cùng khoảng đã qua của kỳ liền trước.',
            ],
        ],
        // Làn rv: mỗi biểu đồ một tiêu đề ngắn, MỘT dòng phụ (`description`), còn cách tính nằm ở
        // `explanation` — chữ của nút ⓘ cạnh tiêu đề (không xoá, chỉ giấu đi).
        'donut' => [
            'heading' => 'Tỉ lệ thu tiền',
            'description' => 'Hợp đồng ký :range',
            'explanation' => 'Việc đã ký trong kỳ (:range) — tính theo ngày ký hợp đồng, chỉ hợp đồng đang hiệu lực hoặc đã hoàn tất. Ba lát tính TẠI HÔM NAY. Bộ lọc luật sư mang hai nghĩa: "Đã thu" theo luật sư phụ trách lúc tiền về; "còn phải thu"/"quá hạn" theo luật sư phụ trách hiện tại của vụ. Tiền đã thu của hợp đồng sau đó bị huỷ không tính ở đây (hai biểu đồ doanh thu vẫn tính, vì tiền đó đã về).',
            'description_lawyer_filtered' => 'Đang lọc theo một luật sư cụ thể: hai tập vụ việc trên có thể khác nhau sau một lần bàn giao — xem lại nghĩa của từng lát ở trên trước khi so sánh với tổng đã ký.',
            'slices' => [
                'collected' => 'Đã thu',
                'not_yet_due' => 'Còn phải thu, chưa tới hạn',
                'overdue' => 'Quá hạn',
            ],
            'table' => [
                'signed_total' => 'Tổng giá trị đã ký trong kỳ (hợp đồng còn hiệu lực/đã hoàn tất)',
                'written_off' => 'Đã miễn (phần còn lại thật sự bị xoá)',
                'cancelled_total' => 'Hợp đồng đã huỷ trong kỳ (không tính vào công nợ)',
                'collected' => 'Đã thu',
                'not_yet_due' => 'Còn phải thu, chưa tới hạn',
                // Lượt rà soát cuối M9, I1: lát này là phần CÒN LẠI của đợt quá hạn, kể cả đợt đã
                // thu một phần — phần đã thu nằm ở dòng "Đã thu".
                'overdue' => 'Quá hạn (phần còn lại chưa thu, kể cả đợt đã thu một phần)',
            ],
        ],
        'over_time' => [
            // Làn rv: tiêu đề theo cách chia cột đang chọn — "Doanh thu theo tháng/quý/năm".
            'heading' => 'Doanh thu theo :unit',
            'heading_units' => [
                'month' => 'tháng',
                'quarter' => 'quý',
                'year' => 'năm',
            ],
            'description' => 'Tiền về :range',
            // 2026-10-21: biểu đồ xu hướng — khoảng vẽ khác kỳ đầu trang (RevenueFilters::trendWindow()).
            'explanation' => 'Biểu đồ xu hướng: tiền về từ :range, chia theo tháng hoặc quý từ đầu năm, hoặc 5 năm gần nhất khi xem theo năm; kỳ chưa có tiền hiện cột 0. Các ô số đầu trang chỉ tính kỳ đang chọn (:period). Số tiền tính theo ngày tiền về, kể cả tiền đã thu của hợp đồng sau đó bị huỷ; số tiền đã gồm thuế GTGT nếu hợp đồng có thuế. Bộ lọc luật sư: luật sư phụ trách lúc tiền về.',
            'filter_month' => 'Theo tháng',
            'filter_quarter' => 'Theo quý',
            'filter_year' => 'Theo năm',
            // Lượt rà soát cuối M9, M4: nhãn một cột khi xem theo quý.
            'quarter_label' => 'Quý :quarter/:year',
            'series' => 'Tiền đã thu',
        ],
        'by_stage' => [
            'heading' => 'Tiền thu theo giai đoạn',
            'description' => 'Tiền về :range',
            'explanation' => 'Tiền về trong kỳ (:range) — tính theo ngày tiền về, gộp theo đợt/giai đoạn kích hoạt đợt, kể cả tiền đã thu của hợp đồng sau đó bị huỷ; số tiền đã gồm thuế GTGT nếu hợp đồng có thuế. Bộ lọc luật sư: luật sư phụ trách lúc tiền về.',
            'bucket_label' => ':type — :stage',
            'on_signing_bucket' => 'Tạm ứng khi ký hợp đồng (mọi loại vụ việc)',
            'due_date_bucket' => 'Đến hạn theo ngày cụ thể, không theo giai đoạn (mọi loại vụ việc)',
            'unknown_stage_bucket' => 'Giai đoạn không còn trong cấu hình',
            'series' => 'Đã thu',
        ],
        'mix_by_practice_area' => [
            'heading' => 'Cơ cấu theo lĩnh vực',
            'description_amount' => 'Hợp đồng ký :range · theo giá trị',
            'description_count' => 'Hợp đồng ký :range · theo số vụ',
            'explanation' => 'Việc đã ký trong kỳ (:range) — tính theo ngày ký hợp đồng, chỉ hợp đồng đang hiệu lực hoặc đã hoàn tất (không tính hợp đồng đã huỷ). Bộ lọc luật sư: luật sư phụ trách hiện tại của vụ. Công tắc "đếm theo số vụ" đổi số vụ/số tiền được đo, xếp giảm dần theo giá trị đó.',
            'series_amount' => 'Giá trị đã ký',
            'series_count' => 'Số vụ',
        ],
        'load_per_lawyer' => [
            'heading' => 'Tải theo luật sư',
            'description' => 'Số vụ đang mở, tại hôm nay',
            'explanation' => 'Ảnh chụp HIỆN TẠI: số vụ đang mở của mỗi luật sư phụ trách hiện tại. KHÔNG phụ thuộc bộ lọc thời gian của trang. Bộ lọc luật sư (luật sư phụ trách hiện tại của vụ) thu hẹp còn đúng một cột.',
            'series' => 'Số vụ đang mở',
        ],
        'closed_with_balance' => [
            'heading' => 'Hồ sơ đã đóng còn nợ',
            'description' => 'Mọi thời điểm, không theo kỳ',
            'explanation' => 'KHÔNG phụ thuộc bộ lọc thời gian hay công tắc đếm — luôn liệt kê mọi vụ việc anh/chị được xem đã kết thúc mà còn dư nợ. Bộ lọc luật sư (luật sư phụ trách hiện tại của vụ) và lĩnh vực vẫn thu hẹp phạm vi vụ việc.',
            'columns' => [
                'matter_code' => 'Mã hồ sơ',
                'client' => 'Khách hàng',
                'closed_at' => 'Kết thúc ngày',
                'outstanding' => 'Còn phải thu',
            ],
            'empty_heading' => 'Không có hồ sơ đã kết thúc nào còn công nợ.',
        ],
    ],
    // làn rv (2026-10-15): nút ⓘ dùng chung (resources/views/components/info-tip.blade.php).
    'info_tip' => [
        'label' => 'Giải thích',
        'label_for' => 'Giải thích: :subject',
    ],
    // 2026-10-10: ô chọn dạng biểu đồ (trait HasSwitchableChartKind) — nhãn cho trình đọc màn hình.
    'chart_kind' => [
        'label' => 'Dạng biểu đồ',
    ],
];
