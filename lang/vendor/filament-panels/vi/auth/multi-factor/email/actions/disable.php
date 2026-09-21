<?php

/**
 * Hai khoá bản `vi` bundled của Filament còn thiếu. Màn hình này thuộc trang hồ sơ cá nhân, thứ
 * panel `portal` cố ý KHÔNG đăng ký (SPEC §8.1 không cho khách tắt mã đăng nhập), nên nó chỉ
 * xuất hiện ở panel nội bộ khi M8 bật 2FA cho nhân sự theo SPEC §10.7. Dịch sẵn để lần đó không
 * phải nhớ lại.
 *
 * Chỉ chứa khoá còn thiếu: ghi đè của Laravel TRỘN đệ quy chứ không thay thế cả tệp.
 */
return [

    'modal' => [

        'form' => [

            'code' => [

                'actions' => [

                    'resend' => [

                        'notifications' => [

                            'throttled' => [
                                'title' => 'Bạn vừa xin mã xong. Xin đợi một chút rồi bấm gửi lại lần nữa.',
                            ],

                        ],

                    ],

                ],

                'messages' => [
                    'rate_limited' => 'Bạn đã thử quá nhiều lần. Xin thử lại sau.',
                ],

            ],

        ],

    ],

];
