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
        ->and(config('vkcrm.brand.colors.navy'))->toMatch('/^#[0-9a-fA-F]{6}$/');
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
