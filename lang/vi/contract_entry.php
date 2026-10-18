<?php

// làn kt (2026-10-15) — trang "Hợp đồng": kế toán nhập hợp đồng giấy đã ký cho hồ sơ có sẵn (SPEC §5,
// "Bổ sung 2026-10-15"). Mọi khoá của tệp này thuộc làn kt.
return [
    'navigation_label' => 'Hợp đồng',
    'title' => 'Hợp đồng của hồ sơ',
    'scope_note' => 'Nhập hợp đồng giấy đã ký cho hồ sơ có sẵn: bấm "Nhập hợp đồng", điền giá trị và các đợt thu; khách đã ký thì bật "Khách đã ký — kích hoạt luôn" rồi bấm "Lưu và kích hoạt", chưa chắc thì để tắt và bấm "Lưu nháp". Trang chỉ có mã hồ sơ, loại vụ việc, tên khách hàng, luật sư phụ trách và các con số. Hồ sơ hạn chế chỉ hiện với quản trị viên và luật sư phụ trách hồ sơ đó — kế toán không thấy. Hồ sơ mới vẫn do luật sư hoặc trưởng phòng mở.',
    'empty_heading' => 'Không có hồ sơ nào khớp bộ lọc',

    'columns' => [
        'matter_code' => 'Mã hồ sơ',
        'matter_type' => 'Loại vụ việc',
        'client' => 'Khách hàng',
        'lead_lawyer' => 'Luật sư phụ trách',
        'contract_code' => 'Số hợp đồng',
        'contract_status' => 'Hợp đồng',
        'total_amount' => 'Tổng giá trị',
        'collected' => 'Đã thu',
        'outstanding' => 'Còn phải thu',
    ],

    'status' => [
        'none' => 'Chưa có hợp đồng',
        // làn fa3 (2026-10-16, mục 4): bộ lọc mặc định — ContractEntry::whereNeedsContract().
        'to_enter' => 'Cần nhập hợp đồng',
        // hết khối làn fa3
    ],

    'filters' => [
        'contract_status' => 'Trạng thái hợp đồng',
        'matter_type' => 'Loại vụ việc',
        'lead_lawyer' => 'Luật sư phụ trách',
    ],

    'actions' => [
        'enter' => 'Nhập hợp đồng',
        'enter_heading' => 'Nhập hợp đồng đã ký',
        'enter_description' => 'Số hợp đồng (HD-năm-số) do hệ thống cấp khi lưu. Tổng các đợt phải bằng đúng giá trị hợp đồng thì mới kích hoạt được.',
        'save_and_activate' => 'Khách đã ký — kích hoạt luôn',
        'save_and_activate_help' => 'Bật: lưu và kích hoạt trong một lần (cần ngày ký, tổng các đợt khớp giá trị). Tắt: chỉ lưu nháp, kích hoạt sau.',
        // Nút gửi của hộp thoại "Nhập hợp đồng" đổi theo công tắc trên (rà soát cuối F1).
        'submit_activate' => 'Lưu và kích hoạt',
        'submit_draft' => 'Lưu nháp',
        'enter_success' => 'Đã lưu bản nháp hợp đồng',
        'enter_activated_success' => 'Đã nhập và kích hoạt hợp đồng',
        'update_draft' => 'Sửa bản nháp',
        'delete_draft' => 'Xoá bản nháp',
        'activate' => 'Kích hoạt (đã ký)',
        'record_payment' => 'Ghi khoản thu',
        'open_contracts' => 'Nhập hợp đồng cho hồ sơ',
    ],

    'widget' => [
        'heading' => 'Hợp đồng cần nhập',
        // làn fa3 (2026-10-16, mục 4): ô đếm đúng luật "Cần nhập" của trang (thay ô "Hồ sơ chưa có hợp đồng").
        'to_enter' => 'Hồ sơ cần nhập hợp đồng',
        'to_enter_hint' => 'Hồ sơ đang mở chưa có hợp đồng, hoặc hợp đồng đã huỷ',
        // hết khối làn fa3
        'drafts' => 'Hợp đồng còn nháp',
        'hint' => 'Bấm để mở trang Hợp đồng và nhập số tiền của hồ sơ',
    ],

    'notifications' => [
        // Một tiêu đề cho mỗi việc (đợt polish, m2) — cùng ba khoá với câu thân bên dưới.
        'titles' => [
            'drafted' => 'Hợp đồng của hồ sơ :matter vừa được nhập (bản nháp)',
            'activated' => 'Hợp đồng của hồ sơ :matter vừa được kích hoạt',
            'entered' => 'Hợp đồng của hồ sơ :matter vừa được nhập và kích hoạt',
            // làn fa3 (2026-10-16, mục 2 + 5)
            'reverted' => 'Hợp đồng của hồ sơ :matter vừa được đưa về bản nháp',
            'draft_updated' => 'Bản nháp hợp đồng của hồ sơ :matter vừa được sửa',
            'draft_deleted' => 'Bản nháp hợp đồng của hồ sơ :matter vừa bị xoá',
            // hết khối làn fa3
        ],
        // Nút của dòng chuông: mở tab "Hợp đồng và thanh toán" của vụ để kiểm tra lại (đợt polish, m9).
        'open_tab' => 'Mở tab Hợp đồng và thanh toán',
        'drafted' => ':who đã nhập hợp đồng :contract (bản nháp) cho hồ sơ :matter (tổng :total, :count đợt) — anh/chị kiểm tra lại.',
        'activated' => ':who đã kích hoạt hợp đồng :contract của hồ sơ :matter (ngày ký :date, tổng :total, :count đợt) — anh/chị kiểm tra lại.',
        'entered' => ':who đã nhập và kích hoạt hợp đồng :contract cho hồ sơ :matter (ngày ký :date, tổng :total, :count đợt) — anh/chị kiểm tra lại.',
        // làn fa3 (2026-10-16, mục 2 + 5): lý do không chép vào chuông (nằm ở nhật ký hoạt động).
        'reverted' => ':who đã đưa hợp đồng :contract của hồ sơ :matter về bản nháp (tổng :total, :count đợt) để sửa một lần kích hoạt nhầm — lý do ở nhật ký; anh/chị kiểm tra lại khi hợp đồng được kích hoạt lại.',
        'draft_updated' => ':who đã sửa bản nháp hợp đồng :contract của hồ sơ :matter (nay tổng :total, :count đợt) — anh/chị kiểm tra lại.',
        'draft_deleted' => ':who đã xoá bản nháp hợp đồng :contract của hồ sơ :matter (tổng :total, :count đợt) — anh/chị kiểm tra lại.',
        // hết khối làn fa3
    ],
];
