<?php

namespace App\Actions\Deployment;

use App\Enums\PreflightLevel;
use App\Http\Middleware\RestrictAdminIpAllowlist;
use App\Models\User;
use App\Support\Backup\RcloneProcess;
use App\Support\OfficeProfile;
use Database\Seeders\DemoAccountsSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * `vkcrm:preflight` (R1, kế hoạch M8 Task 1) — kiểm các điều kiện phải đúng TRƯỚC khi mở cổng
 * một máy chủ thật, và sau mỗi lần nâng cấp (`docs/CAI-DAT.md`, mục "Khi đưa lên máy chủ thật").
 * Nghiệp vụ nằm hẳn ở đây; `App\Console\Commands\PreflightCommand` chỉ định dạng và chọn mã
 * thoát.
 *
 * # Vì sao chỉ kiểm khi `APP_ENV=production`
 *
 * Giá trị THẬT của `TRUSTED_PROXIES`, `SESSION_SECURE_COOKIE`… chỉ nằm trong `.env` của MÁY CHỦ
 * THẬT, không nằm trong repo — không test nào của repo khẳng định được nó. Một `APP_ENV` để
 * trống là ĐỎ ở MỌI nơi (không môi trường hợp lệ nào để trống nó); một `APP_ENV` có giá trị
 * nhưng KHÁC `production` (local, staging…) chỉ in MỘT dòng vàng nói rõ các điều kiện ra mắt bên
 * dưới KHÔNG được kiểm — giá nếu sai (phán quyết của controller): một máy thật đặt nhầm
 * `APP_ENV=staging` chỉ nhận cảnh báo vàng thay vì bị chặn.
 *
 * Đọc `config('app.env')` (giá trị Laravel đã giải từ `.env`), KHÔNG đọc `app()->environment()`:
 * hai giá trị này thường trùng nhau, nhưng `config('app.env')` là cái mà `.env` của MÁY ĐANG kiểm
 * thật sự ghi, còn `app()->environment()` là môi trường của TIẾN TRÌNH PHP đang chạy lệnh — khác
 * nhau khi lệnh chạy với `--env=` hay trong một tiến trình test đang giả lập một môi trường khác
 * bằng `config(['app.env' => …])` (xem `tests/Feature/Deployment/PreflightCommandTest.php`).
 *
 * # Giới hạn đã biết trước, ghi ra để không ai "sửa" nhầm
 *
 * `checkBackupNumericEnvVars()` đọc `env()` TRỰC TIẾP (không qua `config()`) vì `config('vkcrm.
 * backup.local_keep')` và các khoá tương tự đã bị ép `(int)` từ lúc tệp cấu hình được nạp — một
 * chuỗi gõ sai kiểu `"7 bản"` đã lặng lẽ thành `7` trước khi tới đây. Hệ quả: sau
 * `php artisan config:cache`, `LoadEnvironmentVariables::bootstrap()` bỏ qua việc nạp `.env`
 * hẳn (đọc docblock của chính lớp đó), nên `env()` ở ĐÂY — và ở MỌI nơi ngoài một tệp cấu hình —
 * trả về `null` bất kể `.env` ghi gì, và ba điều kiện đó lặng lẽ không kiểm được nữa (coi như để
 * trống, không đỏ). **`vkcrm:preflight` vì vậy phải chạy TRƯỚC `config:cache` trong quy trình
 * triển khai** — ghi rõ ở `docs/CAI-DAT.md`.
 */
class RunPreflight
{
    /** @return list<array{key: string, level: PreflightLevel, message: string}> */
    public function handle(): array
    {
        $rows = [];

        $appEnv = trim((string) config('app.env'));

        if ($appEnv === '') {
            $rows[] = $this->row('app_env', PreflightLevel::Red, __('preflight.app_env_blank'));
        } elseif ($appEnv !== 'production') {
            $rows[] = $this->row('app_env', PreflightLevel::Yellow, __('preflight.app_env_non_production', ['env' => $appEnv]));
        } else {
            $rows[] = $this->row('app_env', PreflightLevel::Green, __('preflight.app_env_production'));

            array_push($rows, ...$this->launchConditionRows());
        }

        array_push($rows, ...$this->backupNumericEnvRows());

        return $rows;
    }

    /** @return list<array{key: string, level: PreflightLevel, message: string}> */
    private function launchConditionRows(): array
    {
        return [
            $this->trustedProxiesRow(),
            $this->heartbeatUrlRow(),
            $this->sessionSecureCookieRow(),
            $this->appDebugRow(),
            $this->demoAccountsRow(),
            $this->extensionsRow(),
            $this->gdRow(),
            $this->pcntlRow(),
            $this->brandFieldsRow(),
            $this->storagePrivateExposureRow(),
            $this->zipAes256Row(),
            $this->procOpenRow(),
            $this->mariadbDumpRow(),
        ];
    }

    /**
     * Fix round 1, finding 4 — bốn giá trị "tin TOÀN BỘ IP", đỏ giống hệt để trống. `*`/`**` là
     * hai chuỗi đặc biệt của chính `Illuminate\Http\Middleware\TrustProxies::
     * setTrustedProxyIpAddresses()` (gọi `setTrustedProxyIpAddressesToTheCallingIp()`, đặt dải
     * tin thành CHÍNH HAI dải bao trọn `0.0.0.0/0`/`::/0`) — và một người tự gõ thẳng hai dải CIDR
     * đó vào `TRUSTED_PROXIES` (không qua `*`) tạo ra ĐÚNG cùng hệ quả bằng đường khác. `config/
     * trustedproxy.php` từng gợi ý `*` "khi không có cách nào biết địa chỉ đó" — đúng tình huống
     * của mẫu `tools/deploy/nginx.conf.example`/`apache-vhost.conf.example` (nginx/apache nói
     * thẳng với php-fpm, không proxy tách rời) — mà tin toàn bộ IP nghĩa là ai cũng tự khai được
     * `X-Forwarded-For`, xuyên thủng `ADMIN_IP_ALLOWLIST` (R7) và bộ đếm đăng nhập theo IP
     * (§10.3), và cột bằng chứng `stage_log_views.ip` mất ý nghĩa. `docs/CAI-DAT.md` và cả hai mẫu
     * `tools/deploy/` giờ nói rõ giá trị đúng cho cấu hình không-proxy đó là `TRUSTED_PROXIES=
     * 127.0.0.1`, không phải `*`.
     */
    private const TRUST_ALL_PROXIES = ['*', '**', '0.0.0.0/0', '::/0'];

    private function trustedProxiesRow(): array
    {
        $raw = (string) config('trustedproxy.proxies');

        if ($raw === '') {
            return $this->row('trusted_proxies', PreflightLevel::Red, __('preflight.trusted_proxies_missing'));
        }

        $entries = array_map(fn (string $entry): string => strtolower(trim($entry)), explode(',', $raw));

        if (array_intersect($entries, self::TRUST_ALL_PROXIES) !== []) {
            return $this->row('trusted_proxies', PreflightLevel::Red, __('preflight.trusted_proxies_trust_all'));
        }

        return $this->row('trusted_proxies', PreflightLevel::Green, __('preflight.trusted_proxies_ok'));
    }

    private function heartbeatUrlRow(): array
    {
        return filled(config('vkcrm.heartbeat_url'))
            ? $this->row('heartbeat_url', PreflightLevel::Green, __('preflight.heartbeat_url_ok'))
            : $this->row('heartbeat_url', PreflightLevel::Red, __('preflight.heartbeat_url_missing'));
    }

    /**
     * Đọc giá trị ĐÃ GIẢI ở `config('session.secure')` (ghi đè ở `AppServiceProvider::boot()`,
     * xem docblock ở đó), không đọc `env('SESSION_SECURE_COOKIE')` — chính giá trị này mới là
     * cái `Illuminate\Session\Middleware\StartSession` thực sự dùng để dựng cookie phiên.
     */
    private function sessionSecureCookieRow(): array
    {
        $value = config('session.secure');

        return $value === true
            ? $this->row('session_secure_cookie', PreflightLevel::Green, __('preflight.session_secure_cookie_ok'))
            : $this->row('session_secure_cookie', PreflightLevel::Red, __('preflight.session_secure_cookie_off', [
                'value' => var_export($value, true),
            ]));
    }

    private function appDebugRow(): array
    {
        return config('app.debug') === true
            ? $this->row('app_debug', PreflightLevel::Red, __('preflight.app_debug_on'))
            : $this->row('app_debug', PreflightLevel::Green, __('preflight.app_debug_ok'));
    }

    /**
     * Final review I4 — tài khoản nhân sự demo trên máy chủ thật. `db:seed --class=DemoDataSeeder`
     * ngoài local/testing tạo tám tài khoản /admin mật khẩu mẫu CÔNG KHAI và KHÔNG secret 2FA: ai
     * đăng nhập trước thì tự cài 2FA của mình (trust-on-first-use) và chiếm tài khoản đó, kể cả quản
     * trị viên — mọi vụ việc, kể cả vụ hạn chế. `docs/CAI-DAT.md` Bước 5 chỉ cho làm vậy sau khi đặt
     * `ADMIN_IP_ALLOWLIST`; dòng này là chốt chặn trong mã cho đúng câu đó.
     *
     *  - ĐỎ: còn tài khoản demo dùng mật khẩu mẫu VÀ allowlist trống (cùng cách đọc với middleware
     *    {@see RestrictAdminIpAllowlist::entries()} — trống nghĩa là /admin mở cho cả Internet).
     *  - VÀNG: còn tài khoản demo nhưng /admin chỉ mở trong allowlist — được, nhưng phải chạy chuỗi
     *    "Hết demo, chuyển sang dùng thật" trước khi dùng thật.
     *  - XANH: không tài khoản nào trong {@see DemoDataSeeder::staffEmails()} còn mật khẩu mẫu.
     *
     * Hỏi MẬT KHẨU chứ không chỉ email: văn phòng có quyền tạo quản trị viên thật bằng đúng
     * `admin@luatvukhang.com` (`vkcrm:create-admin`), và một dòng đỏ cho máy chủ đó chặn ra mắt một
     * máy chủ không có lỗi gì. Giá: tối đa tám lần `Hash::check()` (bcrypt) mỗi lần chạy lệnh.
     */
    private function demoAccountsRow(): array
    {
        $emails = User::query()
            ->whereIn('email', DemoDataSeeder::staffEmails())
            ->orderBy('id')
            ->get(['id', 'email', 'password'])
            ->filter(fn (User $user): bool => Hash::check(DemoAccountsSeeder::DEMO_PASSWORD, $user->password))
            ->pluck('email');

        if ($emails->isEmpty()) {
            return $this->row('demo_accounts', PreflightLevel::Green, __('preflight.demo_accounts_ok'));
        }

        $parameters = ['count' => $emails->count(), 'emails' => $emails->implode(', ')];

        return RestrictAdminIpAllowlist::entries() === []
            ? $this->row('demo_accounts', PreflightLevel::Red, __('preflight.demo_accounts_exposed', $parameters))
            : $this->row('demo_accounts', PreflightLevel::Yellow, __('preflight.demo_accounts_allowlisted', $parameters));
    }

    private function extensionsRow(): array
    {
        $required = (array) config('vkcrm.deployment.required_extensions', []);
        $missing = array_values(array_filter($required, fn (string $extension): bool => ! extension_loaded($extension)));

        return $missing === []
            ? $this->row('extensions', PreflightLevel::Green, __('preflight.extensions_ok'))
            : $this->row('extensions', PreflightLevel::Red, __('preflight.extensions_missing', [
                'extensions' => implode(', ', $missing),
            ]));
    }

    /**
     * `gd` KHÔNG nằm trong `required_extensions` (xem docblock ở `config/vkcrm.php`) vì
     * `config/media-library.php` chọn nó làm `image_driver` MẶC ĐỊNH nhưng dự án chưa đăng ký
     * `addMediaConversion()`/`registerMediaConversions()` nào (soát ngày 2026-09-28: không có
     * lời gọi nào trong `app/`) — thiếu `gd` hôm nay không làm vỡ tính năng nào đang chạy thật,
     * nên VÀNG chứ không ĐỎ. Đỏ hoá dòng này khi có Task nào đăng ký chuyển đổi ảnh đầu tiên.
     */
    private function gdRow(): array
    {
        return extension_loaded('gd')
            ? $this->row('gd', PreflightLevel::Green, __('preflight.gd_ok'))
            : $this->row('gd', PreflightLevel::Yellow, __('preflight.gd_missing'));
    }

    /**
     * Việc sau gộp M7 (làn fu2, phát hiện "wiring" của rà soát gộp): giờ chết của job gói bàn giao
     * (`GenerateHandoverPackage::$timeout` = 1200, `$failOnTimeout`, `--timeout=1200` của mục lịch
     * `queue.handover`) chỉ có tác dụng khi PHP DÒNG LỆNH có ext-pcntl. Thiếu nó, worker không bao
     * giờ giết job quá giờ: `failed()` không chạy (luật sư không được báo), và khi một lần dựng gói
     * vượt 1500 giây (`retry_after` và khoá `withoutOverlapping` 25 phút cùng hết) lượt kế tiếp nhận
     * lại cùng job, dựng vào cùng thư mục làm việc — đúng cuộc đua R9 dựng kết nối `handover` để tránh.
     *
     * Đọc `extension_loaded()`/`function_exists()` của CHÍNH tiến trình đang chạy lệnh này — tức PHP
     * dòng lệnh, cùng PHP mà cron chạy `schedule:run` (cùng giới hạn đã ghi ở {@see procOpenRow()}:
     * chạy preflight bằng đúng binary PHP của cron). Ba chiều, hỏi theo thứ tự:
     *
     * 1. Extension CHƯA nạp → VÀNG, và KHÔNG thêm vào `required_extensions`: danh sách đó đúng bằng
     *    `composer check-platform-reqs` + `pdo_mysql`, và thiếu pcntl không làm vỡ màn hình nào —
     *    `Worker::supportsAsyncSignals()` trả false nên worker không gọi hàm pcntl nào, vẫn chạy,
     *    chỉ mất lưới an toàn của gói lớn. Câu này hỏi TRƯỚC: thiếu extension thì các hàm của nó
     *    cũng không tồn tại, nhưng đó không phải chiều ĐỎ dưới đây.
     * 2. Extension đã nạp nhưng một hàm trong `vkcrm.deployment.worker_signal_functions` không tồn
     *    tại → ĐỎ (rà soát cuối làn fu2, I1). `Worker::supportsAsyncSignals()` chỉ hỏi
     *    `extension_loaded('pcntl')`, nên `daemon()` vẫn gọi `pcntl_async_signals()`/`pcntl_signal()`
     *    (`listenForSignals()`) và `pcntl_signal()`/`pcntl_alarm()` (`registerTimeoutHandler()`, mỗi
     *    vòng, kể cả vòng không có job). Trên PHP 8 một hàm nằm trong `disable_functions` là hàm
     *    không tồn tại — hay gặp ở PHP dòng lệnh của cPanel/CloudLinux — nên MỌI lượt `queue:work`
     *    (`queue.drain` lẫn `queue.handover`) chết ở vòng đầu với "Call to undefined function",
     *    trước khi chạy job nào: không thư nào đi, kể cả thư nhắc mốc thời hạn. Câu ĐỎ nêu đúng các
     *    hàm bị chặn. Cùng lý do {@see procOpenRow()} hỏi `function_exists()`.
     * 3. Đủ cả hai → XANH, câu XANH nêu các hàm đã kiểm.
     *
     * Tên extension và danh sách hàm đọc từ cấu hình chỉ để test gài tên giả mà dựng chiều VÀNG và
     * ĐỎ (`extension_loaded()`/`function_exists()` không giả được). Giá trị dự phòng của hai lời gọi
     * `config()` bằng đúng giá trị trong `config/vkcrm.php`, để một tệp cấu hình đã cache từ bản cũ
     * (chưa có khoá danh sách hàm) không làm dòng này lặng lẽ XANH.
     */
    private function pcntlRow(): array
    {
        $extension = (string) config('vkcrm.deployment.worker_timeout_extension', 'pcntl');
        $functions = (array) config('vkcrm.deployment.worker_signal_functions', [
            'pcntl_async_signals', 'pcntl_signal', 'pcntl_alarm',
        ]);

        if (! extension_loaded($extension)) {
            return $this->row('pcntl', PreflightLevel::Yellow, __('preflight.pcntl_missing'));
        }

        $disabled = array_values(array_filter(
            $functions,
            fn (string $function): bool => ! function_exists($function),
        ));

        if ($disabled !== []) {
            return $this->row('pcntl', PreflightLevel::Red, __('preflight.pcntl_functions_disabled', [
                'functions' => implode(', ', $disabled),
            ]));
        }

        return $this->row('pcntl', PreflightLevel::Green, __('preflight.pcntl_ok', [
            'functions' => implode(', ', $functions),
        ]));
    }

    /**
     * Bốn thông tin pháp lý của chân thư. Gộp M7 vào `main`: đọc qua {@see OfficeProfile} (bảng
     * `settings` mà chủ văn phòng nhập ở trang "Thông tin văn phòng", M7 Task 10 → cấu hình) — CÙNG
     * nguồn mà chân mọi thư (`BrandFooter`) và `MUC-LUC.pdf` in ra, nên dòng này không báo "còn
     * thiếu" một giá trị đang in đúng. Tên biến `.env` vẫn được nêu: đó là nơi thứ hai điền được.
     */
    private function brandFieldsRow(): array
    {
        $office = OfficeProfile::current();

        $fields = [
            'BRAND_TAX_CODE' => $office->taxCode(),
            'BRAND_BAR_ASSOCIATION' => $office->barAssociation(),
            'BRAND_LICENCE_NUMBER' => $office->licenceNumber(),
            'BRAND_OFFICE_ADDRESS' => $office->officeAddress(),
        ];

        $missing = [];

        foreach ($fields as $envName => $value) {
            if (blank($value)) {
                $missing[] = $envName;
            }
        }

        return $missing === []
            ? $this->row('brand_fields', PreflightLevel::Green, __('preflight.brand_fields_ok'))
            : $this->row('brand_fields', PreflightLevel::Yellow, __('preflight.brand_fields_missing', [
                'fields' => implode(', ', $missing),
            ]));
    }

    /**
     * Ghi một tệp thăm dò vào đĩa `private` rồi GET nó qua `APP_URL` ở hai đường một cấu hình
     * máy chủ sai có thể lộ (R1, brief Task 1): document root là gốc dự án
     * (`/storage/app/private/<tệp>`), hoặc symlink `public/storage` trỏ nhầm vào `private` thay
     * vì `public` (`/storage/<tệp>`). 200 kèm ĐÚNG nội dung ở BẤT KỲ đường nào = ĐỎ; không gọi
     * được `APP_URL` = VÀNG "không kiểm được"; ngược lại (404, nội dung khác) = XANH. Tệp thăm dò
     * LUÔN bị xoá, kể cả khi có ngoại lệ.
     */
    private function storagePrivateExposureRow(): array
    {
        $filename = 'preflight-probe-'.Str::random(24).'.txt';
        $content = 'vkcrm-preflight-probe-'.Str::random(16);
        $disk = Storage::disk('private');

        $disk->put($filename, $content);

        try {
            $base = rtrim((string) config('app.url'), '/');
            $urls = [
                $base.'/storage/app/private/'.$filename,
                $base.'/storage/'.$filename,
            ];

            $unreachableDetail = null;

            foreach ($urls as $url) {
                try {
                    $response = Http::timeout(5)->get($url);

                    if ($response->ok() && $response->body() === $content) {
                        return $this->row('storage_private_exposed', PreflightLevel::Red, __('preflight.storage_private_exposed', [
                            'url' => $url,
                        ]));
                    }
                } catch (ConnectionException $exception) {
                    $unreachableDetail ??= $exception->getMessage();
                }
            }

            if ($unreachableDetail !== null) {
                return $this->row('storage_private_exposed', PreflightLevel::Yellow, __('preflight.storage_private_unreachable', [
                    'detail' => $unreachableDetail,
                    'url1' => $urls[0],
                    'url2' => $urls[1],
                ]));
            }

            return $this->row('storage_private_exposed', PreflightLevel::Green, __('preflight.storage_private_ok', [
                'count' => count($urls),
            ]));
        } finally {
            $disk->delete($filename);
        }
    }

    /**
     * "Không giả được `defined()` của một hằng số lớp" — hạn chế đã biết trước và đã ghi ở
     * `tests/Feature/Backup/GuardBackupEncryptionTest.php` cho đúng câu hỏi này. Container test
     * (`webdevops/php:8.3-alpine`, cùng ảnh với môi trường dùng cho `m8b-dev`) có AES-256 thật —
     * xác nhận bằng `docker exec ... php -r 'var_dump(defined("ZipArchive::EM_AES_256"));'` →
     * `bool(true)` ngày 2026-09-28 — nên chiều XANH được kiểm THẬT; chiều ĐỎ (libzip cũ) không
     * dựng lại được trong test, cùng hạn chế với `GuardBackupEncryption`.
     */
    private function zipAes256Row(): array
    {
        return defined('ZipArchive::EM_AES_256')
            ? $this->row('zip_aes256', PreflightLevel::Green, __('preflight.zip_aes256_ok'))
            : $this->row('zip_aes256', PreflightLevel::Red, __('preflight.zip_aes256_missing'));
    }

    /** Cùng hạn chế với {@see zipAes256Row()} — `function_exists()` không giả được trong test. */
    private function procOpenRow(): array
    {
        return function_exists('proc_open')
            ? $this->row('proc_open', PreflightLevel::Green, __('preflight.proc_open_ok'))
            : $this->row('proc_open', PreflightLevel::Red, __('preflight.proc_open_disabled'));
    }

    /**
     * Qua `Illuminate\Support\Facades\Process` (không `shell_exec`/`exec` trực tiếp) — cùng cách
     * gọi tiến trình với {@see RcloneProcess}, nên `Process::fake()` giả được
     * cả hai chiều trong test, không cần binary thật trong container.
     */
    private function mariadbDumpRow(): array
    {
        foreach (['mariadb-dump', 'mysqldump'] as $binary) {
            if (Process::run('command -v '.escapeshellarg($binary))->successful()) {
                return $this->row('mariadb_dump', PreflightLevel::Green, __('preflight.mariadb_dump_ok', [
                    'binary' => $binary,
                ]));
            }
        }

        return $this->row('mariadb_dump', PreflightLevel::Red, __('preflight.mariadb_dump_missing'));
    }

    /**
     * Phán quyết của controller: một giá trị CÓ mặt nhưng KHÔNG phải số nguyên không âm là ĐỎ,
     * ở MỌI môi trường (không chỉ production) — một lỗi gõ ở ba biến này bị ép `(int)` lặng lẽ ở
     * nơi đọc, nên không lộ ra cho tới khi hành vi đã sai. Biến VẮNG MẶT hoặc để trống dùng mặc
     * định, không sinh dòng nào. Đọc `env()` trực tiếp — xem giới hạn "chạy trước config:cache"
     * ở docblock đầu lớp.
     *
     * @return list<array{key: string, level: PreflightLevel, message: string}>
     */
    private function backupNumericEnvRows(): array
    {
        $variables = ['BACKUP_LOCAL_KEEP', 'BACKUP_RCLONE_TIMEOUT', 'BACKUP_MAX_STORAGE_MB'];

        $rows = [];

        foreach ($variables as $variable) {
            $raw = env($variable);

            if ($raw === null || trim((string) $raw) === '') {
                continue;
            }

            if (preg_match('/^\d+$/', trim((string) $raw)) !== 1) {
                $rows[] = $this->row('backup_env_'.strtolower($variable), PreflightLevel::Red, __('preflight.backup_env_non_numeric', [
                    'variable' => $variable,
                    'value' => (string) $raw,
                ]));
            }
        }

        return $rows;
    }

    private function row(string $key, PreflightLevel $level, string $message): array
    {
        return ['key' => $key, 'level' => $level, 'message' => $message];
    }
}
