<?php

use App\Notifications\Backup\BackupHasFailedNotification;
use App\Notifications\Backup\CleanupHasFailedNotification;
use App\Notifications\Backup\UnhealthyBackupWasFoundNotification;
use App\Support\Backup\BackupDisks;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Spatie\Backup\Config\Config;

/*
|--------------------------------------------------------------------------
| §10.8 — hình dạng cấu hình `config/backup.php`
|--------------------------------------------------------------------------
*/

it('§10.8 / fix I1 — BACKUP_NOTIFY_EMAIL và MAIL_FROM_ADDRESS sai định dạng không làm hỏng lệnh artisan nào', function () {
    // `config/backup.php` đã chạy xong (đọc `env()`) TRƯỚC khi thân test bắt đầu, nên đổi
    // `config()` ở đây không kiểm được gì. Đặt biến môi trường rác rồi DỰNG LẠI ứng dụng
    // (`refreshApplication()`), để tệp cấu hình đọc lại chúng đúng như một tiến trình
    // `php artisan schedule:run` mới trên máy chủ.
    //
    // `Env::enablePutenv()` chỉ để XOÁ repository `env()` đã cache (putenv vốn đã bật): writer
    // bất biến của Dotenv nhớ những biến CHÍNH NÓ đã nạp từ `.env` ở lần dựng đầu và được phép
    // ghi đè chúng khi nạp lại. Không có dòng này, `MAIL_FROM_ADDRESS` trong `.env` của máy
    // lặng lẽ thắng giá trị rác đặt ở đây (đo được: câu tự kiểm bên dưới đỏ). Repository mới
    // không nhớ gì, nên giá trị đặt ở đây giữ nguyên như một biến môi trường thật của tiến trình.
    $garbage = [
        'BACKUP_NOTIFY_EMAIL' => 'ops@vidu.test;ke-toan@vidu.test',
        'MAIL_FROM_ADDRESS' => 'khong-phai-email',
    ];
    $saved = [];

    foreach ($garbage as $key => $value) {
        $saved[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    try {
        Env::enablePutenv();
        $this->refreshApplication();

        // Tự kiểm: giá trị rác thật sự đã tới ứng dụng mới dựng, không thì test xanh giả.
        expect(config('vkcrm.backup.notify_email'))->toBe($garbage['BACKUP_NOTIFY_EMAIL'])
            ->and(config('mail.from.address'))->toBe($garbage['MAIL_FROM_ADDRESS']);

        expect(fn () => app(Config::class))->not->toThrow(Throwable::class)
            ->and(Artisan::call('list'))->toBe(0);
    } finally {
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
    }
});

it('§10.8 / fix I6 — BACKUP_NAME có trong .env.example kèm chú thích', function () {
    $envExample = file_get_contents(base_path('.env.example'));

    expect($envExample)->toContain('BACKUP_NAME=');
});

it('§10.8 nguồn sao lưu gồm storage/app/private, không gồm storage/logs', function () {
    $include = config('backup.backup.source.files.include');
    $exclude = config('backup.backup.source.files.exclude');

    expect($include)->toContain(storage_path('app/private'))
        ->and($include)->not->toContain(storage_path('logs'))
        ->and($exclude)->toContain(storage_path('logs'));
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
