<?php

use Spatie\Activitylog\Models\Activity;

return [

    /*
     * If set to false, no activities will be saved to the database.
     */
    'enabled' => env('ACTIVITY_LOGGER_ENABLED', true),

    /*
     * When the clean-command is executed, all recording activities older than
     * the number of days specified here will be deleted.
     *
     * M8 Task 3 (SPEC §10.6, đóng minor M-8 của M6.5): KHÔNG có tác vụ lịch nào chạy
     * `activitylog:clean` và không nên có — nhật ký hoạt động là chứng cứ, và `RegroupDocument`
     * đọc lại các dòng `document_regrouped` cũ để quyết định (xoá dòng cũ đổi kết quả nghiệp vụ).
     * Con số mặc định của gói (365 ngày) sẽ xoá nhật ký sau MỘT năm nếu ai đó chạy lệnh bằng tay,
     * ít hơn nhiều so với thời hạn lưu hồ sơ (`RETENTION_YEARS`, 10 năm). Nên con số này nay là
     * `RETENTION_YEARS × 366` ngày: một lần chạy `activitylog:clean` thủ công cũng không xoá
     * được gì trong thời hạn lưu. Một test ghim cả hai điều (không lịch, không dưới thời hạn lưu).
     * Giá nếu sai: bảng `activity_log` lớn dần (cỡ MB mỗi năm).
     */
    'delete_records_older_than_days' => ((int) env('RETENTION_YEARS', 10)) * 366,

    /*
     * If no log name is passed to the activity() helper
     * we use this default log name.
     */
    'default_log_name' => 'default',

    /*
     * You can specify an auth driver here that gets user models.
     * If this is null we'll use the current Laravel auth driver.
     */
    'default_auth_driver' => null,

    /*
     * If set to true, the subject returns soft deleted models.
     */
    'subject_returns_soft_deleted_models' => false,

    /*
     * This model will be used to log activity.
     * It should implement the Spatie\Activitylog\Contracts\Activity interface
     * and extend Illuminate\Database\Eloquent\Model.
     */
    'activity_model' => Activity::class,

    /*
     * This is the name of the table that will be created by the migration and
     * used by the Activity model shipped with this package.
     */
    'table_name' => env('ACTIVITY_LOGGER_TABLE_NAME', 'activity_log'),

    /*
     * This is the database connection that will be used by the migration and
     * the Activity model shipped with this package. In case it's not set
     * Laravel's database.default will be used instead.
     */
    'database_connection' => env('ACTIVITY_LOGGER_DB_CONNECTION'),
];
