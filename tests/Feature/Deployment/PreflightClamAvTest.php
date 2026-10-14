<?php

use App\Support\Files\VirusScanner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\Support\WebPushTestKeys;

/*
 * Làn fa (kiểm tra nghiệp vụ 2026-10-09, mục nhỏ "quét virus") — `vkcrm:preflight` nhắc khi quét virus
 * tắt, hoặc bật mà daemon không trả lời. Qua lệnh Artisan thật như `PreflightCommandTest`. Hàm toàn cục
 * mang tiền tố `pcav…`.
 */
function pcavProduction(): void
{
    $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($resource, $private);

    config([
        'app.env' => 'production',
        'app.debug' => false,
        'app.url' => 'http://preflight.example.test',
        'trustedproxy.proxies' => '10.0.0.1',
        'vkcrm.heartbeat_url' => 'https://heartbeat.example.test/ping',
        'session.secure' => true,
        'passport.private_key' => $private,
        'passport.public_key' => openssl_pkey_get_details($resource)['key'],
        ...WebPushTestKeys::config(),
    ]);

    Http::fake(fn () => Http::response('not found', 404));
    Process::fake();
}

function pcavScanner(bool $active): void
{
    app()->bind(VirusScanner::class, fn () => new class($active) implements VirusScanner
    {
        public function __construct(private bool $active) {}

        public function scan(string $path): void {}

        public function isActive(): bool
        {
            return $this->active;
        }
    });
}

it('warns in yellow on production when virus scanning is off', function () {
    pcavProduction();
    config(['vkcrm.clamav.enabled' => false]);

    Artisan::call('vkcrm:preflight');

    expect(Artisan::output())->toContain('['.__('preflight.levels.yellow').'] '.__('preflight_clamav.disabled'));
});

it('warns in yellow when scanning is on but the daemon does not answer, and is green when it does', function () {
    pcavProduction();
    config(['vkcrm.clamav.enabled' => true, 'vkcrm.clamav.socket' => '/run/clamd.sock']);

    pcavScanner(false);
    Artisan::call('vkcrm:preflight');
    expect(Artisan::output())->toContain('['.__('preflight.levels.yellow').'] '.__('preflight_clamav.unreachable', ['socket' => '/run/clamd.sock']));

    pcavScanner(true);
    Artisan::call('vkcrm:preflight');
    expect(Artisan::output())->toContain('['.__('preflight.levels.green').'] '.__('preflight_clamav.ok'));
});
