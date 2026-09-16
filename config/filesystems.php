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

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
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
         * Disk cho tệp hồ sơ/tài liệu (SPEC §10.4). Cùng thư mục vật lý với disk 'local' mặc
         * định của Laravel (storage/app/private), nhưng KHÔNG đặt 'serve' => true như disk
         * 'local': bật cờ đó tự đăng ký một route GET/PUT /storage/{path} phục vụ MỌI tệp trên
         * disk qua chữ ký URL riêng của framework (`storage.<disk>`), không đi qua
         * `DocumentDownloadController` và không kiểm tra policy — đúng thứ SPEC §10.4 cấm ("chữ
         * ký URL không thay thế kiểm tra quyền"). Việc dùng một disk riêng, tách khỏi 'local',
         * đảm bảo tài liệu KHÔNG BAO GIỜ vô tình đi qua route tự động đó dù ai đó sau này bật
         * 'serve' cho disk 'local' vì một lý do khác. Tệp chỉ tải được qua route ký riêng ở
         * Task 5, route đó tự kiểm tra policy sau khi xác minh chữ ký.
         */
        'private' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
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
