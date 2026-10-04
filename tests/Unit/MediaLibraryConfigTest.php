<?php

/**
 * Trần MỘT tệp của medialibrary (`media-library.max_file_size`) — M7 Task 4, vòng sửa 1 (C1).
 *
 * Mặc định 10 MB của gói chặn hai thứ: gói bàn giao (tổng của mọi tệp A/B/C, vài trăm MB) không
 * bao giờ lưu được quá 10 MB, và tệp tải lên 10–20 MB qua được `FileGuard` (UPLOAD_MAX_MB = 20)
 * rồi hỏng ở medialibrary. Cổng tải lên là `FileGuard`; trần của medialibrary là trần của kho.
 *
 * Đọc lại tệp cấu hình dưới những giá trị môi trường khác nhau: `env()` đọc `$_SERVER` trước
 * (`ServerConstAdapter` của Dotenv), nên đặt khoá ở đó là đủ để thay giá trị trong lúc `require`.
 */
function mediaLibraryMaxFileSizeUnder(array $environment): int
{
    $saved = [];

    foreach ($environment as $key => $value) {
        $saved[$key] = array_key_exists($key, $_SERVER) ? $_SERVER[$key] : null;
        $_SERVER[$key] = $value;
    }

    try {
        return (require config_path('media-library.php'))['max_file_size'];
    } finally {
        foreach ($saved as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
    }
}

it('trần của kho hồ sơ đọc từ MEDIA_MAX_FILE_SIZE_MB', function () {
    expect(mediaLibraryMaxFileSizeUnder(['MEDIA_MAX_FILE_SIZE_MB' => '4096', 'UPLOAD_MAX_MB' => '20']))
        ->toBe(4096 * 1024 * 1024);
});

it('MEDIA_MAX_FILE_SIZE_MB trống hoặc không phải số dương thì về mặc định 2048 MB', function (string $value) {
    expect(mediaLibraryMaxFileSizeUnder(['MEDIA_MAX_FILE_SIZE_MB' => $value, 'UPLOAD_MAX_MB' => '20']))
        ->toBe(2048 * 1024 * 1024);
})->with(['trống' => '', 'số 0' => '0', 'số âm' => '-5', 'chữ' => 'abc']);

it('trần của kho hồ sơ không bao giờ thấp hơn trần tải lên — FileGuard mới là cổng tải lên', function () {
    expect(mediaLibraryMaxFileSizeUnder(['MEDIA_MAX_FILE_SIZE_MB' => '5', 'UPLOAD_MAX_MB' => '20']))
        ->toBe(20 * 1024 * 1024)
        // UPLOAD_MAX_MB hỏng thì FileGuard lùi về 20 MB — trần ở đây lùi theo đúng con số đó.
        ->and(mediaLibraryMaxFileSizeUnder(['MEDIA_MAX_FILE_SIZE_MB' => '5', 'UPLOAD_MAX_MB' => 'abc']))
        ->toBe(20 * 1024 * 1024);
});

it('cấu hình đang nạp: trần của kho hồ sơ không thấp hơn trần tải lên của FileGuard', function () {
    expect(config('media-library.max_file_size'))
        ->toBeGreaterThanOrEqual((int) config('vkcrm.upload_max_mb') * 1024 * 1024);
});
