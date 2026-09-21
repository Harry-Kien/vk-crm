<?php

/**
 * Bốn khoá bản `vi` bundled còn thiếu cho hộp thoại xuất dữ liệu (SPEC §10.6 coi xuất dữ liệu là
 * một sự kiện phải ghi nhật ký, nên màn hình này sẽ được dùng thật).
 *
 * Chỉ chứa khoá còn thiếu: ghi đè của Laravel TRỘN đệ quy chứ không thay thế cả tệp.
 */
return [

    'modal' => [

        'form' => [

            'columns' => [

                'actions' => [

                    'select_all' => [
                        'label' => 'Chọn tất cả',
                    ],

                    'deselect_all' => [
                        'label' => 'Bỏ chọn tất cả',
                    ],

                ],

            ],

        ],

    ],

    'notifications' => [

        'no_columns' => [
            'title' => 'Chưa chọn cột nào',
            'body' => 'Xin chọn ít nhất một cột để xuất.',
        ],

    ],

];
