<?php

/**
 * Nhãn tiếng Việt cho resource Client (SPEC §7.4). Danh sách rút gọn theo
 * ClientPolicy::view — ai không có client.manage chỉ thấy khách của vụ việc mình xem được.
 */
return [
    'label' => 'Khách hàng',
    'plural_label' => 'Khách hàng',
    'fields' => [
        'code' => 'Mã khách hàng',
        'type' => 'Loại',
        'name' => 'Tên / Tên tổ chức',
        'id_number' => 'Số CCCD / Mã số thuế',
        'phone' => 'Điện thoại',
        'email' => 'Email',
        'address' => 'Địa chỉ',
        'representative_name' => 'Người đại diện',
        'note' => 'Ghi chú nội bộ',
        'matters_count' => 'Số vụ việc',
    ],
    'note_hint' => 'Chỉ nội bộ, không bao giờ hiện cho khách trên portal.',
    'delete_blocked_open_matters' => 'Không thể xoá: khách hàng còn :count vụ việc đang mở.',
    // M9 Task 5: kể cả khi mọi vụ việc đã đóng, còn dư nợ trên hợp đồng đang có hiệu lực vẫn chặn
    // xoá mềm khách hàng — xem ClientPolicy::delete().
    'delete_blocked_outstanding_balance' => 'Không thể xoá: khách hàng còn dư nợ :amount trên :count đợt thanh toán của hợp đồng đang có hiệu lực (kể cả vụ việc đã đóng). Thu nốt hoặc miễn các đợt còn lại (kèm lý do) trước khi xoá.',
];
