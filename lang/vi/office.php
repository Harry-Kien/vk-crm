<?php

/**
 * M7 Task 10 — trang "Thông tin văn phòng" (`App\Filament\Admin\Pages\OfficeProfilePage`) và Action
 * lưu nó (`App\Actions\Settings\UpdateOfficeProfile`). Tệp riêng của M7 để không đụng tệp ngôn ngữ
 * chung của các làn song song.
 */
return [
    'navigation_label' => 'Thông tin văn phòng',
    'page_title' => 'Thông tin văn phòng',

    'intro' => 'Những thông tin này in ở chân mọi thư văn phòng gửi đi, ở chân mục lục của gói bàn giao hồ sơ, và trên cổng khách hàng. Thư và tệp sinh ra từ lúc lưu mang giá trị mới, kể cả thư đang chờ gửi; thư đã gửi thì không đổi.',
    'blank_hint' => 'Để trống một ô thì hệ thống dùng giá trị trong cấu hình máy chủ (tệp .env). Bốn thông tin pháp lý còn trống ở cả hai nơi thì không in dòng đó.',

    'sections' => [
        'legal' => 'Thông tin pháp lý',
        'contact' => 'Liên hệ',
    ],

    'fields' => [
        'legal_name' => [
            'label' => 'Tên pháp lý',
        ],
        'tax_code' => [
            'label' => 'Mã số thuế',
            'hint' => '10 chữ số, hoặc 13 chữ số dạng 0123456789-001.',
        ],
        'bar_association' => [
            'label' => 'Đoàn Luật sư',
        ],
        'licence_number' => [
            'label' => 'Số Giấy đăng ký hoạt động',
        ],
        'office_address' => [
            'label' => 'Địa chỉ trụ sở',
        ],
        'hotline' => [
            'label' => 'Hotline',
            'hint' => 'Số điện thoại Việt Nam. Hệ thống tự bỏ khoảng trắng, dấu chấm và +84.',
        ],
        'zalo' => [
            'label' => 'Zalo',
            'hint' => 'Đường dẫn đầy đủ, ví dụ https://zalo.me/0832270898.',
        ],
        'website' => [
            'label' => 'Website',
        ],
        'reply_to' => [
            'label' => 'Email liên hệ',
            'hint' => 'Khách và nhân sự bấm "Trả lời" trên thư của hệ thống thì thư đi tới hộp này.',
        ],
    ],

    // Gợi ý trong ô trống: giá trị cấu hình mà hệ thống đang dùng thay.
    'placeholder_configured' => 'Đang dùng: :value',
    'placeholder_none' => 'Chưa có',

    'validation' => [
        'tax_code' => 'Mã số thuế gồm 10 chữ số, hoặc 13 chữ số dạng 0123456789-001.',
        'hotline' => 'Hotline phải là một số điện thoại Việt Nam (8–10 chữ số sau số 0 đầu).',
    ],

    'submit' => 'Lưu',

    'notifications' => [
        'saved' => 'Đã lưu thông tin văn phòng.',
        'unchanged' => 'Không có thông tin nào thay đổi.',
    ],
];
