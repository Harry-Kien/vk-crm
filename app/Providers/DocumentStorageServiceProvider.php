<?php

namespace App\Providers;

use App\Exceptions\DocumentStorageMisconfigured;
use App\Support\Storage\MisconfiguredDriveAdapter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;

/**
 * Kho tài liệu Google Drive (M14): đăng ký driver `google-drive` cho đĩa `documents_remote`
 * (`config/filesystems.php`).
 *
 * Provider riêng chứ không nằm trong `AppServiceProvider`: nhiều làn song song cùng sửa provider đó,
 * và mọi thứ của kho (driver, sau này listener và lịch của nó) đọc được ở một chỗ.
 *
 * `Storage::extend()` chỉ ghi lại một hàm dựng; hàm chạy lần đầu ai đó lấy đĩa `documents_remote`.
 * Hàm dựng ở đây không đọc khoá, không gọi mạng và không bao giờ ném: thiếu cấu hình thì lỗi
 * {@see DocumentStorageMisconfigured} chỉ ném ra lúc DÙNG đĩa (kế hoạch M14, R7).
 *
 * M14 Task 1: driver trả {@see MisconfiguredDriveAdapter} cho mọi cấu hình — trình kết nối Drive
 * thật đến ở Task 2.
 */
class DocumentStorageServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Storage::extend('google-drive', function (Application $app, array $config): FilesystemAdapter {
            $adapter = new MisconfiguredDriveAdapter(fn (): DocumentStorageMisconfigured => DocumentStorageMisconfigured::adapterNotInstalled());

            return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
        });
    }
}
