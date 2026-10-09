<?php

use Illuminate\Support\Env;

/*
|--------------------------------------------------------------------------
| M14 Task 1 — khối cấu hình `vkcrm.storage`, kết nối hàng đợi `storage`, dòng mẫu `.env.example`
|--------------------------------------------------------------------------
|
| Cấu hình đọc `env()` lúc NẠP tệp, tức trước khi thân test chạy, nên đổi `config()` trong test
| không kiểm được gì về cách tệp đọc biến môi trường. Các test dưới đây nạp lại CHÍNH tệp cấu hình
| với biến môi trường của tiến trình đã đặt (cùng cách `BackupConfigTest`), rồi trả mọi thứ về như
| cũ trong `finally`.
*/

/** Mọi biến môi trường mà M14 Task 1 thêm vào `config/vkcrm.php`. */
const M14_STORAGE_ENV_KEYS = [
    'DOCUMENT_STORAGE',
    'DOCUMENT_STAGING_GRACE_HOURS',
    'DOCUMENT_PUSH_ALERT_MINUTES',
    'GOOGLE_DRIVE_CREDENTIALS_PATH',
    'GOOGLE_DRIVE_SHARED_DRIVE_ID',
    'GOOGLE_DRIVE_ROOT_FOLDER_ID',
    'GOOGLE_DRIVE_ALLOWED_MEMBERS',
    'GOOGLE_DRIVE_CHUNK_MB',
    'DOCUMENT_OFFICE_RECEIPTS_PATH',
];

/**
 * Khối `storage` của `config/vkcrm.php`, nạp lại với các biến môi trường đã cho. Mọi biến M14 không
 * nêu tên được coi là VẮNG MẶT (không phải rỗng), để `.env` cục bộ của máy chạy test không lọt vào.
 *
 * `Env::enablePutenv()` chỉ để dựng lại repository mà `env()` đã cache (đọc docblock của
 * `overrideProcessEnv()` ở `BackupConfigTest`).
 *
 * @param  array<string, string>  $vars
 * @return array<string, mixed>
 */
function storageConfigUnderEnv(array $vars = []): array
{
    $saved = [];

    foreach (M14_STORAGE_ENV_KEYS as $key) {
        $saved[$key] = [
            getenv($key),
            array_key_exists($key, $_ENV) ? $_ENV[$key] : null,
            array_key_exists($key, $_SERVER) ? $_SERVER[$key] : null,
        ];

        if (array_key_exists($key, $vars)) {
            putenv("{$key}={$vars[$key]}");
            $_ENV[$key] = $_SERVER[$key] = $vars[$key];
        } else {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    Env::enablePutenv();

    try {
        return (require config_path('vkcrm.php'))['storage'];
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

        Env::enablePutenv();
    }
}

it('mặc định khi không biến nào được khai: công tắc local, các số và store đúng kế hoạch', function () {
    expect(storageConfigUnderEnv())->toBe([
        'driver' => 'local',
        'staging_grace_hours' => 24,
        'push_alert_minutes' => 60,
        'lock_store' => 'database',
        'lock_ttl_seconds' => 2100,
        'google_drive' => [
            'credentials_path' => null,
            'shared_drive_id' => null,
            'root_folder_id' => null,
            'allowed_members' => null,
            'connect_timeout' => 5,
            'timeout' => 30,
            'read_timeout' => 60,
            'chunk_mb' => 8,
            'token_cache_store' => 'file',
            'breaker_store' => 'file',
            'item_warn' => 300000,
            'item_limit' => 400000,
        ],
        'office' => [
            'receipts_path' => null,
            'max_age_hours' => 36,
            'purge_margin_hours' => 24,
            'receipt_max_bytes' => 33554432,
            // M14 Task 7: hạn riêng của lượt nhập biên nhận văn phòng, không 1800 của sao lưu.
            'rclone_timeout' => 120,
        ],
    ]);
});

it('DOCUMENT_STORAGE trống là local; giá trị khác được giữ NGUYÊN, kể cả khi gõ sai', function (string $raw, string $expected) {
    expect(storageConfigUnderEnv(['DOCUMENT_STORAGE' => $raw])['driver'])->toBe($expected);
})->with([
    'trống' => ['', 'local'],
    'local' => ['local', 'local'],
    'google_drive' => ['google_drive', 'google_drive'],
    // Không chuẩn hoá về local: DocumentStore::driverIsValid() phải nhìn thấy lỗi gõ để báo ĐỎ.
    'gõ sai' => ['gooogle_drive', 'gooogle_drive'],
]);

/**
 * Thành ngữ `?:` + `max(1, …)` của `config/backup.php`: trống và `0` là MẶC ĐỊNH (không phải 0),
 * số âm bị chặn về 1, số hợp lệ đi qua.
 */
it('số giờ ân hạn vùng đệm và số phút cảnh báo tồn đọng theo thành ngữ ?: + max(1, …)', function (string $key, string $configKey, string $raw, int $expected) {
    expect(storageConfigUnderEnv([$key => $raw])[$configKey])->toBe($expected);
})->with([
    'ân hạn trống' => ['DOCUMENT_STAGING_GRACE_HOURS', 'staging_grace_hours', '', 24],
    'ân hạn 0' => ['DOCUMENT_STAGING_GRACE_HOURS', 'staging_grace_hours', '0', 24],
    'ân hạn âm' => ['DOCUMENT_STAGING_GRACE_HOURS', 'staging_grace_hours', '-5', 1],
    'ân hạn 48' => ['DOCUMENT_STAGING_GRACE_HOURS', 'staging_grace_hours', '48', 48],
    'cảnh báo trống' => ['DOCUMENT_PUSH_ALERT_MINUTES', 'push_alert_minutes', '', 60],
    'cảnh báo 0' => ['DOCUMENT_PUSH_ALERT_MINUTES', 'push_alert_minutes', '0', 60],
    'cảnh báo âm' => ['DOCUMENT_PUSH_ALERT_MINUTES', 'push_alert_minutes', '-5', 1],
    'cảnh báo 90' => ['DOCUMENT_PUSH_ALERT_MINUTES', 'push_alert_minutes', '90', 90],
]);

/**
 * R9: khối tải lên là số nguyên MiB từ 1 tới 64, nên luôn là bội của 256 KiB như Google đòi. Ngoài
 * khoảng đó (trống, 0, âm, quá 64, không phải số) thì rơi về 8 — không chặn về biên: 0 hay 100 là
 * cấu hình sai, và một khối 64 MiB đặt nhầm giữ 64 MiB bộ nhớ cho mỗi lượt tải.
 */
it('GOOGLE_DRIVE_CHUNK_MB: 1–64 đi qua, mọi giá trị khác rơi về 8', function (string $raw, int $expected) {
    expect(storageConfigUnderEnv(['GOOGLE_DRIVE_CHUNK_MB' => $raw])['google_drive']['chunk_mb'])->toBe($expected);
})->with([
    'trống' => ['', 8],
    '0' => ['0', 8],
    'âm' => ['-4', 8],
    'quá 64' => ['65', 8],
    'không phải số' => ['abc', 8],
    'biên dưới 1' => ['1', 1],
    'biên trên 64' => ['64', 64],
    '16' => ['16', 16],
]);

it('đường dẫn khoá, mã Shared Drive, thư mục gốc, thành viên và đường biên nhận: trống là null, có thì giữ nguyên', function () {
    $blank = storageConfigUnderEnv([
        'GOOGLE_DRIVE_CREDENTIALS_PATH' => '',
        'GOOGLE_DRIVE_SHARED_DRIVE_ID' => '',
        'GOOGLE_DRIVE_ROOT_FOLDER_ID' => '',
        'GOOGLE_DRIVE_ALLOWED_MEMBERS' => '',
        'DOCUMENT_OFFICE_RECEIPTS_PATH' => '',
    ]);

    $filled = storageConfigUnderEnv([
        'GOOGLE_DRIVE_CREDENTIALS_PATH' => '/etc/vkcrm/google-drive-key.json',
        'GOOGLE_DRIVE_SHARED_DRIVE_ID' => '0AbCdEfGhIjKlUk9PVA',
        'GOOGLE_DRIVE_ROOT_FOLDER_ID' => '1aBcDeFgHiJkLmNoPqRsTuVwXyZ012345',
        'GOOGLE_DRIVE_ALLOWED_MEMBERS' => 'du-phong@vidu.test:organizer,van-phong-kho@vidu.test:reader',
        'DOCUMENT_OFFICE_RECEIPTS_PATH' => 'gdrive:VK-CRM-backups/office-receipts/vk-crm-production',
    ]);

    expect($blank['google_drive']['credentials_path'])->toBeNull()
        ->and($blank['google_drive']['shared_drive_id'])->toBeNull()
        ->and($blank['google_drive']['root_folder_id'])->toBeNull()
        ->and($blank['google_drive']['allowed_members'])->toBeNull()
        ->and($blank['office']['receipts_path'])->toBeNull()
        ->and($filled['google_drive']['credentials_path'])->toBe('/etc/vkcrm/google-drive-key.json')
        ->and($filled['google_drive']['shared_drive_id'])->toBe('0AbCdEfGhIjKlUk9PVA')
        ->and($filled['google_drive']['root_folder_id'])->toBe('1aBcDeFgHiJkLmNoPqRsTuVwXyZ012345')
        ->and($filled['google_drive']['allowed_members'])->toBe('du-phong@vidu.test:organizer,van-phong-kho@vidu.test:reader')
        ->and($filled['office']['receipts_path'])->toBe('gdrive:VK-CRM-backups/office-receipts/vk-crm-production');
});

/**
 * R2: job đẩy tệp (`PushDocumentFile`, Task 3) chạy tới 1800 giây (`$timeout`). `retry_after` của
 * kết nối phải lớn hơn, nếu không worker khác nhặt lại job đang tải một gói 2 GB và chạy SONG SONG
 * với chính nó. Ghim bằng số: job chưa tồn tại tới Task 3. Driver luôn là `database`, không theo
 * `QUEUE_CONNECTION` (phpunit đặt `sync`), cùng lý do với kết nối `handover`.
 */
it('kết nối hàng đợi storage: driver database, hàng storage, retry_after 2400 > 1800 giây của job đẩy', function () {
    $connection = config('queue.connections.storage');

    expect($connection)->toMatchArray([
        'driver' => 'database',
        'queue' => 'storage',
        'retry_after' => 2400,
        'after_commit' => false,
    ])
        ->and($connection['retry_after'])->toBeGreaterThan(1800);
});

it('mỗi biến môi trường mới của M14 có đúng một dòng mẫu trong .env.example', function () {
    $example = (string) file_get_contents(base_path('.env.example'));

    foreach (M14_STORAGE_ENV_KEYS as $key) {
        preg_match_all('/^#?[ \t]*'.preg_quote($key, '/').'=/m', $example, $matches);

        expect($matches[0])->toHaveCount(1, "{$key} phải có đúng một dòng mẫu");
    }

    // Dòng mẫu của công tắc nói đúng mặc định: bản cài mới không đẩy gì lên kho.
    expect($example)->toMatch('/^DOCUMENT_STORAGE=local$/m');
});
