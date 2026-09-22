<?php

/**
 * Hai khoá bản `vi` bundled còn thiếu: câu báo khi địa chỉ email mới đã bị tài khoản khác lấy
 * mất trong lúc liên kết xác minh còn chờ. Đường đổi email chưa được bật ở mốc này, nhưng một
 * khoá thiếu vẫn là một câu tiếng Anh chờ sẵn ngày nó được bật.
 *
 * Chỉ chứa khoá còn thiếu: ghi đè của Laravel TRỘN đệ quy chứ không thay thế cả tệp.
 */
return [

    'notifications' => [

        'unavailable' => [
            'title' => 'Địa chỉ email đó không còn dùng được nữa.',
            'body' => 'Một tài khoản khác đã dùng địa chỉ này trong lúc liên kết xác minh của bạn còn chờ.',
        ],

    ],

];
