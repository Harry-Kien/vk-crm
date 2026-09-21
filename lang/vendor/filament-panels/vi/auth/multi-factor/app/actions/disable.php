<?php

/**
 * Khoá bản `vi` bundled của Filament còn thiếu cho xác thực hai bước bằng ứng dụng (TOTP). Không
 * dùng ở cổng khách hàng — cổng dùng mã qua email — nhưng SPEC §10.7 đòi 2FA cho toàn bộ tài
 * khoản nội bộ, nên đường này sẽ sống khi mốc đó tới.
 *
 * Chỉ chứa khoá còn thiếu: ghi đè của Laravel TRỘN đệ quy chứ không thay thế cả tệp.
 */
return [

    'modal' => [

        'form' => [

            'code' => [
                'messages' => [
                    'rate_limited' => 'Bạn đã thử quá nhiều lần. Xin thử lại sau.',
                ],
            ],

            'recovery_code' => [
                'messages' => [
                    'rate_limited' => 'Bạn đã thử quá nhiều lần. Xin thử lại sau.',
                ],
            ],

        ],

    ],

];
