<?php

use NotificationChannels\WebPush\PushSubscription;

/*
 * Thông báo đẩy trên điện thoại (M12 R6, R7, R12) — `laravel-notification-channels/webpush`.
 *
 * Publish từ gói (`vendor/laravel-notification-channels/webpush/config/webpush.php`, 13.0.1) rồi
 * sửa. Giữ ĐỦ khoá cấp một của tệp gói: `mergeConfigFrom()` gộp nông, một khoá bỏ đi sẽ âm thầm
 * lấy lại giá trị của gói (`tests/Feature/Push/WebPushInstallTest.php` canh). Chỉ ba biến
 * `VAPID_*` đọc từ `.env`; các `env()` khác của tệp gói (`VAPID_PEM_FILE`, `WEBPUSH_DB_TABLE`,
 * `WEBPUSH_DB_CONNECTION`, `WEBPUSH_AUTOMATIC_PADDING`) bị thay bằng giá trị cố định — không ai cần
 * điền chúng, và một biến có mặt trong `.env.example` là một biến người vận hành sẽ đi tìm cách điền.
 */
return [

    /*
     * R7 — khoá VAPID là bí mật cùng hạng với `APP_KEY`. Sinh MỘT lần cho mỗi môi trường bằng
     * `php artisan webpush:vapid` (ghi `VAPID_PUBLIC_KEY`/`VAPID_PRIVATE_KEY` vào `.env`);
     * `VAPID_SUBJECT` là `mailto:` hộp thư có người đọc của văn phòng (Apple từ chối khi thiếu).
     * Mất hay đổi khoá riêng thì mọi đăng ký trên mọi máy chết im lặng (401/403, không phải 410) —
     * chạy `php artisan vkcrm:push-reset` sau mỗi lần đổi.
     *
     * Thiếu, trống hay sai định dạng = chưa cấu hình: một định nghĩa duy nhất ở
     * {@see \App\Support\Push\VapidKeys::configured()} — `vkcrm:preflight` báo VÀNG; nút "Bật" (Task 5)
     * và việc xếp job (Task 7) hỏi cùng hàm đó để push tắt êm.
     *
     * `pem_file` của gói không dùng: khoá nằm trong `.env`, cùng chỗ cất với `APP_KEY`, không ở
     * một tệp thứ hai cần sao lưu riêng.
     */
    'vapid' => [
        'subject' => env('VAPID_SUBJECT'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'pem_file' => null,
    ],

    /*
     * Model của gói, nằm trong `vendor/` — ngoài lưới `app/Models` của `PortalCoverageTest`. Luật
     * R8: chỉ chạm qua quan hệ của chính người đang đăng nhập; lưới duy nhất là
     * `tests/Feature/Push/PushSubscriptionAccessTest.php`.
     */
    'model' => PushSubscription::class,

    /*
     * Bảng của migration `2026_10_03_000001_create_push_subscriptions_table` — cố định, không đọc
     * `WEBPUSH_DB_TABLE`.
     */
    'table_name' => 'push_subscriptions',

    /*
     * `null` = kết nối mặc định của ứng dụng (cùng CSDL, cùng transaction với mọi bảng khác).
     * Tệp gói đọc `WEBPUSH_DB_CONNECTION` rồi `DB_CONNECTION`, mặc định `mysql`.
     */
    'database_connection' => null,

    /*
     * R12 — hạn cho MỖI request ra máy chủ push: 10 giây (gói mặc định 30). Mỗi thiết bị là một
     * request; máy chủ push chậm nhân với số thiết bị lúc 07:00 sẽ giữ hết lượt rút hàng đợi
     * (`--max-time=50`). Có tác dụng THẬT là nhờ `App\Providers\WebPushServiceProvider`: client mà
     * bản gốc dựng bỏ mọi tuỳ chọn ở đây (docblock của provider đó nói vì sao).
     */
    'client_options' => [
        'timeout' => 10,
    ],

    /*
     * Đệm payload tự động của `minishlink/web-push` (mặc định của gói) — giấu độ dài thật của nội
     * dung trước máy chủ push.
     */
    'automatic_padding' => true,

];
