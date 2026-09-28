<?php

use App\Notifications\Backup\BackupHasFailedNotification;
use App\Notifications\Backup\CleanupHasFailedNotification;
use App\Notifications\Backup\UnhealthyBackupWasFoundNotification;
use App\Support\Backup\BackupDisks;
use Illuminate\Support\Str;
use Spatie\Backup\Notifications\Notifiable;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;

/*
|--------------------------------------------------------------------------
| VK-CRM — SPEC §10 mục 8, R3, M8a Task 1
|--------------------------------------------------------------------------
|
| Bản gốc `vendor/spatie/laravel-backup/config/backup.php` sao lưu TOÀN BỘ `base_path()` (mã
| nguồn lẫn tệp hồ sơ). Dự án này chỉ cần hai thứ: bản dump CSDL, và tệp hồ sơ khách hàng ở
| `storage/app/private` — mã nguồn đã có Git, sao lưu lại là lãng phí thời gian archive và có
| nguy cơ cuốn theo `.env` nếu ai đó lỡ gộp `base_path()` với `storage_path()` (đích đang mã hoá
| bằng CHÍNH mật khẩu lấy từ `.env` đó — sao lưu luôn cả `.env` là tự khoá chìa trong hộp).
*/

/*
 * Tên bản sao lưu: `BACKUP_NAME`, trống thì `APP_NAME`, trống nữa thì `VK-CRM`. Dùng ở ba chỗ
 * bên dưới (`backup.name`, `destination.filename_prefix`, `monitor_backups[0].name`), tính MỘT
 * lần để ba chỗ không thể lệch nhau.
 *
 * `?:`, KHÔNG `env('BACKUP_NAME', mặc-định)` (fix NB1, review vòng 2): `.env.example` giao
 * `BACKUP_NAME=` rỗng, và `env()` trả `''` cho một biến có mặt mà rỗng — tham số mặc định chỉ
 * dùng khi biến VẮNG MẶT. Bản trước vì vậy cho `backup.name = ''` và tiền tố `'-'`: archive rơi
 * thẳng vào gốc mọi disk đích, `backup:clean`/`backup:monitor` cũng làm việc trên gốc disk. Kiểm
 * ở `tests/Feature/Backup/BackupConfigTest.php` (các test "fix NB1").
 */
$backupName = env('BACKUP_NAME') ?: (env('APP_NAME') ?: 'VK-CRM');

return [

    'backup' => [
        /*
         * Tên ứng dụng dùng làm tên thư mục trên MỖI disk đích (SPEC §10 mục 8: "tên archive có
         * tiền tố nhận ra được của văn phòng"). `BACKUP_NAME` cho phép đặt riêng, khác
         * `APP_NAME` — hữu ích khi nhiều môi trường (staging/production) dùng chung một Google
         * Drive và cần phân biệt bằng mắt. Thứ tự rơi về: xem `$backupName` ở trên.
         */
        'name' => $backupName,

        'source' => [
            'files' => [
                /*
                 * CHỈ tệp hồ sơ khách hàng — SPEC §10 mục 8. Mã nguồn (`base_path()`) đã có Git,
                 * không cần sao lưu lại; sao lưu cả mã nguồn còn có nguy cơ cuốn theo `.env`
                 * (chứa `APP_KEY` và chính `BACKUP_ARCHIVE_PASSWORD`) vào TRONG archive mà nó
                 * đang dùng để tự mã hoá.
                 */
                'include' => [
                    storage_path('app/private'),
                ],

                /*
                 * Loại trừ tường minh dù `include` ở trên đã không chứa các thư mục này — một
                 * lưới an toàn cho lần sau ai đó mở rộng `include` ra `base_path()`.
                 */
                'exclude' => [
                    base_path('vendor'),
                    base_path('node_modules'),
                    storage_path('logs'),
                    storage_path('framework'),
                ],

                'follow_links' => false,
                'ignore_unreadable_directories' => false,

                /*
                 * M8a Task 3 (R3, phục hồi thật): `null` (mặc định gói) đặt tên mục trong archive
                 * bằng đường dẫn TUYỆT ĐỐI của container đã tạo archive, bỏ dấu `/` đầu
                 * (`Zip::determineNameOfFileInZip()`) — ví dụ
                 * `var/www/html/storage/app/private/1/tep.pdf`. Đường đó chỉ đúng NẾU máy khôi
                 * phục dùng đúng cùng một đường lắp `base_path()` với máy đã sao lưu — một trùng
                 * hợp, không phải một bất biến, và `tools/backup/restore-drill.sh` (chạy trên máy
                 * dev, `base_path()` khác `/var/www/html` của container backup:run) sẽ không tự
                 * khớp được mục nào về `storage/app/private` nếu không đoán/patch đường dẫn.
                 *
                 * `base_path()` làm mục trong archive RELATIVE tới gốc ứng dụng (ví dụ
                 * `storage/app/private/1/tep.pdf`), bất kể `base_path()` tuyệt đối của máy đang
                 * chạy `backup:run` là gì. Bước khôi phục vì vậy chỉ cần tách mục có tiền tố
                 * `storage/app/private/` và chép PHẦN SAU tiền tố đó vào đúng thư mục cùng tên
                 * trên máy sạch — không cần biết máy sao lưu chạy trong container nào.
                 *
                 * Không có test Task 1 nào khoá giá trị `null` cũ (kiểm bằng
                 * `grep -rn relative_path tests/`, không ra kết quả) — đổi ở đây không cần sửa
                 * test nào đã có.
                 */
                'relative_path' => base_path(),
            ],

            /*
             * CSDL mặc định của ứng dụng (SPEC §10 mục 8: "cả CSDL lẫn thư mục tệp"). Hỗ trợ cả
             * MySQL/MariaDB (production) và SQLite (test — không có `mariadb-dump`/`mysqldump`
             * trong image dev, xem docs/research/2026-09-26-sao-luu.md).
             */
            'databases' => [
                env('DB_CONNECTION', 'mysql'),
            ],
        ],

        'database_dump_compressor' => null,
        'database_dump_file_timestamp_format' => null,
        'database_dump_filename_base' => 'database',
        'database_dump_file_extension' => '',

        'destination' => [
            'compression_method' => ZipArchive::CM_DEFAULT,
            'compression_level' => 9,

            /*
             * Tiền tố tên archive — SPEC §10 mục 8. `Str::slug` để tên tệp không mang dấu tiếng
             * Việt hay khoảng trắng (một số adapter/hệ điều hành đích không chịu được).
             */
            'filename_prefix' => Str::slug($backupName).'-',

            /*
             * Danh sách disk đích — `BACKUP_DISKS` (SPEC §10 mục 8: "đẩy ra một disk ngoài máy
             * chủ", và Task 1 brief: "ra được nhiều đích cùng lúc chỉ bằng cấu hình"). Phân tích
             * qua `BackupDisks::parse()` (test riêng ở
             * `tests/Unit/Support/Backup/BackupDisksTest.php`) — tách khỏi việc gói này có đọc
             * đúng giá trị hay không.
             */
            'disks' => BackupDisks::parse(env('BACKUP_DISKS')),

            /*
             * BẮT BUỘC true: một disk hỏng (Google Drive hết hạn token, máy chủ văn phòng mất
             * điện) không được kéo theo mất luôn bản sao ở disk còn lại. Gói tự phát
             * `BackupHasFailed` cho MỖI disk hỏng khi cờ này bật (xem
             * `Spatie\Backup\Tasks\Backup\BackupJob::copyToBackupDestinations()`), nên
             * `App\Notifications\Backup\BackupHasFailedNotification` vẫn nhận đúng tên disk hỏng
             * dù các disk khác thành công.
             */
            'continue_on_failure' => true,
        ],

        'temporary_directory' => storage_path('app/backup-temp'),

        /*
         * Mật khẩu mã hoá archive (SPEC §10 mục 8, R3). Rỗng ở local/testing (không bắt buộc
         * cấu hình bí mật để chạy máy dev); BẮT BUỘC ở production —
         * `App\Actions\Backup\GuardBackupEncryption` làm lượt sao lưu thất bại (không archive,
         * có thư báo lỗi) khi thiếu mật khẩu, hoặc khi máy chủ không mã hoá được bằng thuật toán
         * ở `encryption` bên dưới. Chỗ móc và lý do ở docblock của lớp đó.
         */
        'password' => env('BACKUP_ARCHIVE_PASSWORD'),

        /*
         * AES-256 tường minh — "thuật toán mặc định mạnh nhất gói hỗ trợ" (Task 1 brief).
         * `aes256` và `default` cùng dùng `ZipArchive::EM_AES_256`
         * (`Spatie\Backup\Enums\Encryption::algorithm()`); chọn tường minh để không phụ thuộc
         * gói đổi ý nghĩa của `default` ở một bản phát hành sau.
         */
        'encryption' => 'aes256',

        'verify_backup' => false,
        'tries' => 1,
        'retry_delay' => 0,
    ],

    'notifications' => [
        'notifications' => [
            /*
             * Ba lớp CỦA DỰ ÁN thay bản gốc của gói (Task 1 brief: "thay các lớp notification
             * mặc định của gói bằng lớp của dự án"). Móc bằng CLASS BASENAME, không phải khoá
             * sự kiện: `Spatie\Backup\Notifications\EventHandler::determineNotification()` tìm
             * trong mảng này một khoá có `class_basename() === class_basename($event).
             * 'Notification'` — ba lớp dưới đây CỐ Ý giữ đúng basename gốc
             * (`BackupHasFailedNotification`, `CleanupHasFailedNotification`,
             * `UnhealthyBackupWasFoundNotification`) dù nằm ở namespace `App\Notifications\
             * Backup`, để phép khớp đó vẫn đúng. Đổi tên lớp mà không đổi ở đây là gãy im lặng:
             * gói sẽ lặng lẽ rơi về notification MẶC ĐỊNH của chính nó (tiếng Anh, không qua
             * `outbound_messages`).
             */
            BackupHasFailedNotification::class => ['mail'],
            UnhealthyBackupWasFoundNotification::class => ['mail'],
            CleanupHasFailedNotification::class => ['mail'],

            /*
             * Thư THÀNH CÔNG — Task 1 brief: "không cần thư thành công". Giữ nguyên KHOÁ là lớp
             * GỐC của gói (không xoá khoá — xem lý do ở
             * `Spatie\Backup\Notifications\BaseNotification::via()`: nó tự đọc mảng này bằng
             * `static::class`, và mảng RỖNG hay THIẾU KHOÁ với các lớp mặc định này gây lỗi
             * "Undefined array key" ngay khi một backup THÀNH CÔNG — tức đúng lúc không ai để ý
             * theo dõi log). Kênh rỗng (`[]`) là chỗ tắt đúng: `via()` lọc mảng rỗng, không kênh
             * nào chạy, `toMail()` không bao giờ được gọi.
             */
            BackupWasSuccessfulNotification::class => [],
            HealthyBackupWasFoundNotification::class => [],
            CleanupWasSuccessfulNotification::class => [],
        ],

        'notifiable' => Notifiable::class,

        'mail' => [
            /*
             * CẢ KHỐI NÀY KHÔNG DÙNG ĐỂ GỬI THƯ, và cố ý là HẰNG SỐ — không đọc biến môi trường
             * nào (fix I1, review vòng 1).
             *
             * Vì sao không dùng để gửi: ba lớp `App\Notifications\Backup\*` trả về một Mailable
             * (`App\Mail\Staff\BackupAlert`) từ `toMail()`, và
             * `Illuminate\Notifications\Channels\MailChannel::send()` gặp Mailable thì gọi thẳng
             * `$message->send($mailer)` — không hỏi `routeNotificationFor('mail')`, nên `to` ở
             * đây không bao giờ được đọc. Người nhận THẬT (một địa chỉ hoặc danh sách phẩy, từng
             * địa chỉ được validate, địa chỉ hỏng bị bỏ qua và ghi log) do
             * `App\Actions\Backup\ResolveBackupNotificationRecipients` tính từ
             * `config('vkcrm.backup.notify_email')`. Người gửi THẬT là người gửi chung của
             * mailer (`config('mail.from')`), như mọi thư khác của dự án.
             *
             * Vì sao phải là hằng số: `Spatie\Backup\Config\NotificationMailConfig::fromArray()`
             * validate `to`, và `NotificationMailSenderConfig::fromArray()` validate
             * `from.address`, bằng `filter_var(..., FILTER_VALIDATE_EMAIL)` — sai thì ném
             * `InvalidConfig`. Việc đó chạy mỗi khi `Spatie\Backup\Config\Config` được dựng, và
             * nó được dựng ở constructor của các lệnh `backup:*`, những lệnh Artisan khởi tạo mỗi
             * khi console application khởi động, BẤT KỂ lệnh nào được gọi. Các bản trước đưa
             * `BACKUP_NOTIFY_EMAIL` rồi `MAIL_FROM_ADDRESS` vào đây: một danh sách phẩy hay một
             * lỗi gõ tay ở `.env` làm chết MỌI lệnh artisan, kể cả `schedule:run` (sao lưu, giám
             * sát, `CheckDeadlines`) và `queue:work`. Hằng số không phụ thuộc người vận hành thì
             * không hỏng theo cách đó. Kiểm ở `tests/Feature/Backup/BackupConfigTest.php`, test
             * "fix I1 — BACKUP_NOTIFY_EMAIL và MAIL_FROM_ADDRESS sai định dạng không làm hỏng lệnh
             * artisan nào".
             *
             * `localhost.localdomain` không trỏ tới hộp thư có thật nào, nhưng vẫn qua được
             * `FILTER_VALIDATE_EMAIL`.
             */
            'to' => 'backup-placeholder@localhost.localdomain',

            'from' => [
                'address' => 'backup-placeholder@localhost.localdomain',
                'name' => 'VK-CRM',
            ],
        ],

        'slack' => [
            'webhook_url' => '',
            'channel' => null,
            'username' => null,
            'icon' => null,
        ],

        'discord' => [
            'webhook_url' => '',
            'username' => '',
            'avatar_url' => '',
        ],

        'webhook' => [
            'url' => '',
        ],
    ],

    'log_channel' => null,

    'monitor_backups' => [
        [
            'name' => $backupName,
            // Cùng danh sách disk với `destination.disks` ở trên — nếu không, `backup:monitor`
            // âm thầm không giám sát disk mà `backup:run` vừa ghi vào.
            'disks' => BackupDisks::parse(env('BACKUP_DISKS')),
            'health_checks' => [
                MaximumAgeInDays::class => 1,

                /*
                 * Hạn mức dung lượng MỖI disk đích (MB), `BACKUP_MAX_STORAGE_MB`, trống thì 25000
                 * (lượt rà soát cuối M8a — bản trước là hằng số 5000 của gói). 5000 MB nghĩa là
                 * `backup:monitor` báo "không lành mạnh" MỖI NGÀY ngay khi 7 bản trên máy chủ
                 * (`BACKUP_LOCAL_KEEP`) vượt 5 GB, tức mỗi archive chỉ cần quá ~700 MB — một văn
                 * phòng vài năm tuổi đã quá mức đó, và một thư báo động giả mỗi sáng dạy người
                 * nhận bỏ qua thư sao lưu. 25000 MB (~24 GB, khoảng 40% ổ 60 GB của máy chủ khuyến
                 * nghị ở SPEC §2) chứa đủ 7 bản của một archive 3 GB; vượt mức đó thật sự là lúc
                 * cần xem lại ổ đĩa. Khi CHƯA bật Google Drive, máy chủ giữ 30 bản — khi đó
                 * `App\Actions\Backup\GuardOffServerBackupDestination` đã báo lỗi mỗi đêm ở
                 * production, nên hạn mức này không phải lớp báo động duy nhất.
                 *
                 * `?:` + `max(1, ...)`, cùng thành ngữ với `vkcrm.backup.local_keep`: trống là mặc
                 * định, không phải 0 (0 MB = báo động mọi ngày).
                 */
                MaximumStorageInMegabytes::class => max(1, (int) (env('BACKUP_MAX_STORAGE_MB') ?: 25000)),
            ],
        ],
    ],

    'cleanup' => [
        'strategy' => DefaultStrategy::class,

        'default_strategy' => [
            /*
             * Giữ 30 bản hằng ngày (SPEC §10 mục 8). Lịch chạy `backup:clean` NGAY TRƯỚC
             * `backup:run` mỗi ngày (routes/console.php) — tức lệnh dọn luôn thấy N-1 bản đã có
             * khi bản thứ N sắp được tạo. Với `DefaultStrategy::removeBackupsOlderThan()` dùng so
             * sánh CHẶT (`<`, không `<=`) trên mốc `now() - keep_all_backups_for_days`, hằng số
             * phải là 29 — không phải 30 — để sau đúng 31 lượt "dọn rồi sao lưu" liên tiếp mỗi
             * ngày một lần, số bản còn lại hội tụ về đúng 30 (kiểm bằng mô phỏng ngày tháng thật,
             * không suy luận suông — xem `tests/Feature/Backup/BackupCleanupTest.php`, test
             * "§10.8 sau 31 lượt dọn-rồi-sao-lưu liên tiếp mỗi ngày một lần, còn đúng 30 bản").
             */
            'keep_all_backups_for_days' => 29,
            'keep_daily_backups_for_days' => 0,
            'keep_weekly_backups_for_weeks' => 0,
            'keep_monthly_backups_for_months' => 0,
            'keep_yearly_backups_for_years' => 0,
            'delete_oldest_backups_when_using_more_megabytes_than' => null,
        ],

        'tries' => 1,
        'retry_delay' => 0,
    ],

];
