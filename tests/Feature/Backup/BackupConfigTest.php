<?php

use App\Notifications\Backup\BackupHasFailedNotification;
use App\Notifications\Backup\CleanupHasFailedNotification;
use App\Notifications\Backup\UnhealthyBackupWasFoundNotification;
use App\Support\Backup\BackupDisks;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Spatie\Backup\Config\Config;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;

/*
|--------------------------------------------------------------------------
| §10.8 — hình dạng cấu hình `config/backup.php`
|--------------------------------------------------------------------------
*/

/**
 * Đặt biến môi trường cho CẢ TIẾN TRÌNH để một ứng dụng dựng lại sau đó (`refreshApplication()`)
 * đọc chúng khi nạp `config/*.php` — đúng như một tiến trình `php artisan schedule:run` mới trên
 * máy chủ. Cần vì `config/backup.php` đã chạy xong (đọc `env()`) TRƯỚC khi thân test bắt đầu,
 * nên đổi `config()` trong test không kiểm được gì về cách tệp cấu hình đọc biến môi trường.
 *
 * `Env::enablePutenv()` chỉ để XOÁ repository `env()` đã cache (putenv vốn đã bật): writer bất
 * biến của Dotenv nhớ những biến CHÍNH NÓ đã nạp từ `.env` ở lần dựng đầu và được phép ghi đè
 * chúng khi nạp lại. Không có dòng này, giá trị trong `.env` của máy (ví dụ `MAIL_FROM_ADDRESS`,
 * `APP_NAME`) lặng lẽ thắng giá trị đặt ở đây. Repository mới không nhớ gì, nên giá trị đặt ở
 * đây giữ nguyên như một biến môi trường thật của tiến trình.
 *
 * @param  array<string, string>  $vars
 * @return Closure(): void hàm khôi phục — gọi trong `finally`
 */
function overrideProcessEnv(array $vars): Closure
{
    $saved = [];

    foreach ($vars as $key => $value) {
        $saved[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    Env::enablePutenv();

    return function () use ($saved): void {
        foreach ($saved as $key => [$env, $envConst, $server]) {
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

        Env::enablePutenv();
    };
}

it('§10.8 / fix I1 — BACKUP_NOTIFY_EMAIL và MAIL_FROM_ADDRESS sai định dạng không làm hỏng lệnh artisan nào', function () {
    $garbage = [
        'BACKUP_NOTIFY_EMAIL' => 'ops@vidu.test;ke-toan@vidu.test',
        'MAIL_FROM_ADDRESS' => 'khong-phai-email',
    ];
    $restore = overrideProcessEnv($garbage);

    try {
        $this->refreshApplication();

        // Tự kiểm: giá trị rác thật sự đã tới ứng dụng mới dựng, không thì test xanh giả.
        expect(config('vkcrm.backup.notify_email'))->toBe($garbage['BACKUP_NOTIFY_EMAIL'])
            ->and(config('mail.from.address'))->toBe($garbage['MAIL_FROM_ADDRESS']);

        expect(fn () => app(Config::class))->not->toThrow(Throwable::class)
            ->and(Artisan::call('list'))->toBe(0);
    } finally {
        $restore();
    }
});

it('§10.8 / fix NB1 — BACKUP_NAME để trống (như .env.example) thì tên và tiền tố archive rơi về APP_NAME', function () {
    // `.env.example` giao `BACKUP_NAME=` rỗng. `env('BACKUP_NAME', mặc-định)` trả `''` cho biến
    // rỗng chứ không trả mặc định — bản trước vì vậy cho `backup.name = ''` và tiền tố `'-'`:
    // archive rơi thẳng vào gốc mọi disk đích, và `backup:clean`/`backup:monitor` cũng làm việc
    // trên gốc disk.
    $restore = overrideProcessEnv([
        'BACKUP_NAME' => '',
        'APP_NAME' => 'VK-CRM Kiểm thử',
    ]);

    try {
        $this->refreshApplication();

        expect(config('backup.backup.name'))->toBe('VK-CRM Kiểm thử')
            ->and(config('backup.backup.destination.filename_prefix'))->toBe('vk-crm-kiem-thu-')
            ->and(config('backup.monitor_backups.0.name'))->toBe('VK-CRM Kiểm thử');
    } finally {
        $restore();
    }
});

it('§10.8 / fix NB1 — BACKUP_NAME có giá trị thì thắng APP_NAME', function () {
    $restore = overrideProcessEnv([
        'BACKUP_NAME' => 'VK-CRM Production',
        'APP_NAME' => 'VK-CRM Kiểm thử',
    ]);

    try {
        $this->refreshApplication();

        expect(config('backup.backup.name'))->toBe('VK-CRM Production')
            ->and(config('backup.backup.destination.filename_prefix'))->toBe('vk-crm-production-')
            ->and(config('backup.monitor_backups.0.name'))->toBe('VK-CRM Production');
    } finally {
        $restore();
    }
});

it('§10.8 / fix I6 — BACKUP_NAME có trong .env.example kèm chú thích', function () {
    $envExample = file_get_contents(base_path('.env.example'));

    expect($envExample)->toContain('BACKUP_NAME=');
});

it('§10.8 nguồn sao lưu gồm storage/app/private, không gồm storage/logs', function () {
    // Đọc thẳng tệp cấu hình, KHÔNG `config()`: hook của `tests/Pest.php` (fix I2, lượt rà soát
    // cuối M8a) đổi `backup.backup.source.files.include` sang một thư mục nguồn tạm cho MỌI test
    // ở thư mục này, để `backup:run` thật không nén hồ sơ thật của máy.
    $source = (require config_path('backup.php'))['backup']['source']['files'];
    $include = $source['include'];
    $exclude = $source['exclude'];

    expect($include)->toContain(storage_path('app/private'))
        ->and($include)->not->toContain(storage_path('logs'))
        ->and($exclude)->toContain(storage_path('logs'));
});

it('§10.8 mục trong archive tương đối theo base_path(), không phải đường tuyệt đối của container tạo archive (M8a Task 3, R3)', function () {
    // `relative_path = null` (mặc định gói) đặt tên mục bằng đường TUYỆT ĐỐI của container đã
    // chạy `backup:run`, bỏ dấu `/` đầu — ví dụ `var/www/html/storage/app/private/1/tep.pdf`.
    // Đường đó chỉ khớp lại được trên máy khôi phục NẾU base_path() của máy đó trùng hệt máy đã
    // sao lưu — một sự trùng hợp, không phải một bất biến. `tools/backup/restore-drill.sh` chạy
    // trên máy dev (base_path() khác `/var/www/html` của container tạo archive) chứng minh điều
    // này bằng cách tách đúng mục có tiền tố `storage/app/private/` — tiền tố đó CHỈ tồn tại khi
    // `relative_path` là `base_path()` (xem docblock `config/backup.php`).
    expect(config('backup.backup.source.files.relative_path'))->toBe(base_path());
});

it('§10.8 sao lưu CSDL mặc định của kết nối hiện tại', function () {
    expect(config('backup.backup.source.databases'))->toContain(config('database.default'));
});

it('§10.8 tiếp tục ghi các disk còn lại khi một disk hỏng', function () {
    expect(config('backup.backup.destination.continue_on_failure'))->toBeTrue();
});

it('§10.8 mã hoá archive bằng AES-256', function () {
    expect(config('backup.backup.encryption'))->toBe('aes256');
});

it('§10.8 ba notification của dự án thay bản gốc của gói, gửi mail', function () {
    $notifications = config('backup.notifications.notifications');

    expect($notifications[BackupHasFailedNotification::class] ?? null)->toBe(['mail'])
        ->and($notifications[CleanupHasFailedNotification::class] ?? null)->toBe(['mail'])
        ->and($notifications[UnhealthyBackupWasFoundNotification::class] ?? null)->toBe(['mail']);
});

it('§10.8 khi BACKUP_DISKS chưa khai báo, đích mặc định là disk local_backups đã cấu hình', function () {
    expect(config('backup.backup.destination.disks'))->toBe([BackupDisks::DEFAULT_DISK])
        ->and(config('filesystems.disks.'.BackupDisks::DEFAULT_DISK.'.driver'))->toBe('local')
        ->and(config('filesystems.disks.'.BackupDisks::DEFAULT_DISK.'.root'))->toBe(storage_path('app/backups'));
});

it('§10.8 giữ 30 bản hằng ngày theo cấu hình dọn dẹp', function () {
    // Con số CHÍNH XÁC (29, không phải 30) và lý do được kiểm bằng mô phỏng thật ở
    // `BackupCleanupTest`; test này chỉ khoá lại giá trị đã chọn không bị đổi nhầm khi sửa file.
    expect(config('backup.cleanup.default_strategy.keep_all_backups_for_days'))->toBe(29)
        ->and(config('backup.cleanup.default_strategy.keep_daily_backups_for_days'))->toBe(0)
        ->and(config('backup.cleanup.default_strategy.keep_weekly_backups_for_weeks'))->toBe(0)
        ->and(config('backup.cleanup.default_strategy.keep_monthly_backups_for_months'))->toBe(0)
        ->and(config('backup.cleanup.default_strategy.keep_yearly_backups_for_years'))->toBe(0);
});

/*
 * Lượt rà soát cuối M8a — biến số của sao lưu để TRỐNG (đúng như `.env.example` giao) phải rơi về
 * mặc định, không về 0. `env('X', mặc-định)` trả `''` cho một biến có mặt mà rỗng, và
 * `(int) ''` là 0 — bản trước vì vậy cho `BACKUP_LOCAL_KEEP=` → `max(1, 0)` = GIỮ MỘT BẢN thay vì 7.
 */
it('§10.8 / fix I1 — BACKUP_LOCAL_KEEP để trống giữ 7 bản, không phải 1', function () {
    $restore = overrideProcessEnv(['BACKUP_LOCAL_KEEP' => '']);

    try {
        $this->refreshApplication();

        expect(config('vkcrm.backup.local_keep'))->toBe(7);
    } finally {
        $restore();
    }
});

it('§10.8 / fix I1 — BACKUP_LOCAL_KEEP có giá trị thì dùng giá trị đó; 0 rơi về 7, số âm thành 1 — không bao giờ dưới 1', function () {
    foreach (['3' => 3, '0' => 7, '-3' => 1] as $raw => $expected) {
        $restore = overrideProcessEnv(['BACKUP_LOCAL_KEEP' => $raw]);

        try {
            $this->refreshApplication();

            expect(config('vkcrm.backup.local_keep'))->toBe($expected);
        } finally {
            $restore();
        }
    }
});

it('§10.8 BACKUP_RCLONE_TIMEOUT để trống là 1800 giây, có giá trị thì dùng giá trị đó', function () {
    foreach (['' => 1800, '600' => 600, '0' => 1800, '-5' => 1] as $raw => $expected) {
        $restore = overrideProcessEnv(['BACKUP_RCLONE_TIMEOUT' => (string) $raw]);

        try {
            $this->refreshApplication();

            expect(config('vkcrm.backup.rclone.timeout'))->toBe($expected);
        } finally {
            $restore();
        }
    }
});

it('§10.8 BACKUP_MAX_STORAGE_MB để trống là 25000 MB, có giá trị thì backup:monitor dùng giá trị đó', function () {
    foreach (['' => 25000, '12000' => 12000, '0' => 25000, '-1' => 1] as $raw => $expected) {
        $restore = overrideProcessEnv(['BACKUP_MAX_STORAGE_MB' => (string) $raw]);

        try {
            $this->refreshApplication();

            expect(config('backup.monitor_backups.0.health_checks')[MaximumStorageInMegabytes::class])->toBe($expected);
        } finally {
            $restore();
        }
    }
});

it('§10.8 các biến số mới của sao lưu có trong .env.example kèm chú thích', function () {
    $envExample = file_get_contents(base_path('.env.example'));

    foreach (['BACKUP_RCLONE_TIMEOUT=', 'BACKUP_MAX_STORAGE_MB='] as $line) {
        expect($envExample)->toContain("\n".$line);
    }
});
