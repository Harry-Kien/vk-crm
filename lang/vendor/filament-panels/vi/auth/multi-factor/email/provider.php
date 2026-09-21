<?php

/**
 * Bản `vi` bundled của Filament thiếu đúng một khoá ở tệp này: câu báo khi nút "Gửi lại mã" bị
 * bộ đếm riêng của `EmailAuthentication::sendCode()` chặn (2 lần / 60 giây). Khách hàng bấm gửi
 * lại hai lần liên tiếp là gặp nó, nên nó không phải một khoá hiếm.
 *
 * Chỉ chứa khoá còn thiếu: ghi đè của Laravel TRỘN đệ quy chứ không thay thế cả tệp.
 */
return [

    'login_form' => [

        'code' => [

            'actions' => [

                'resend' => [

                    'notifications' => [

                        'throttled' => [
                            'title' => 'Anh/chị vừa xin mã xong. Xin đợi khoảng một phút rồi bấm gửi lại lần nữa.',
                        ],

                    ],

                ],

            ],

        ],

    ],

];
