<?php

use App\Actions\Settings\WriteSettings;
use App\Models\User;
use App\Support\OfficeProfile;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\ReferenceDataSeeder;
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
        ->and($output)->toContain(__('preflight.demo_accounts_ok'))
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

/**
 * Fix round 1, finding 4 — `config/trustedproxy.php` gợi ý dùng `*` "khi không có cách nào biết
 * địa chỉ đó", và mẫu nginx/apache của CHÍNH task này (`tools/deploy/`) tả một cấu hình KHÔNG có
 * proxy tách rời (nginx/apache nói thẳng với php-fpm). Một người vận hành theo đúng mẫu, không có
 * proxy nào để điền, làm theo gợi ý đó thì preflight xanh — nhưng `*`/`**` (Laravel
 * `TrustProxies::setTrustedProxyIpAddressesToTheCallingIp()`) và các dải bao trọn `0.0.0.0/0`/
 * `::/0` đều tin MỌI IP tự khai `X-Forwarded-For`, xoá luôn ranh giới mà `ADMIN_IP_ALLOWLIST`
 * (R7) và bộ đếm đăng nhập theo IP (§10.3) dựa vào — một IP bất kỳ giả `X-Forwarded-For` là ai
 * cũng qua được hai lớp đó, và cột bằng chứng `stage_log_views.ip` mất luôn ý nghĩa. Bốn giá trị
 * dưới đây (không phân biệt hoa/thường, phần tử NẰM TRONG danh sách nhiều proxy cũng tính) phải
 * đỏ giống hệt để trống — không có "gần đúng" nào khác biệt.
 */
it('§preflight R1 production TRUSTED_PROXIES tin TOÀN BỘ IP là ĐỎ, không phải XANH', function (string $value) {
    config(preflightGreenProductionConfig());
    config(['trustedproxy.proxies' => $value]);
    fakeStoragePrivateNotExposed();
    fakeMariadbDumpFound();

    $exitCode = Artisan::call('vkcrm:preflight');
    $output = Artisan::output();

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain(__('preflight.trusted_proxies_trust_all'))
        ->and($output)->not->toContain(__('preflight.trusted_proxies_ok'));
})->with([
    '*' => ['*'],
    '**' => ['**'],
    '0.0.0.0/0' => ['0.0.0.0/0'],
    '::/0' => ['::/0'],
    'chữ hoa lẫn với một IP thật' => ['10.0.0.1,0.0.0.0/0'],
]);

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

/*
| Final review I4: `docs/CAI-DAT.md` Bước 5 cho chạy `db:seed --class=DemoDataSeeder --force` trên
| máy chủ thật. Ngoài local/testing tám tài khoản nhân sự demo ra đời với mật khẩu `password` và
| KHÔNG secret 2FA — ai đăng nhập trước thì tự cài 2FA của mình (trust-on-first-use) và chiếm tài
| khoản, kể cả `admin@luatvukhang.com`. Trước bản sửa chỉ có câu chữ trong tài liệu chặn việc đó.
| Danh sách email KHÔNG chép tay ở đây: chạy chính seeder ở `production` rồi đọc lại bảng `users`.
*/

/** Chạy đúng hai lệnh seed của tài liệu ở `production`, trả email nhân sự demo theo thứ tự tạo. */
function preflightSeedDemoAsProduction(): array
{
    app()->detectEnvironment(fn () => 'production');

    test()->artisan('db:seed', ['--class' => ReferenceDataSeeder::class, '--force' => true])->assertSuccessful()->run();
    test()->artisan('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true])->assertSuccessful()->run();

    return User::query()->orderBy('id')->pluck('email')->all();
}

it('§preflight I4 production còn tài khoản nhân sự demo mật khẩu mẫu mà ADMIN_IP_ALLOWLIST trống là ĐỎ, nêu đích danh từng email', function () {
    $emails = preflightSeedDemoAsProduction();

    config(preflightGreenProductionConfig());
    config(['vkcrm.security.admin_ip_allowlist' => '']);
    fakeStoragePrivateNotExposed();
    fakeMariadbDumpFound();

    $exitCode = Artisan::call('vkcrm:preflight');
    $output = Artisan::output();

    expect($emails)->toHaveCount(8)
        ->and($exitCode)->not->toBe(0)
        ->and($output)->toContain(__('preflight.demo_accounts_exposed', [
            'count' => 8,
            'emails' => implode(', ', $emails),
        ]))
        ->and($output)->toContain(__('preflight.summary_red'));
});

it('§preflight I4 tài khoản demo chỉ mở trong ADMIN_IP_ALLOWLIST là VÀNG, không ĐỎ', function () {
    $emails = preflightSeedDemoAsProduction();

    config(preflightGreenProductionConfig());
    config(['vkcrm.security.admin_ip_allowlist' => '203.0.113.10']);
    fakeStoragePrivateNotExposed();
    fakeMariadbDumpFound();

    $exitCode = Artisan::call('vkcrm:preflight');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain(__('preflight.demo_accounts_allowlisted', [
            'count' => 8,
            'emails' => implode(', ', $emails),
        ]))
        ->and($output)->toContain(__('preflight.summary_yellow'));
});

it('§preflight I4 một quản trị viên THẬT dùng lại địa chỉ admin@luatvukhang.com với mật khẩu riêng là XANH', function () {
    // Máy chủ chưa từng demo: `vkcrm:create-admin` tạo quản trị viên thật đúng bằng địa chỉ mà seeder
    // demo cũng dùng — văn phòng có quyền dùng hộp thư đó. Mật khẩu của họ không phải mật khẩu mẫu,
    // nên không được ĐỎ (một dòng đỏ ở đây chặn ra mắt một máy chủ không có lỗi gì).
    $this->artisan('vkcrm:create-admin')
        ->expectsQuestion(__('users.create_admin.ask_name'), 'Trần Quản Trị')
        ->expectsQuestion(__('users.create_admin.ask_email'), 'admin@luatvukhang.com')
        ->expectsQuestion(__('users.create_admin.ask_password'), 'Mat-khau-that-12')
        ->expectsQuestion(__('users.create_admin.ask_password_confirmation'), 'Mat-khau-that-12')
        ->assertSuccessful()
        ->run();

    config(preflightGreenProductionConfig());
    config(['vkcrm.security.admin_ip_allowlist' => '']);
    fakeStoragePrivateNotExposed();
    fakeMariadbDumpFound();

    $exitCode = Artisan::call('vkcrm:preflight');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain(__('preflight.demo_accounts_ok'));
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

/**
 * Gộp M7 vào `main` (PROGRESS "Ghi chú M7", Task 10, "M8 Task 7 (làn M8b)"): từ M7 Task 10 chủ văn
 * phòng tự nhập bốn thông tin pháp lý ở trang "Thông tin văn phòng" (bảng `settings`), và mọi nơi in
 * chúng đọc qua `OfficeProfile` (bảng → cấu hình). Dòng kiểm tra của `vkcrm:preflight` phải hỏi cùng
 * nguồn đó: bốn trường đã nhập trong app thì XANH dù `.env` để trống — trước bản gộp nó đọc thẳng
 * `config('vkcrm.brand.*')` nên báo VÀNG "còn thiếu" cho những giá trị đang in đúng ở chân mọi thư.
 * Vế âm (cả hai nơi trống → VÀNG, nêu tên biến) là test ngay trên.
 */
it('§preflight bốn thông tin pháp lý nhập ở trang Thông tin văn phòng là XANH dù .env để trống', function () {
    config(preflightGreenProductionConfig());
    config([
        'vkcrm.brand.tax_code' => null,
        'vkcrm.brand.bar_association' => null,
        'vkcrm.brand.licence_number' => null,
        'vkcrm.brand.office_address' => null,
    ]);
    app(WriteSettings::class)->handle([
        OfficeProfile::KEY_PREFIX.'tax_code' => '0101234567',
        OfficeProfile::KEY_PREFIX.'bar_association' => 'Đoàn Luật sư Đồng Nai',
        OfficeProfile::KEY_PREFIX.'licence_number' => '1234/TP/ĐKHĐ',
        OfficeProfile::KEY_PREFIX.'office_address' => '1808 đường Nguyễn Ái Quốc, phường Trấn Biên, thành phố Đồng Nai',
    ], null);
    fakeStoragePrivateNotExposed();
    fakeMariadbDumpFound();

    $exitCode = Artisan::call('vkcrm:preflight');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain(__('preflight.brand_fields_ok'))
        ->and($output)->not->toContain('BRAND_TAX_CODE');
});
