<?php

use App\Actions\Schedule\CheckDeadlines;
use App\Actions\Schedule\ExpireClientAccess;
use App\Actions\Schedule\FlagRetentionExpiry;
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
 *
 * Ghi nhận scheduler còn sống.
 *
 * Vòng sửa 1, I1: `->name()` chỉ là bí danh của `->description()` trong Laravel 13 — cả hai
 * cùng ghi một property `$description`, nên một lời gọi CẢ HAI chỉ giữ lại giá trị của lời
 * gọi SAU CÙNG. Bản trước gọi `->name('system-health.touch')` rồi `->description('Ghi nhận
 * scheduler còn sống')`, nên định danh ổn định `system-health.touch` bị GHI ĐÈ và biến mất —
 * không còn cách nào tra một tác vụ theo mã của nó (`tests/Feature/Schedule/
 * SystemHealthTest.php` chỉ tình cờ còn xanh vì nó tra theo đúng câu tiếng Việt còn sót lại).
 * Từ đây MỖI tác vụ chỉ gọi `->name($id)`, giữ ĐÚNG MỘT giá trị ổn định; câu tiếng Việt mô tả
 * việc nó làm chuyển hẳn vào docblock phía trên, như đoạn này.
 */
Schedule::call(new RecordScheduleRun)
    ->everyMinute()
    ->name('system-health.touch');

/**
 * Heartbeat ra dịch vụ giám sát bên ngoài, 5 phút một lần (SPEC §2). Action không bao giờ
 * ném — xem docblock của nó — nên một dịch vụ giám sát chết không kéo theo cả lịch.
 *
 * Ping dịch vụ giám sát cron bên ngoài.
 */
Schedule::call(new SendHeartbeat)
    ->everyFiveMinutes()
    ->name('system-health.heartbeat')
    // Final review X6: không để khoá mặc định 1440 phút — một lần ping bị giết giữa chừng không
    // được tắt heartbeat cả ngày (dịch vụ giám sát sẽ báo "cron chết" dù cron vẫn chạy).
    ->withoutOverlapping(10);

/**
 * Hàng đợi, theo phán quyết R2 của kế hoạch M6: trên VPS chạy `queue:work` bằng systemd,
 * còn trên shared hosting để scheduler gọi `--stop-when-empty`.
 *
 * `--max-time=50` để tiến trình luôn kết thúc trước lần cron kế tiếp, kể cả khi hàng đợi
 * liên tục có việc mới; `withoutOverlapping()` là lớp thứ hai cho đúng trường hợp đó.
 * Thư OTP đăng nhập KHÔNG đi qua hàng đợi (phán quyết M5): một mã sống 5 phút mà nằm chờ
 * cron là một mã chết.
 *
 * Rút hàng đợi, thay cho worker thường trực.
 */
Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->name('queue.drain')
    ->withoutOverlapping(10);

/**
 * Nhắc mốc thời hạn tố tụng: lần đầu 07:00 hằng ngày (SPEC §6.8), rồi mỗi 30 phút, lần cuối 19:30.
 *
 * Đây là tác vụ mang rủi ro nghề nghiệp cao nhất trong cả hệ thống: một mốc kháng cáo bị
 * lỡ là trách nhiệm nghề nghiệp, không phải một bất tiện. `withoutOverlapping()` vì nó gửi
 * thư — hai tiến trình chồng nhau là hai thư cho cùng một người.
 *
 * Final review X6 (B-I2), hai thay đổi:
 *  - chạy LẶP mỗi 30 phút trong giờ làm việc (07:00–19:30): trên shared hosting một phút cron
 *    bị bỏ qua (máy bận, cron của nhà cung cấp trễ) từng làm mất CẢ NGÀY nhắc hạn. Chạy lại là
 *    vô hại — mỗi mốc
 *    bị khoá dòng, bậc đã đánh dấu ở `reminders_sent`, và sổ thư chặn gửi trùng theo bậc@ngày —
 *    nên lần 07:30 chỉ làm việc lần 07:00 chưa làm được. Lần đầu trong ngày vẫn là 07:00.
 *  - khoá chống chồng lấn hết hạn sau 60 phút, không phải 1440 mặc định: một lần chạy bị giết
 *    giữa chừng không còn khoá luôn mọi lần chạy tới cùng giờ ngày hôm sau.
 */
Schedule::call(new CheckDeadlines)
    // Cron thuần, không `->between()`: `between()` chụp `now()` lúc lịch được DỰNG, không lúc hỏi.
    ->cron('*/30 7-19 * * *')
    ->name('deadlines.check')
    ->withoutOverlapping(60);

/**
 * M7 Task 4 (R9): rút hàng đợi RIÊNG của gói bàn giao. Job `GenerateHandoverPackage` nén tệp hồ sơ
 * (có thể vài trăm MB, vài phút) — nếu nó chạy trong mục `queue.drain` ở trên, nó giữ lượt
 * `withoutOverlapping` của mục đó và thư nhắc mốc thời hạn (rủi ro nghề nghiệp cao nhất của hệ
 * thống, xem `deadlines.check`) phải đứng chờ. Mục này chạy trên kết nối `handover`
 * (`config/queue.php`, `retry_after` 900 giây) và hàng `handover`, còn `queue.drain` vẫn chỉ rút
 * hàng `default`.
 *
 * `--timeout=600` khớp `GenerateHandoverPackage::$timeout` (worker ưu tiên `$timeout` của job,
 * cờ này chỉ là tầng thứ hai cho job nào không khai). `--max-time=50` chỉ chặn việc NHẬN job mới
 * sau 50 giây — nó không cắt một job đang chạy. Khoá chống chồng lấn hết hạn sau 15 phút: dài hơn
 * một lần chạy tối đa (600 giây) để không hai worker cùng dựng gói, và ngắn hơn 1440 phút mặc định
 * để một tiến trình bị giết giữa chừng (giới hạn CPU của shared hosting) không khoá hàng cả ngày.
 *
 * `runInBackground()`: `schedule:run` chạy các mục của một phút LẦN LƯỢT trong cùng tiến trình. Chạy
 * tiền cảnh, một lần dựng gói tới 600 giây sẽ bắt mọi mục đăng ký SAU mục này trong cùng phút
 * đứng chờ — gồm các tác vụ hằng ngày của M7 Task 5/6 được thêm vào cuối tệp. Chạy nền thì khoá
 * `withoutOverlapping` vẫn giữ tới khi lệnh nền kết thúc (Laravel gỡ khoá ở `schedule:finish`).
 * Mục `queue.drain` ở trên không đổi (ngoài phạm vi task này): nó ngừng nhận job sau 50 giây và
 * các job của nó là thư, ngắn.
 */
Schedule::command('queue:work handover --queue=handover --stop-when-empty --max-time=50 --timeout=600')
    ->everyMinute()
    ->name('queue.handover')
    ->withoutOverlapping(15)
    ->runInBackground();

/**
 * M7 Task 5 (R4, SPEC §6.12): vô hiệu hoá tài khoản cổng của khách có vụ đã hết hạn tra cứu và không
 * còn vụ nào trên cổng — 00:30 hằng ngày, giờ Việt Nam. Xem docblock `ExpireClientAccess` cho đúng
 * điều kiện (R4).
 *
 * Vụ việc rời cổng ĐÚNG 00:00 ngày sau `client_access_until` nhờ hai tầng ranh giới
 * (`Matter::applyClientPortalConstraints()`, `MatterPolicy::releasedToPortal()`), không nhờ tác vụ
 * này — nên một đêm cron lỡ (shared hosting) chỉ hoãn việc khoá tài khoản một ngày, không để lộ hồ
 * sơ nào. 00:30 chứ không `daily()` (00:00): tránh phút nửa đêm, nơi mọi mục `daily()` dồn vào cùng
 * một lượt `schedule:run` và chạy lần lượt.
 *
 * Khoá chống chồng lấn hết hạn sau 60 phút, không 1440 mặc định — cùng lý lẽ với `deadlines.check`:
 * một lần chạy bị giết giữa chừng không được khoá luôn lần chạy của đêm sau. Tác vụ idempotent (chỉ
 * đụng tài khoản đang hoạt động), nên hai lần chạy chồng nhau cũng không ghi trùng.
 */
Schedule::call(new ExpireClientAccess)
    ->dailyAt('00:30')
    ->name('client-access.expire')
    ->withoutOverlapping(60);

/**
 * M7 Task 6 (R5, SPEC §6.12): cảnh báo quản trị khi hồ sơ quá hạn lưu trữ mà chưa ghi quyết định
 * tiêu huỷ — 01:00 hằng ngày, giờ Việt Nam. Chỉ ghi thông báo trong hệ thống; KHÔNG BAO GIỜ xoá
 * hay sửa hồ sơ (xem docblock `FlagRetentionExpiry`). Quyết định tiêu huỷ được ghi tay bằng
 * `RecordMatterDestruction` trên trang vụ việc.
 *
 * 01:00 chứ không `daily()` (00:00) hay 00:30: tránh dồn vào cùng lượt `schedule:run` với
 * `client-access.expire`. Khoá chống chồng lấn hết hạn sau 60 phút, không 1440 mặc định — cùng lý lẽ
 * với `deadlines.check` và `client-access.expire`. Tác vụ không gửi thư nên không đụng hàng đợi.
 */
Schedule::call(new FlagRetentionExpiry)
    ->dailyAt('01:00')
    ->name('retention.flag')
    ->withoutOverlapping(60);
