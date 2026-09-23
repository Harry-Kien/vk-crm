<?php

use App\Actions\Schedule\RecordScheduleRun;
use App\Actions\Schedule\SendHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Tác vụ định kỳ
|--------------------------------------------------------------------------
|
| SPEC §2: toàn bộ chạy bằng ĐÚNG MỘT DÒNG cron, vì kiến trúc phải sống được trên shared
| hosting, nơi không có systemd, không có supervisor, không có worker thường trực:
|
|   * * * * * cd /đường/dẫn && php artisan schedule:run >> /dev/null 2>&1
|
| Mỗi tác vụ ở đây gọi một Action ở `app/Actions/Schedule/`, không phải một closure mang
| nghiệp vụ. Closure chỉ chạy được qua scheduler nên không test nào gọi thẳng được nó, và
| thứ không test được là thứ sẽ hỏng lặng lẽ — đúng cái mà cả nhóm tác vụ này sinh ra để
| chống.
|
| Giờ giấc theo `APP_TIMEZONE`. Có test ghim múi giờ đó là giờ Việt Nam, vì một tác vụ
| "07:00" chạy theo giờ UTC sẽ gửi thư nhắc hạn lúc 2 giờ sáng.
*/

/**
 * Mạch đập của chính scheduler, mỗi phút. Trang chủ admin đọc `last_schedule_run_at` và
 * cảnh báo đỏ khi nó cũ hơn 30 phút (SPEC §2).
 *
 * KHÔNG `withoutOverlapping()`: tác vụ này là một câu UPDATE, xong trong mili giây. Khoá
 * chống chồng lấn sống trong cache; nếu một tiến trình chết giữa chừng thì khoá còn lại tới
 * khi hết hạn, và hệ quả sẽ là đồng hồ sức khoẻ đứng im trong khi cron vẫn chạy — tức báo
 * động giả về đúng thứ nó theo dõi.
 */
Schedule::call(new RecordScheduleRun)
    ->everyMinute()
    ->name('system-health.touch')
    ->description('Ghi nhận scheduler còn sống');

/**
 * Heartbeat ra dịch vụ giám sát bên ngoài, 5 phút một lần (SPEC §2). Action không bao giờ
 * ném — xem docblock của nó — nên một dịch vụ giám sát chết không kéo theo cả lịch.
 */
Schedule::call(new SendHeartbeat)
    ->everyFiveMinutes()
    ->name('system-health.heartbeat')
    ->description('Ping dịch vụ giám sát cron bên ngoài')
    ->withoutOverlapping();

/**
 * Hàng đợi, theo phán quyết R2 của kế hoạch M6: trên VPS chạy `queue:work` bằng systemd,
 * còn trên shared hosting để scheduler gọi `--stop-when-empty`.
 *
 * `--max-time=50` để tiến trình luôn kết thúc trước lần cron kế tiếp, kể cả khi hàng đợi
 * liên tục có việc mới; `withoutOverlapping()` là lớp thứ hai cho đúng trường hợp đó.
 * Thư OTP đăng nhập KHÔNG đi qua hàng đợi (phán quyết M5): một mã sống 5 phút mà nằm chờ
 * cron là một mã chết.
 */
Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->name('queue.drain')
    ->description('Rút hàng đợi, thay cho worker thường trực')
    ->withoutOverlapping();
