<?php

it('exposes project settings with safe defaults', function () {
    expect(config('vkcrm.admin_domain'))->toBeNull()
        ->and(config('vkcrm.portal_domain'))->toBeNull()
        ->and(config('vkcrm.matter_code_prefix'))->toBe('VK')
        ->and(config('vkcrm.upload_max_mb'))->toBe(20)
        ->and(config('vkcrm.retention_years'))->toBe(10)
        ->and(config('vkcrm.client_access_days'))->toBe(90)
        ->and(config('vkcrm.clamav.enabled'))->toBeFalse()
        // `brand_color`/`BRAND_COLOR` đã bị gỡ (Minor, fix round 4): nó không còn nơi tiêu thụ
        // nào kể từ khi `brand.primary_ramp` và `brand.colors` nắm toàn bộ màu của hai panel, mà
        // `.env.example` vẫn quảng cáo nó — một nút bấm không nối vào đâu cả là lời hứa sai với
        // người vận hành. Màu thương hiệu thật giờ nằm ở một chỗ duy nhất.
        ->and(config('vkcrm'))->not->toHaveKey('brand_color')
        ->and(config('vkcrm.brand.colors.navy'))->toMatch('/^#[0-9a-fA-F]{6}$/')
        // M6.5 Task 12 (`notify/notify-14`): Reply-To dùng chung của mọi thư văn phòng — một địa
        // chỉ THẬT, khác no-reply@ (MAIL_FROM_ADDRESS, chỉ dùng cho SPF/DKIM).
        ->and(config('vkcrm.brand.reply_to'))->toBeString()
        ->and(config('vkcrm.brand.reply_to'))->not->toBe(config('mail.from.address'));
});

it('§10.8 exposes rclone backup destination settings with safe defaults', function () {
    expect(config('vkcrm.backup.rclone.remote'))->toBeNull()
        ->and(config('vkcrm.backup.rclone.binary'))->toBe('rclone')
        ->and(config('vkcrm.backup.rclone.config_path'))->toBeNull()
        ->and(config('vkcrm.backup.rclone.timeout'))->toBe(1800)
        ->and(config('vkcrm.backup.rclone.keep'))->toBe(30)
        ->and(config('vkcrm.backup.local_keep'))->toBe(7);
});

it('treats blank domain env as null', function () {
    // Mirrors the transform in config/vkcrm.php
    $normalize = fn (?string $v) => filled($v) ? $v : null;

    expect($normalize(''))->toBeNull()
        ->and($normalize('crm.example.test'))->toBe('crm.example.test');
});

/*
 * M10 Task 5 (R5): ngưỡng phản hồi lần đầu, tính theo giờ làm việc. `INTAKE_RESPONSE_HOURS` trống,
 * `0` hay số âm đều rơi về 4 (mặc định của kế hoạch) — không bao giờ "nhắc ngay khi vừa nhận" hay "không
 * bao giờ nhắc". Giờ làm việc: Thứ Hai–Thứ Sáu, 08:00–17:30 (ngày theo ISO-8601, 1 = Thứ Hai).
 */
it('exposes the first-response threshold and the working hours with safe defaults', function () {
    expect(config('vkcrm.intake_response_hours'))->toBe(4)
        ->and(config('vkcrm.business_hours.days'))->toBe([1, 2, 3, 4, 5])
        ->and(config('vkcrm.business_hours.opens_at'))->toBe('08:00')
        ->and(config('vkcrm.business_hours.closes_at'))->toBe('17:30');
});

it('reads the first-response threshold from INTAKE_RESPONSE_HOURS, falling back to 4 when blank, zero or negative', function (string $value, int $expected) {
    $key = 'INTAKE_RESPONSE_HOURS';
    $saved = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
    putenv("{$key}={$value}");
    $_ENV[$key] = $_SERVER[$key] = $value;

    try {
        $config = require base_path('config/vkcrm.php');

        expect($config['intake_response_hours'])->toBe($expected);
    } finally {
        [$env, $envConst, $server] = $saved;
        $env === false ? putenv($key) : putenv("{$key}={$env}");

        if ($envConst === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $envConst;
        }

        if ($server === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $server;
        }
    }
})->with([
    'đặt 6' => ['6', 6],
    'trống' => ['', 4],
    'số 0' => ['0', 4],
    'số âm' => ['-3', 4],
]);
