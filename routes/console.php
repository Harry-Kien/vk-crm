<?php

use App\Actions\Schedule\CheckDeadlines;
use App\Actions\Schedule\CheckStaleMatters;
use App\Actions\Schedule\RecordScheduleRun;
use App\Actions\Schedule\RemindMissingDocuments;
use App\Actions\Schedule\RemindOverdueInstalments;
use App\Actions\Schedule\RemindUnseenUpdates;
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
 *    vô hại — mỗi mốc bị khoá dòng, bậc đã đánh dấu ở `reminders_sent`, và sổ thư chặn gửi trùng
 *    theo bậc@ngày — nên lần 07:30 chỉ làm việc lần 07:00 chưa làm được. Lần đầu trong ngày vẫn là
 *    07:00.
 *    Một bậc đã hỏng HẲN (hết lượt thử) thì không xếp lại trong ngày — chỉ lượt đầu của ngày
 *    hôm sau thử lại (wave 2, I-2: `SendDeadlineReminderMail::failedForGoodToday()`).
 *  - khoá chống chồng lấn hết hạn sau 60 phút, không phải 1440 mặc định: một lần chạy bị giết
 *    giữa chừng không còn khoá luôn mọi lần chạy tới cùng giờ ngày hôm sau.
 */
Schedule::call(new CheckDeadlines)
    // Cron thuần, không `->between()`: `between()` chụp `now()` lúc lịch được DỰNG, không lúc hỏi.
    ->cron('*/30 7-19 * * *')
    ->name('deadlines.check')
    ->withoutOverlapping(60);

/**
 * Dọn bản sao lưu cũ rồi tạo bản mới, 02:00 hằng ngày (SPEC §10 mục 8, R3; M8a Task 1).
 *
 * Dọn TRƯỚC khi sao lưu, đúng thứ tự Task 1 brief yêu cầu — `config/backup.php` chọn
 * `keep_all_backups_for_days = 29` (không phải 30) chính vì thứ tự này: sau khi dọn, hôm nay
 * CHƯA có bản mới, nên "còn đúng 30 bản" chỉ đúng SAU KHI `backup:run` chạy xong (xem
 * `tests/Feature/Backup/BackupCleanupTest.php`).
 *
 * `->then()` — KHÔNG `->onSuccess()` — vì `backup:run` phải chạy dù `backup:clean` thất bại: một
 * lượt dọn dẹp hỏng (ví dụ một disk không xoá được tệp cũ) không được phép cũng chặn luôn bản sao
 * lưu CỦA HÔM NAY. `withoutOverlapping()` vì cả hai lệnh có thể chạy lâu trên một CSDL lớn — hai
 * tiến trình `backup:run` chồng nhau ghi hai archive cùng lúc là lãng phí I/O, không phải lỗi dữ
 * liệu, nhưng vẫn không đáng để cho phép. Khoá chồng lấn giữ cả `backup:run` (callback `->then()`
 * chạy TRƯỚC khi scheduler gỡ khoá).
 *
 * `withoutOverlapping(360)` — khoá tự hết hạn sau 6 giờ, không phải 24 giờ mặc định (fix I5, lượt
 * rà soát cuối M8a): một tiến trình bị giết giữa chừng (máy chủ khởi động lại lúc 02:30) để lại
 * khoá; với 24 giờ, khoá đó chặn luôn lượt sao lưu 02:00 của ĐÊM SAU — hai đêm liền không bản sao
 * nào. 6 giờ đủ cho một lượt sao lưu vài GB và hết hạn trước 02:00 hôm sau.
 *
 * CHỈ `->name('backup.nightly')`, không `->description()`: trong Laravel 13
 * `Illuminate\Console\Scheduling\ManagesAttributes::name()` và `description()` là BÍ DANH của
 * nhau (cùng ghi `$description`), gọi cả hai thì lời gọi SAU ghi đè lời gọi TRƯỚC. Theo quy ước
 * M6.5 cho mọi tác vụ lịch, mỗi tác vụ chỉ mang một tên máy đọc được, và
 * `tests/Feature/Schedule/BackupScheduleTest.php` tra tác vụ bằng tên đó rồi khẳng định thêm lệnh
 * Artisan thật (`$event->command`) nó chạy.
 */
Schedule::command('backup:clean')
    ->dailyAt('02:00')
    ->name('backup.nightly')
    ->withoutOverlapping(360)
    ->then(fn () => Artisan::call('backup:run'));

/**
 * Giám sát sức khoẻ các bản sao lưu, 08:00 hằng ngày (SPEC §10 mục 8) — đủ xa lượt 02:00 để một
 * lượt sao lưu chạy lâu (hồ sơ vài trăm MB) chắc chắn đã xong. `backup:monitor` phát
 * `UnhealthyBackupWasFound` khi bản mới nhất trên một đĩa trong `BACKUP_DISKS` quá cũ hoặc đĩa vượt
 * hạn mức (`config('backup.monitor_backups')`). Rồi — `->then()`, chạy cả khi `backup:monitor`
 * thất bại — kiểm độ tươi của bản trên đích rclone, thứ `backup:monitor` không nhìn thấy (fix I4,
 * lượt rà soát cuối M8a; `App\Actions\Backup\CheckRcloneRemoteFreshness`). Lớp được gọi bằng
 * chuỗi `Lớp@handle` thay vì `use` + `::class`: luật làn song song cho tệp này là CHỈ NỐI THÊM
 * dòng ở cuối, và Pint tự chèn một dòng `use` lên đầu tệp cho mọi tên lớp viết đầy đủ.
 *
 * `withoutOverlapping(60)` — không để khoá mặc định 1440 phút (cùng lý lẽ M6.5 X6 đã áp cho các
 * tác vụ trên): một lượt 08:00 bị giết giữa chừng không được chặn luôn lượt giám sát của NGÀY SAU.
 * `tests/Feature/Schedule/BackupScheduleTest.php` ghim con số này, và ghim luôn rằng không tác vụ
 * lịch nào còn giữ khoá 1440 phút.
 */
Schedule::command('backup:monitor')
    ->dailyAt('08:00')
    ->name('backup.monitor')
    ->withoutOverlapping(60)
    ->then(fn () => app()->call('App\Actions\Backup\CheckRcloneRemoteFreshness@handle'));

/**
 * Nhắc nội bộ đợt thanh toán quá hạn, 08:00 hằng ngày (SPEC §6.8, đính chính M9 Task 11).
 *
 * Chạy MỘT lần mỗi ngày, không lặp trong ngày như `deadlines.check`: một mốc kháng cáo bị lỡ là
 * trách nhiệm nghề nghiệp, một lời nhắc công nợ trễ một ngày thì không — và nhịp nhắc là "ngày
 * đầu tiên quá hạn, rồi bảy ngày một lần" nên lượt 08:00 bị lỡ vẫn được lượt hôm sau bù (đợt vẫn
 * quá hạn, chưa nhắc trong bảy ngày). Chống trùng nằm ở sổ thư (`outbound_messages`), nên chạy
 * lại tay trong ngày là vô hại. Giờ Việt Nam: `dailyAt()` đọc múi giờ ứng dụng.
 *
 * `withoutOverlapping(60)` vì nó xếp thư — hai tiến trình chồng nhau là hai lượt cùng đọc sổ thư
 * trước khi bên nào ghi `sent` — và 60 phút, không phải 1440 mặc định (cùng lý lẽ M6.5 X6 áp cho
 * mọi tác vụ lịch: một lượt bị giết giữa chừng không được khoá luôn lượt hôm sau;
 * `tests/Feature/Schedule/BackupScheduleTest.php` ghim "không tác vụ nào giữ khoá ≥ 1440 phút").
 * CHỈ `->name()`, không `->description()` (bí danh của nhau trong Laravel 13, xem `backup.nightly`).
 */
Schedule::call(new RemindOverdueInstalments)
    ->dailyAt('08:00')
    ->name('instalments.remind')
    ->withoutOverlapping(60);

/**
 * Hồ sơ quá hạn cập nhật cho khách (SPEC §6.4): 07:30 hằng ngày, MỘT lần — không cần lặp lại nhiều
 * lần trong ngày như `deadlines.check` (rủi ro nghề nghiệp thấp hơn hẳn một mốc tố tụng bị lỡ; R5
 * của kế hoạch M6 đã tự cho phép "một thư mỗi 7 ngày", nên một lần cron bị bỏ lỡ trong ngày không
 * làm mất lời nhắc — lần chạy 07:30 hôm sau vẫn thấy vụ việc còn đình trệ).
 *
 * `withoutOverlapping()` vì nó gửi thư và ghi thông báo; khoá hết hạn sau 60 phút, không phải mặc
 * định 1440 — cùng lý lẽ `deadlines.check`: một lần chạy bị giết giữa chừng (giới hạn CPU của
 * shared hosting) không được khoá luôn lần chạy của NGÀY HÔM SAU.
 *
 * Nhắc hồ sơ quá hạn cập nhật cho khách.
 */
Schedule::call(new CheckStaleMatters)
    ->dailyAt('07:30')
    ->name('stale-matters.check')
    ->withoutOverlapping(60);

/**
 * Nhắc khách nộp giấy tờ còn thiếu (SPEC §6.9): thứ Hai, Tư, Sáu lúc 08:00 giờ Việt Nam. Cron thuần
 * `0 8 * * 1,3,5` (1 = Hai, 3 = Tư, 5 = Sáu), cùng lý do `deadlines.check` không dùng `between()`.
 *
 * Khoảng cách giữa các lượt là 2, 2 và 3 ngày, còn luật chống trùng là "không quá một thư mỗi 3
 * ngày cho cùng một hồ sơ" (R3 của kế hoạch M6, `RemindMissingDocuments::mailWindowStart()`), nên
 * trên một hồ sơ thiếu giấy tờ liên tục thư thực tế đi thứ Hai và thứ Sáu; thứ Tư chỉ gửi cho hồ
 * sơ mới bắt đầu thiếu, hoặc lượt trước đã hỏng. Đó là hệ quả của HAI con số cùng nằm trong SPEC,
 * không phải một lỗi lịch.
 *
 * `withoutOverlapping()` vì nó gửi thư và ghi thông báo; khoá hết hạn sau 60 phút, không phải mặc
 * định 1440 — cùng lý lẽ `deadlines.check`.
 *
 * Nhắc khách nộp giấy tờ còn thiếu.
 */
Schedule::call(new RemindMissingDocuments)
    ->cron('0 8 * * 1,3,5')
    ->name('missing-documents.remind')
    ->withoutOverlapping(60);

/**
 * Nhắc luật sư phụ trách gọi điện cho khách chưa xem cập nhật (SPEC §4.18, §7.1 mục 5): 08:30 hằng
 * ngày giờ Việt Nam, sau `missing-documents.remind` (08:00). MỘT lần một ngày — không thư nào tới
 * khách, chỉ một thông báo trong hệ thống, và thông báo chống lặp theo dòng chưa xem mới nhất
 * (`RemindUnseenUpdates`), nên một lượt cron bị bỏ lỡ chỉ dời lời nhắc sang hôm sau.
 *
 * `withoutOverlapping()` vì nó ghi thông báo: hai tiến trình chồng nhau có thể cùng thấy "chưa nhắc"
 * và ghi hai dòng cho một người; khoá hết hạn sau 60 phút, không phải mặc định 1440 — cùng lý lẽ
 * `deadlines.check`: một lần chạy bị giết giữa chừng không được khoá luôn lần chạy của NGÀY HÔM SAU.
 *
 * Nhắc luật sư gọi điện cho khách chưa xem cập nhật.
 */
Schedule::call(new RemindUnseenUpdates)
    ->dailyAt('08:30')
    ->name('unseen-updates.remind')
    ->withoutOverlapping(60);

/**
 * Dọn đăng ký thông báo đẩy (M12 R9): 03:30 hằng ngày giờ Việt Nam, sau lượt sao lưu 02:00 và trước
 * mọi tác vụ gửi thư buổi sáng. Bỏ đăng ký của tài khoản đã vô hiệu, đã xoá mềm (hay có khách hàng đã
 * xoá mềm) và đăng ký không mở ứng dụng quá 180 ngày. Vệ sinh, không phải lớp bảo vệ: người nhận push
 * luôn là người nhận của thư, tính lúc gửi.
 *
 * KHÔNG `withoutOverlapping()`: mỗi nhóm là một câu `DELETE`, chạy lại hay chồng nhau đều vô hại
 * (bên sau xoá 0 dòng) — một khoá chỉ thêm một cách hỏng (khoá kẹt) mà không mua được gì. CHỈ
 * `->name()`, không `->description()` (bí danh của nhau trong Laravel 13, xem `backup.nightly`).
 * Lớp gọi bằng chuỗi `Lớp@handle`, không `use` + `new` — luật làn song song cho tệp này là chỉ nối
 * thêm ở cuối (xem `backup.monitor`).
 */
Schedule::call('App\Actions\Schedule\PrunePushSubscriptions@handle')
    ->dailyAt('03:30')
    ->name('push-subscriptions.prune');
