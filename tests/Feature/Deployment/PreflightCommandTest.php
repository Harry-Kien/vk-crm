<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| R1 — `vkcrm:preflight` (kế hoạch M8 Task 1)
|--------------------------------------------------------------------------
|
| `App\Actions\Deployment\RunPreflight` không được gọi thẳng — mọi test đi qua lệnh Artisan thật
| (`Artisan::call('vkcrm:preflight')` + `Artisan::output()`), cùng cách `BackupCheckCommandTest`
| đã làm cho `vkcrm:backup-check`.
|
| `Http::fake()` mô phỏng máy chủ web bằng cách đọc lại CHÍNH tệp thăm dò trên đĩa `private` giả —
| tái hiện đúng những gì một máy chủ cấu hình sai sẽ làm (phục vụ nguyên văn tệp đang có ở đó),
| không cần biết trước tên/nội dung ngẫu nhiên mà Action sinh ra.
*/

/** @return array<string, mixed> */
function preflightGreenProductionConfig(): array
{
    return [
        'app.env' => 'production',
        'app.debug' => false,
        'app.url' => 'http://preflight.example.test',
        'trustedproxy.proxies' => '10.0.0.1',
        'vkcrm.heartbeat_url' => 'https://heartbeat.example.test/ping',
        'session.secure' => true,
        'vkcrm.brand.tax_code' => '0101234567',
        'vkcrm.brand.bar_association' => 'Đoàn Luật sư TP.HCM',
        'vkcrm.brand.licence_number' => '1234/TP/ĐKHĐ',
        'vkcrm.brand.office_address' => '123 Đường ABC, Quận 1, TP.HCM',
    ];
}

/** Máy chủ web KHÔNG lộ storage/app/private (hành vi đúng) — 404 cho mọi đường dò. */
function fakeStoragePrivateNotExposed(): void
{
    Http::fake(fn () => Http::response('not found', 404));
}

/** Máy chủ web LỘ storage/app/private — phục vụ nguyên văn tệp thăm dò đang có trên đĩa. */
function fakeStoragePrivateExposed(): void
{
    Http::fake(function ($request) {
        $filename = basename((string) parse_url($request->url(), PHP_URL_PATH));
        $disk = Storage::disk('private');

        return $disk->exists($filename)
            ? Http::response($disk->get($filename), 200)
            : Http::response('not found', 404);
    });
}

function fakeMariadbDumpFound(): void
{
    Process::fake(['command -v *' => Process::result(exitCode: 0)]);
}

function fakeMariadbDumpMissing(): void
{
    Process::fake(['command -v *' => Process::result(exitCode: 1)]);
}

it('§preflight R1 APP_ENV để trống là ĐỎ ở mọi nơi, mã thoát khác 0', function () {
    config(['app.env' => '']);

    $exitCode = Artisan::call('vkcrm:preflight');

    expect($exitCode)->not->toBe(0)
        ->and(Artisan::output())->toContain(__('preflight.app_env_blank'));
});

it('§preflight R1 APP_ENV khác production chỉ in một dòng vàng, không kiểm điều kiện ra mắt, mã thoát 0', function () {
    config(preflightGreenProductionConfig());
    config([
        'app.env' => 'staging',
        // Đặt hỏng CÓ CHỦ Ý — nếu điều kiện này vẫn được kiểm dưới `staging`, dòng đỏ của nó sẽ
        // lộ ra trong output và test này đỏ.
        'trustedproxy.proxies' => null,
    ]);

    $exitCode = Artisan::call('vkcrm:preflight');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain(str_replace(':env', 'staging', __('preflight.app_env_non_production', ['env' => 'staging'])))
        ->and($output)->not->toContain(__('preflight.trusted_proxies_missing'));
});

it('§preflight R1 production đủ điều kiện: mọi dòng XANH/VÀNG hợp lệ, mã thoát 0', function () {
    config(preflightGreenProductionConfig());
    fakeStoragePrivateNotExposed();
    fakeMariadbDumpFound();

    $exitCode = Artisan::call('vkcrm:preflight');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain(__('preflight.app_env_production'))
        ->and($output)->toContain(__('preflight.trusted_proxies_ok'))
        ->and($output)->toContain(__('preflight.heartbeat_url_ok'))
        ->and($output)->toContain(__('preflight.session_secure_cookie_ok'))
        ->and($output)->toContain(__('preflight.app_debug_ok'))
        ->and($output)->toContain(__('preflight.extensions_ok'))
        ->and($output)->toContain(__('preflight.brand_fields_ok'))
        ->and($output)->toContain(__('preflight.storage_private_ok', ['count' => 2]))
        ->and($output)->toContain(__('preflight.zip_aes256_ok'))
        ->and($output)->toContain(__('preflight.proc_open_ok'))
        ->and($output)->toContain(__('preflight.summary_ok'));
});

it('§preflight R1 production thiếu TRUSTED_PROXIES là ĐỎ', function () {
    config(preflightGreenProductionConfig());
    config(['trustedproxy.proxies' => null]);
    fakeStoragePrivateNotExposed();
    fakeMariadbDumpFound();

    $exitCode = Artisan::call('vkcrm:preflight');
    $output = Artisan::output();

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain(__('preflight.trusted_proxies_missing'));
});

it('§preflight R1 production thiếu HEARTBEAT_URL là ĐỎ', function () {
    config(preflightGreenProductionConfig());
    config(['vkcrm.heartbeat_url' => null]);
    fakeStoragePrivateNotExposed();
    fakeMariadbDumpFound();

    $exitCode = Artisan::call('vkcrm:preflight');
    $output = Artisan::output();

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain(__('preflight.heartbeat_url_missing'));
});

it('§preflight R1 production SESSION_SECURE_COOKIE giải khác true là ĐỎ', function () {
    config(preflightGreenProductionConfig());
    config(['session.secure' => false]);
    fakeStoragePrivateNotExposed();
    fakeMariadbDumpFound();

    $exitCode = Artisan::call('vkcrm:preflight');
    $output = Artisan::output();

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain(__('preflight.session_secure_cookie_off', ['value' => 'false']));
});

it('§preflight R1 production APP_DEBUG=true là ĐỎ', function () {
    config(preflightGreenProductionConfig());
    config(['app.debug' => true]);
    fakeStoragePrivateNotExposed();
    fakeMariadbDumpFound();

    $exitCode = Artisan::call('vkcrm:preflight');
    $output = Artisan::output();

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain(__('preflight.app_debug_on'));
});

it('§preflight R1 production thiếu một PHP extension bắt buộc là ĐỎ, nêu đích danh tên', function () {
    config(preflightGreenProductionConfig());
    config(['vkcrm.deployment.required_extensions' => ['json', 'khong-co-that']]);
    fakeStoragePrivateNotExposed();
    fakeMariadbDumpFound();

    $exitCode = Artisan::call('vkcrm:preflight');
    $output = Artisan::output();

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain('khong-co-that');
});

it('§preflight R1 production thiếu bốn thông tin pháp lý BRAND_* là VÀNG, không ĐỎ', function () {
    config(preflightGreenProductionConfig());
    config([
        'vkcrm.brand.tax_code' => null,
        'vkcrm.brand.bar_association' => null,
        'vkcrm.brand.licence_number' => null,
        'vkcrm.brand.office_address' => null,
    ]);
    fakeStoragePrivateNotExposed();
    fakeMariadbDumpFound();

    $exitCode = Artisan::call('vkcrm:preflight');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('BRAND_TAX_CODE')
        ->and($output)->toContain('BRAND_BAR_ASSOCIATION')
        ->and($output)->toContain('BRAND_LICENCE_NUMBER')
        ->and($output)->toContain('BRAND_OFFICE_ADDRESS')
        ->and($output)->toContain(__('preflight.summary_yellow'));
});

it('§preflight R1 production storage/app/private phục vụ công khai được là ĐỎ', function () {
    config(preflightGreenProductionConfig());
    fakeStoragePrivateExposed();
    fakeMariadbDumpFound();

    $exitCode = Artisan::call('vkcrm:preflight');
    $output = Artisan::output();

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain('storage/app/private');
});

it('§preflight R1 không gọi được APP_URL để kiểm storage/app/private là VÀNG, không ĐỎ', function () {
    config(preflightGreenProductionConfig());
    Http::fake(fn () => throw new ConnectionException('could not resolve host'));
    fakeMariadbDumpFound();

    $exitCode = Artisan::call('vkcrm:preflight');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain(__('preflight.summary_yellow'))
        ->and($output)->not->toContain(str_replace(':url', '', __('preflight.storage_private_exposed', ['url' => ''])));
});

it('§preflight R1 production không tìm thấy mariadb-dump/mysqldump là ĐỎ', function () {
    config(preflightGreenProductionConfig());
    fakeStoragePrivateNotExposed();
    fakeMariadbDumpMissing();

    $exitCode = Artisan::call('vkcrm:preflight');
    $output = Artisan::output();

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain(__('preflight.mariadb_dump_missing'));
});

it('§preflight R1 BACKUP_RCLONE_TIMEOUT có giá trị nhưng không phải số là ĐỎ, bất kể APP_ENV', function () {
    // KHÔNG production — chứng minh ba biến số sao lưu được kiểm ở MỌI môi trường, không riêng
    // production (phán quyết của controller).
    config(['app.env' => 'testing']);

    $_SERVER['BACKUP_RCLONE_TIMEOUT'] = '30m';
    $_ENV['BACKUP_RCLONE_TIMEOUT'] = '30m';
    putenv('BACKUP_RCLONE_TIMEOUT=30m');

    try {
        $exitCode = Artisan::call('vkcrm:preflight');
        $output = Artisan::output();

        expect($exitCode)->not->toBe(0)
            ->and($output)->toContain('BACKUP_RCLONE_TIMEOUT')
            ->and($output)->toContain('30m');
    } finally {
        unset($_SERVER['BACKUP_RCLONE_TIMEOUT'], $_ENV['BACKUP_RCLONE_TIMEOUT']);
        putenv('BACKUP_RCLONE_TIMEOUT');
    }
});

it('§preflight R1 các biến số sao lưu số nguyên hợp lệ hoặc để trống không sinh dòng đỏ nào', function () {
    config(preflightGreenProductionConfig());
    fakeStoragePrivateNotExposed();
    fakeMariadbDumpFound();

    $_SERVER['BACKUP_LOCAL_KEEP'] = '7';
    $_ENV['BACKUP_LOCAL_KEEP'] = '7';
    putenv('BACKUP_LOCAL_KEEP=7');

    try {
        $exitCode = Artisan::call('vkcrm:preflight');

        expect($exitCode)->toBe(0)
            ->and(Artisan::output())->not->toContain('BACKUP_LOCAL_KEEP có giá trị');
    } finally {
        unset($_SERVER['BACKUP_LOCAL_KEEP'], $_ENV['BACKUP_LOCAL_KEEP']);
        putenv('BACKUP_LOCAL_KEEP');
    }
});
