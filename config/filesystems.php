<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        /*
         * Disk mặc định của Laravel, dùng cho tệp tạm/nội bộ của ứng dụng. Hai chỗ lệch khỏi bản
         * gốc `laravel/laravel`, cả hai đều cố ý:
         *
         * 1. `root` KHÔNG còn là `storage/app/private`. Bản gốc để hai disk trỏ cùng một thư
         *    mục, nên "tách disk" chỉ là một cái tên: mọi tài liệu ghi qua `private` đọc lại
         *    được qua `local`, và mọi thứ áp lên `private` không có tác dụng gì. Nay `local` có
         *    thư mục riêng, không lồng vào và không chứa `storage/app/private`.
         * 2. `serve` KHÔNG còn `true`. Cờ đó làm `FilesystemServiceProvider` tự đăng ký route
         *    `storage.local` (`GET`/`PUT /storage/{path}`) phục vụ MỌI tệp trên disk chỉ với
         *    một chữ ký URL của framework — không đi qua `DocumentDownloadController`, không
         *    kiểm tra policy, không ghi `document_downloads`. Đúng thứ SPEC §10.4 cấm.
         *
         * Ứng dụng này không có nhu cầu nào cần một route phục vụ tệp thô: đường tải tệp duy
         * nhất là route ký riêng ở Task 5. `tests/Feature/Storage/PrivateDiskTest.php` kiểm cả
         * hai điều trên bằng hành vi thật (ghi qua disk này, đọc qua disk kia) chứ không đọc
         * bình luận — một bình luận vẫn đọc thấy đúng cả khi nó đã sai.
         */
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/local'),
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
         * Disk cho tệp hồ sơ/tài liệu (SPEC §10.4): `storage/app/private`, ngoài document root,
         * có `.htaccess` chặn kèm cấu hình nginx tương ứng ghi ngay trong tệp đó.
         *
         * Thư mục này là của RIÊNG disk `private` — không disk nào khác trỏ vào nó, cũng không
         * disk nào trỏ vào thư mục cha hay thư mục con của nó (xem bình luận ở `local`). Và
         * `private` không đặt `serve`, nên không có route `storage.private` nào tồn tại.
         *
         * Hệ quả là đường duy nhất tới một tệp trong này là route có chữ ký của
         * `DocumentDownloadController` (Task 5), và route đó vẫn kiểm tra policy sau khi xác
         * minh chữ ký — chữ ký URL không thay thế kiểm tra quyền.
         */
        'private' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'throw' => false,
            'report' => false,
        ],

        /*
         * Đích sao lưu local mặc định khi `BACKUP_DISKS` chưa khai báo (SPEC §10 mục 8, M8a
         * Task 1) — `App\Support\Backup\BackupDisks::DEFAULT_DISK`. CỐ Ý một thư mục RIÊNG,
         * không lồng vào `private` hay `local` ở trên: một archive sao lưu chứa TOÀN BỘ
         * `storage/app/private`, nên nếu nó nằm dưới `private` thì mỗi lượt `backup:run` sẽ sao
         * lưu luôn cả archive của chính lượt chạy trước — một vòng lặp phình vô hạn.
         */
        'local_backups' => [
            'driver' => 'local',
            'root' => storage_path('app/backups'),
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
