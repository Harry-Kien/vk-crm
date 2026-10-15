<?php

// làn kt (2026-10-15) — trang "Hợp đồng": kế toán nhập hợp đồng giấy đã ký cho hồ sơ có sẵn (SPEC §5,
// "Bổ sung 2026-10-15"). Mọi khoá của tệp này thuộc làn kt.
return [
    'navigation_label' => 'Hợp đồng',
    'title' => 'Hợp đồng của hồ sơ',
    'scope_note' => 'Nhập hợp đồng giấy đã ký cho hồ sơ có sẵn: bấm "Nhập hợp đồng", điền giá trị và các đợt thu, rồi "Lưu và kích hoạt" (khách đã ký) hoặc "Lưu nháp". Trang chỉ có mã hồ sơ, loại vụ việc, tên khách hàng, luật sư phụ trách và các con số; hồ sơ hạn chế không có ở đây. Hồ sơ mới vẫn do luật sư hoặc trưởng phòng mở.',
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
        'without_contract' => 'Hồ sơ chưa có hợp đồng',
        'drafts' => 'Hợp đồng còn nháp',
        'hint' => 'Bấm để mở trang Hợp đồng và nhập số tiền của hồ sơ',
    ],

    'notifications' => [
        'title' => 'Hợp đồng của hồ sơ :matter vừa được nhập',
        'drafted' => ':who đã nhập hợp đồng :contract (bản nháp) cho hồ sơ :matter (tổng :total, :count đợt) — anh/chị kiểm tra lại.',
        'activated' => ':who đã kích hoạt hợp đồng :contract của hồ sơ :matter (ngày ký :date, tổng :total, :count đợt) — anh/chị kiểm tra lại.',
        'entered' => ':who đã nhập và kích hoạt hợp đồng :contract cho hồ sơ :matter (ngày ký :date, tổng :total, :count đợt) — anh/chị kiểm tra lại.',
    ],
];
