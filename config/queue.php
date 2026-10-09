<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis",
    |          "deferred", "background", "failover", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
            'block_for' => null,
            'after_commit' => false,
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'background' => [
            'driver' => 'background',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'database',
                'deferred',
            ],
        ],

        /*
         * M7 Task 4 (R9): hàng đợi RIÊNG cho job sinh gói bàn giao (`GenerateHandoverPackage`).
         *
         * Vì sao một KẾT NỐI riêng, không chỉ một tên hàng `--queue=handover`: `retry_after` là
         * thuộc tính của kết nối, không của hàng. Kết nối `database` ở trên giữ `retry_after` = 90
         * giây; một job nén vài trăm MB chạy quá 90 giây sẽ bị worker khác nhặt lại và chạy SONG
         * SONG với chính nó (hai gói cùng ghi một version). Kết nối này đặt `retry_after` = 1500 giây,
         * cao hơn `GenerateHandoverPackage::$timeout` (1200 giây; M14 R12: gói nay còn tải tệp từ kho
         * Google Drive về trước khi nén) — `QueueHandoverScheduleTest` ghim quan hệ đó.
         *
         * Driver LUÔN là `database`, không theo `QUEUE_CONNECTION`: dù người vận hành đặt hàng chính
         * là `sync`, một job nén tệp không bao giờ được chạy đồng bộ trong một request web của
         * người vừa bấm chuyển giai đoạn. Thứ chạy nó là mục lịch `queue.handover`
         * (`routes/console.php`), không phải mục `queue.drain`, để một gói lớn không giữ lượt của
         * thư nhắc mốc hạn.
         */
        'handover' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => 'handover',
            'retry_after' => (int) env('HANDOVER_QUEUE_RETRY_AFTER', 1500),
            'after_commit' => false,
        ],

        /*
         * M14 (kế hoạch R2): hàng đợi RIÊNG cho job đẩy tệp từ vùng đệm `private` lên kho Google
         * Drive (`PushDocumentFile`, Task 3). Cùng lý do với kết nối `handover` ngay trên:
         * `retry_after` là thuộc tính của KẾT NỐI. Job đẩy chạy tới 1800 giây (`$timeout`, một gói
         * bàn giao tới 2 GB); `retry_after` = 2400 lớn hơn, nên worker khác không nhặt lại một job
         * còn đang tải và chạy SONG SONG với chính nó (hai lượt tải cùng khoá đụng `object_key`
         * unique). `tests/Feature/Storage/StorageConfigTest.php` ghim quan hệ đó bằng số.
         *
         * Không đọc biến môi trường: con số đi đôi với `$timeout` của job và TTL 2100 của khoá đẩy
         * (`vkcrm.storage.lock_ttl_seconds`); hạ riêng nó là mở lại đúng lỗi chạy song song. Driver
         * LUÔN là `database`, không theo `QUEUE_CONNECTION`: tải lên Drive không bao giờ chạy đồng
         * bộ trong request của người vừa nộp tệp.
         */
        'storage' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => 'storage',
            'retry_after' => 2400,
            'after_commit' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

];
