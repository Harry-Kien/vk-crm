<?php

/*
 * Sửa sau kiểm tra nghiệp vụ toàn hệ thống (làn fb, 2026-10-09) — tab "Hợp đồng và thanh toán":
 * soạn hợp đồng mới sau khi huỷ (A1), miễn và bỏ miễn đợt (A2), chọn đúng khoản thu để huỷ (A3).
 * Tệp riêng để không chạm khối của làn khác trong lang/vi/billing.php.
 */
return [
    'errors' => [
        'instalment_not_waived' => 'Không thể bỏ miễn đợt ":name": đợt này đang ở trạng thái ":status", không phải "đã miễn".',
    ],

    'waive' => [
        'description' => 'Đợt ":name" — giá trị :amount, đã thu :collected. Nếu miễn, văn phòng thôi đòi :written_off. Miễn nhầm thì bấm "Bỏ miễn" trên đúng dòng này để đưa đợt về chờ thu.',
    ],

    'unwaive' => [
        'label' => 'Bỏ miễn',
        'heading' => 'Bỏ miễn đợt thanh toán',
        'description' => 'Đợt ":name" (:amount) quay lại chờ thu, theo đúng hạn đã có. Lý do miễn trước đó: :previous_reason. Lần miễn cũ và lần bỏ miễn này đều giữ trong nhật ký.',
        'success' => 'Đã bỏ miễn — đợt quay lại chờ thu.',
    ],

    'void' => [
        'heading' => 'Huỷ một khoản thu',
        'description' => 'Chọn đúng khoản cần huỷ. Mặc định là khoản GHI VÀO HỆ THỐNG gần nhất (theo lúc ghi, không theo ngày tiền về). Khoản bị huỷ vẫn ở lại trong lịch sử, kèm lý do.',
        'option' => ':date — :amount (:method), ghi lúc :recorded_at',
        'option_reference' => ':date — :amount (:method), mã :reference, ghi lúc :recorded_at',
        'option_voided' => ':date — :amount (:method) — đã huỷ',
        'field' => 'Khoản thu cần huỷ',
    ],
];
