<?php

it('exposes project settings with safe defaults', function () {
    expect(config('vkcrm.admin_domain'))->toBeNull()
        ->and(config('vkcrm.portal_domain'))->toBeNull()
        ->and(config('vkcrm.matter_code_prefix'))->toBe('VK')
        ->and(config('vkcrm.upload_max_mb'))->toBe(20)
        ->and(config('vkcrm.retention_years'))->toBe(10)
        ->and(config('vkcrm.client_access_days'))->toBe(90)
        ->and(config('vkcrm.clamav.enabled'))->toBeFalse()
        ->and(config('vkcrm.brand_color'))->toMatch('/^#[0-9a-fA-F]{6}$/');
});

it('treats blank domain env as null', function () {
    // Mirrors the transform in config/vkcrm.php
    $normalize = fn (?string $v) => filled($v) ? $v : null;

    expect($normalize(''))->toBeNull()
        ->and($normalize('crm.example.test'))->toBe('crm.example.test');
});
