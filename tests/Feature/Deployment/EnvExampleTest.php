<?php

/*
|--------------------------------------------------------------------------
| `.env.example` là bản mẫu ĐỦ của `.env` thật (kế hoạch M8, "sẽ cắn" #4; M8 Task 7)
|--------------------------------------------------------------------------
|
| `.env` thật không nằm trong repo, nên người triển khai chỉ biết tới những biến có mặt trong
| `.env.example`. Một biến dự án đọc mà vắng ở đây là một biến bị bỏ trống đúng lúc nó quan trọng
| nhất (đã xảy ra với mười lăm biến `BRAND_*`, gồm bốn thông tin pháp lý của chân thư). Và một
| biến có mặt ở đây mà không ai đọc là một lời nói dối (đã xảy ra với `BACKUP_DISK=s3`, sống thêm
| hai milestone sau khi `BACKUP_DISKS` thay nó).
|
| Hai chiều, quét bằng regex trên mã nguồn — không danh sách biến nào chép tay ở đây, ngoài các
| ngoại lệ tường minh bên dưới, mỗi nhóm một lý do.
|
| "Có mặt" nghĩa là một dòng `KEY=…` hoặc `# KEY=…` (dòng chú thích): vài biến CỐ Ý để dạng chú
| thích vì một dòng `KEY=` trống KHÁC với không khai — `env('KEY', mặc-định)` trả chuỗi rỗng, không
| trả mặc định (`TRUSTED_PROXIES`, các biến `BRAND_*` có giá trị mặc định).
*/

/**
 * Biến framework/gói mà dự án KHÔNG dùng hoặc giữ nguyên mặc định — có trong `config/*.php` vì
 * Laravel và các gói phát hành tệp cấu hình đầy đủ, không phải vì dự án cần người vận hành điền.
 *
 * @return array<string, list<string>>
 */
function envExampleFrameworkOnly(): array
{
    return [
        // Driver/dịch vụ dự án không dùng: chỉ database cho cache/queue/session (SPEC §2), SMTP cho thư.
        'redis' => ['REDIS_BACKOFF_ALGORITHM', 'REDIS_BACKOFF_BASE', 'REDIS_BACKOFF_CAP', 'REDIS_CACHE_CONNECTION', 'REDIS_CACHE_DB', 'REDIS_CACHE_LOCK_CONNECTION', 'REDIS_CLIENT', 'REDIS_CLUSTER', 'REDIS_DB', 'REDIS_HOST', 'REDIS_MAX_RETRIES', 'REDIS_PASSWORD', 'REDIS_PERSISTENT', 'REDIS_PORT', 'REDIS_PREFIX', 'REDIS_QUEUE', 'REDIS_QUEUE_CONNECTION', 'REDIS_QUEUE_RETRY_AFTER', 'REDIS_URL', 'REDIS_USERNAME'],
        'memcached' => ['MEMCACHED_HOST', 'MEMCACHED_PASSWORD', 'MEMCACHED_PERSISTENT_ID', 'MEMCACHED_PORT', 'MEMCACHED_USERNAME'],
        // AWS: đĩa s3, cache dynamodb, hàng đợi sqs, thư ses — không cái nào được dùng (gói adapter s3
        // không được cài; khối AWS cũ của `.env.example` đã xoá ở Task 7 cùng `BACKUP_DISK`).
        'aws' => ['AWS_ACCESS_KEY_ID', 'AWS_BUCKET', 'AWS_DEFAULT_REGION', 'AWS_ENDPOINT', 'AWS_SECRET_ACCESS_KEY', 'AWS_URL', 'AWS_USE_PATH_STYLE_ENDPOINT', 'DYNAMODB_CACHE_TABLE', 'DYNAMODB_ENDPOINT', 'SQS_PREFIX', 'SQS_QUEUE', 'SQS_SUFFIX'],
        'beanstalkd' => ['BEANSTALKD_QUEUE', 'BEANSTALKD_QUEUE_HOST', 'BEANSTALKD_QUEUE_RETRY_AFTER'],
        'dịch vụ thư/thông báo khác' => ['POSTMARK_API_KEY', 'POSTMARK_MESSAGE_STREAM_ID', 'RESEND_API_KEY', 'SLACK_BOT_USER_DEFAULT_CHANNEL', 'SLACK_BOT_USER_OAUTH_TOKEN', 'MAIL_EHLO_DOMAIN', 'MAIL_LOG_CHANNEL', 'MAIL_SENDMAIL_PATH', 'MAIL_URL'],
        'kênh log khác' => ['LOG_DAILY_DAYS', 'LOG_DEPRECATIONS_TRACE', 'LOG_PAPERTRAIL_HANDLER', 'LOG_SLACK_EMOJI', 'LOG_SLACK_USERNAME', 'LOG_SLACK_WEBHOOK_URL', 'LOG_STDERR_FORMATTER', 'LOG_SYSLOG_FACILITY', 'PAPERTRAIL_PORT', 'PAPERTRAIL_URL'],
        // Cơ sở dữ liệu: mặc định của `config/database.php` là đúng cho MariaDB (utf8mb4).
        'database' => ['DB_CACHE_CONNECTION', 'DB_CACHE_LOCK_CONNECTION', 'DB_CACHE_LOCK_TABLE', 'DB_CACHE_TABLE', 'DB_CHARSET', 'DB_COLLATION', 'DB_ENCRYPT', 'DB_FOREIGN_KEYS', 'DB_QUEUE', 'DB_QUEUE_CONNECTION', 'DB_QUEUE_RETRY_AFTER', 'DB_QUEUE_TABLE', 'DB_SOCKET', 'DB_SSLMODE', 'DB_TRUST_SERVER_CERTIFICATE', 'DB_URL', 'MYSQL_ATTR_SSL_CA'],
        'cache, phiên, hàng đợi lỗi' => ['CACHE_PREFIX', 'CACHE_STORAGE_DISK', 'CACHE_STORAGE_PATH', 'SESSION_CONNECTION', 'SESSION_COOKIE', 'SESSION_EXPIRE_ON_CLOSE', 'SESSION_HTTP_ONLY', 'SESSION_PARTITIONED_COOKIE', 'SESSION_SAME_SITE', 'SESSION_STORE', 'SESSION_TABLE', 'QUEUE_FAILED_DRIVER'],
        'xác thực' => ['AUTH_GUARD', 'AUTH_MODEL', 'AUTH_PASSWORD_BROKER', 'AUTH_PASSWORD_RESET_TOKEN_TABLE', 'AUTH_PASSWORD_TIMEOUT', 'AUTH_TIMEBOX_DURATION'],
        // `APP_PREVIOUS_KEYS` — xoay khoá mã hoá; docs/CAI-DAT.md (cảnh báo APP_KEY) nói vì sao nó
        // KHÔNG cứu được cột so trùng CCCD, nên không mời ai điền nó trong bản mẫu.
        'app' => ['APP_MAINTENANCE_STORE', 'APP_PREVIOUS_KEYS'],
        'spatie/laravel-activitylog' => ['ACTIVITY_LOGGER_DB_CONNECTION', 'ACTIVITY_LOGGER_ENABLED', 'ACTIVITY_LOGGER_TABLE_NAME'],
        // spatie/laravel-medialibrary: dự án chưa đăng ký chuyển đổi ảnh/video nào (xem `gd` ở
        // `RunPreflight`), mọi tệp ở đĩa `private`.
        'spatie/laravel-medialibrary' => ['ENABLE_MEDIA_LIBRARY_VAPOR_UPLOADS', 'FFMPEG_PATH', 'FFMPEG_THREADS', 'FFMPEG_TIMEOUT', 'FFPROBE_PATH', 'FORCE_MEDIA_LIBRARY_LAZY_LOADING', 'IMAGE_DRIVER', 'MEDIA_CONVERSIONS_DISK', 'MEDIA_DISK', 'MEDIA_DOWNLOADER_SSL', 'MEDIA_PREFIX', 'MEDIA_QUEUE', 'MEDIA_TEMPORARY_URL_DEFAULT_LIFETIME', 'QUEUE_CONVERSIONS_AFTER_DB_COMMIT', 'QUEUE_CONVERSIONS_BY_DEFAULT'],
        'livewire' => ['LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'],
    ];
}

/**
 * Biến có trong `.env.example` mà không mã PHP nào của dự án đọc — được đọc ở NƠI KHÁC. Ba dòng
 * của bộ cài Sail cũ (`VITE_APP_NAME`, `WWWGROUP`, `WWWUSER`), không nơi nào đọc, đã được dọn khỏi
 * bản mẫu ở lượt nghiệm thu bản 1.0 (M8 Task 8), nên không còn mục "chưa dọn".
 *
 * @return array<string, list<string>>
 */
function envExampleReadElsewhere(): array
{
    return [
        // `compose.yaml` (môi trường dev `bin/dev`) — cổng map ra máy và số worker của `artisan serve`.
        'compose.yaml' => ['APP_PORT', 'FORWARD_DB_PORT', 'FORWARD_MAILPIT_PORT', 'FORWARD_MAILPIT_DASHBOARD_PORT', 'PHP_CLI_SERVER_WORKERS'],
        // Tệp cấu hình mặc định nằm trong `vendor/laravel/framework/config/` (dự án không phát hành
        // `hashing.php`, `broadcasting.php`) — vẫn được Laravel nạp và đọc.
        'vendor/laravel/framework/config' => ['BCRYPT_ROUNDS', 'BROADCAST_CONNECTION'],
    ];
}

/** @return list<string> */
function envExampleKeys(): array
{
    preg_match_all('/^#?[ \t]*([A-Z][A-Z0-9_]*)=/m', (string) file_get_contents(base_path('.env.example')), $matches);

    return array_values(array_unique($matches[1]));
}

/**
 * Mọi khoá `env('…')` trong các tệp PHP của những thư mục đã cho.
 *
 * @param  list<string>  $directories
 * @return list<string>
 */
function envKeysReadIn(array $directories): array
{
    $keys = [];

    foreach ($directories as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all('/\benv\(\s*[\'"]([A-Z][A-Z0-9_]*)[\'"]/', (string) file_get_contents($file->getPathname()), $matches);
            array_push($keys, ...$matches[1]);
        }
    }

    $keys = array_values(array_unique($keys));
    sort($keys);

    return $keys;
}

it('mọi biến env() mà config/ và app/ đọc đều có dòng mẫu trong .env.example (trừ biến framework không dùng)', function () {
    $documented = envExampleKeys();
    $frameworkOnly = array_merge(...array_values(envExampleFrameworkOnly()));

    $missing = array_values(array_diff(
        envKeysReadIn([config_path(), app_path()]),
        $documented,
        $frameworkOnly,
    ));

    expect($missing)->toBe([], 'Thiếu dòng mẫu trong .env.example cho: '.implode(', ', $missing));
});

it('mọi dòng của .env.example đều được đọc ở đâu đó — không biến chết như BACKUP_DISK', function () {
    $read = envKeysReadIn([config_path(), app_path(), base_path('vendor/laravel/framework/config')]);
    $elsewhere = array_merge(...array_values(envExampleReadElsewhere()));

    $dead = array_values(array_diff(envExampleKeys(), $read, $elsewhere));

    expect($dead)->toBe([], 'Dòng .env.example không ai đọc: '.implode(', ', $dead));
});

it('các ngoại lệ "đọc ở nơi khác" là thật: compose.yaml đọc đúng những biến được kể', function () {
    $compose = (string) file_get_contents(base_path('compose.yaml'));

    foreach (envExampleReadElsewhere()['compose.yaml'] as $key) {
        expect($compose)->toContain('${'.$key);
    }

    expect(envKeysReadIn([base_path('vendor/laravel/framework/config')]))
        ->toContain('BCRYPT_ROUNDS')
        ->toContain('BROADCAST_CONNECTION');
});

it('danh sách ngoại lệ framework không che một biến mà .env.example đã khai (ngoại lệ thừa = lưới thủng)', function () {
    $frameworkOnly = array_merge(...array_values(envExampleFrameworkOnly()));

    expect(array_values(array_intersect($frameworkOnly, envExampleKeys())))->toBe([]);
});

/**
 * Mười lăm biến thương hiệu `config/vkcrm.php` đọc (đếm 2026-09-28, brief Task 7) — test riêng
 * cho chúng vì bốn trong số đó là thông tin pháp lý của chân thư (`App\Support\BrandFooter`) và
 * `vkcrm:preflight` báo VÀNG khi chúng trống.
 */
it('đủ mười lăm biến BRAND_* trong .env.example', function () {
    $brand = array_values(array_filter(envKeysReadIn([config_path()]), fn (string $key) => str_starts_with($key, 'BRAND_')));

    expect($brand)->toHaveCount(15)
        ->and(array_values(array_diff($brand, envExampleKeys())))->toBe([]);
});

/**
 * Biến `BRAND_*` CÓ giá trị mặc định trong `config/vkcrm.php` phải ở dạng chú thích (`# KEY=…`) —
 * một dòng `BRAND_LEGAL_NAME=` để trống không phải "dùng mặc định": `env()` trả chuỗi rỗng và tên
 * văn phòng biến mất khỏi trang đăng nhập, thư và chân thư.
 */
it('không biến BRAND_* có mặc định nào bị khai trống (chuỗi rỗng đè mặc định)', function () {
    $example = (string) file_get_contents(base_path('.env.example'));

    // Lượt quét trước bản 1.0: bản cũ chỉ khẳng định trên các dòng trống ĐANG CÓ (`->each`), mà
    // `.env.example` không có dòng trống nào — test không khẳng định gì (test "risky" duy nhất của bộ).
    // Nay đọc tập biến CÓ mặc định thẳng từ `config/vkcrm.php` (`env('BRAND_X', mặc định)`) rồi đòi: tập
    // đó không rỗng, không biến nào trong đó là một dòng trống đang hiệu lực, và mỗi biến có một dòng
    // mẫu mang giá trị (thường là dòng chú thích `# BRAND_X=giá trị mặc định`).
    preg_match_all("/env\\('(BRAND_[A-Z_]+)',\\s*[^)\\s]/", (string) file_get_contents(config_path('vkcrm.php')), $defaults);
    $withDefault = array_values(array_unique($defaults[1]));

    preg_match_all('/^(BRAND_[A-Z_]+)=[ \t]*$/m', $example, $blank);

    expect($withDefault)->toContain('BRAND_LEGAL_NAME', 'BRAND_SHORT_NAME', 'BRAND_HOTLINE')
        ->and(array_values(array_intersect($blank[1], $withDefault)))->toBe([]);

    foreach ($withDefault as $key) {
        expect(preg_match('/^(# )?'.$key.'=\S/m', $example))->toBe(1, "{$key} có mặc định nhưng không có dòng mẫu mang giá trị");
    }
});

/**
 * Final review I3 (lúc gộp với `main`): mỗi biến chỉ có MỘT dòng mẫu, tính cả dòng chú thích
 * `# KEY=…`. `main` (M6 Task 10) đã có một khối "Nhận diện thương hiệu" khai `BRAND_*` theo kiểu
 * ngược lại (mặc định bỏ chú thích, bốn thông tin pháp lý chú thích) ở đúng chỗ cuối tệp mà làn này
 * thêm khối của mình. Giữ cả hai khi gộp thì mỗi `BRAND_*` có hai dòng: phpdotenv lấy dòng ĐẦU, nên
 * người vận hành sửa dòng thứ hai và không có gì đổi. Test này đỏ ngay khi điều đó xảy ra.
 */
it('mỗi biến chỉ có đúng một dòng mẫu trong .env.example, kể cả dòng chú thích (gộp hai khối BRAND_* là đỏ)', function () {
    preg_match_all('/^#?[ \t]*([A-Z][A-Z0-9_]*)=/m', (string) file_get_contents(base_path('.env.example')), $matches);

    $duplicates = array_keys(array_filter(array_count_values($matches[1]), fn (int $count): bool => $count > 1));

    expect($duplicates)->toBe([], 'Biến có hơn một dòng mẫu: '.implode(', ', $duplicates));
});
