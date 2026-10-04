<?php

namespace App\Providers;

use App\Exceptions\DocumentStorageMisconfigured;
use App\Support\Storage\GoogleDrive\DriveAdapter;
use App\Support\Storage\GoogleDrive\DriveCircuitBreaker;
use App\Support\Storage\GoogleDrive\DriveClient;
use App\Support\Storage\GoogleDrive\DriveObjectIndex;
use App\Support\Storage\GoogleDrive\DriveTokenProvider;
use App\Support\Storage\GoogleDrive\ServiceAccountTokenProvider;
use App\Support\Storage\MisconfiguredDriveAdapter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;

/**
 * Kho tài liệu Google Drive (M14): đăng ký driver `google-drive` cho đĩa `documents_remote`
 * (`config/filesystems.php`).
 *
 * Provider riêng chứ không nằm trong `AppServiceProvider`: nhiều làn song song cùng sửa provider đó,
 * và mọi thứ của kho (driver, sau này listener và lịch của nó) đọc được ở một chỗ.
 *
 * `Storage::extend()` chỉ ghi lại một hàm dựng; hàm chạy lần đầu ai đó lấy đĩa `documents_remote`.
 * Hàm dựng ở đây không đọc khoá, không gọi mạng và không bao giờ ném (kế hoạch M14, R7):
 *
 * - đủ ba khoá `vkcrm.storage.google_drive.{credentials_path, shared_drive_id, root_folder_id}` →
 *   {@see DriveAdapter} thật; tệp khoá chỉ được đọc khi lần đầu cần access token;
 * - thiếu khoá nào → {@see MisconfiguredDriveAdapter}, ném {@see DocumentStorageMisconfigured} nêu
 *   đúng biến môi trường còn thiếu ở MỌI lời gọi, không lời gọi nào chạm mạng.
 *
 * {@see DriveTokenProvider} là một binding của container: test thay bằng token cố định mà không gọi
 * endpoint token.
 */
class DocumentStorageServiceProvider extends ServiceProvider
{
    /** Khoá cấu hình bắt buộc → biến môi trường nêu trong câu lỗi. */
    private const REQUIRED = [
        'credentials_path' => 'GOOGLE_DRIVE_CREDENTIALS_PATH',
        'shared_drive_id' => 'GOOGLE_DRIVE_SHARED_DRIVE_ID',
        'root_folder_id' => 'GOOGLE_DRIVE_ROOT_FOLDER_ID',
    ];

    public function register(): void
    {
        $this->app->bind(DriveTokenProvider::class, fn (): DriveTokenProvider => new ServiceAccountTokenProvider(
            config('vkcrm.storage.google_drive.credentials_path'),
            Cache::store(config('vkcrm.storage.google_drive.token_cache_store')),
            (int) config('vkcrm.storage.google_drive.connect_timeout', 5),
            (int) config('vkcrm.storage.google_drive.timeout', 30),
        ));
    }

    public function boot(): void
    {
        // `Storage::extend()` gắn hàm dựng vào `FilesystemManager` (`bindTo`), nên trong đó `self::`
        // là lớp của trình quản lý, không phải provider này: giữ hàm dựng adapter như một closure riêng.
        $make = self::adapter(...);

        Storage::extend('google-drive', function (Application $app, array $config) use ($make): FilesystemAdapter {
            $adapter = $make($app);

            return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
        });
    }

    private static function adapter(Application $app): FlysystemAdapter
    {
        $drive = (array) config('vkcrm.storage.google_drive');
        $missing = array_values(array_filter(
            self::REQUIRED,
            fn (string $variable, string $key): bool => ! is_string($drive[$key] ?? null) || $drive[$key] === '',
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($missing !== []) {
            return new MisconfiguredDriveAdapter(fn (): DocumentStorageMisconfigured => DocumentStorageMisconfigured::notConfigured($missing));
        }

        $client = DriveClient::fromConfig(
            $app->make(DriveTokenProvider::class),
            new DriveCircuitBreaker(Cache::store($drive['breaker_store'] ?? 'file')),
        );

        return new DriveAdapter(
            $client,
            new DriveObjectIndex,
            $drive['shared_drive_id'],
            $drive['root_folder_id'],
            (string) config('vkcrm.storage.lock_store'),
        );
    }
}
