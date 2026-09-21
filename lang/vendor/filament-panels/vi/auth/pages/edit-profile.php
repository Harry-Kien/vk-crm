<?php

/**
 * Hai khoá bản `vi` bundled còn thiếu cho giới hạn tần suất của trang hồ sơ cá nhân. Panel
 * `portal` không đăng ký trang này (SPEC §8.1), nên nó thuộc về panel nội bộ.
 *
 * Chỉ chứa khoá còn thiếu: ghi đè của Laravel TRỘN đệ quy chứ không thay thế cả tệp.
 */
return [

    'notifications' => [

        'throttled' => [
            'title' => 'Bạn thao tác quá nhanh. Xin thử lại sau :seconds giây.',
            'body' => 'Xin thử lại sau :seconds giây.',
        ],

    ],

];
