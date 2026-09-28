<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\Config\Config;
use Spatie\Backup\Notifications\EventHandler;

/*
|--------------------------------------------------------------------------
| §10.8 — giữ 30 bản hằng ngày: mô phỏng THẬT, không suy luận suông
|--------------------------------------------------------------------------
|
| `config/backup.php` chọn `keep_all_backups_for_days = 29` (không phải 30) để bù việc
| `DefaultStrategy::removeBackupsOlderThan()` so sánh CHẶT (`<`) và lịch chạy `backup:clean`
| NGAY TRƯỚC `backup:run` (routes/console.php) — tại thời điểm dọn, bản của HÔM NAY chưa tồn tại.
|
| `Carbon::setTestNow()` ĐÓNG BĂNG "bây giờ" cho CẢ việc đặt mtime giả lập LẪN
| `DefaultStrategy::calculateDateRanges()` (nó tự gọi `Carbon::now()`). Không đóng băng, hai lần
| gọi "bây giờ" — một lúc dựng tệp giả, một lúc gói tính mốc dọn — lệch nhau vài phần nghìn giây
| do thời gian ghi 30 tệp giả; đúng lúc biên là MỘT NGÀY TRÒN, lệch dù chỉ vài mili giây cũng đủ
| đẩy tệp biên (29 ngày tuổi) qua bên kia của phép so sánh chặt. Đo được: bỏ dòng
| `Carbon::setTestNow()`, test dưới cho ra 28 bản thay vì 29 — không sai ở cấu hình, sai ở đồng hồ
| của chính bài test.
|
| Cả hai bài đều gọi `--disable-notifications`, tắt cờ STATIC `Spatie\Backup\Notifications\
| EventHandler::$enabled` — cờ này sống qua CẢ TIẾN TRÌNH test, không riêng tệp này, nên phải bật
| lại ở `afterEach` để không làm đỏ oan một bài test KHÁC (ví dụ `BackupNotificationsTest`) chạy
| sau trong CÙNG worker khi `--parallel` gộp hai tệp vào một tiến trình.
*/

beforeEach(fn () => Carbon::setTestNow(Carbon::create(2026, 3, 1, 2, 0, 0)));

afterEach(function () {
    Carbon::setTestNow();
    EventHandler::enable();
});

it('§10.8 sau 31 lượt dọn-rồi-sao-lưu liên tiếp mỗi ngày một lần, còn đúng 30 bản', function () {
    Storage::fake('cleanup_test_disk');
    $disk = Storage::disk('cleanup_test_disk');

    $name = config('backup.backup.name');

    // Trạng thái NGAY TRƯỚC lượt dọn thứ 31: 30 bản đã có, từ 1 đến 30 ngày tuổi (bản của "hôm
    // nay" — lượt thứ 31 — CHƯA được tạo, vì `backup:clean` chạy trước `backup:run`).
    for ($ageInDays = 1; $ageInDays <= 30; $ageInDays++) {
        $path = "{$name}/vk-crm-fake-age-{$ageInDays}.zip";
        $disk->put($path, 'noi-dung-gia-lap');

        touch($disk->path($path), now()->copy()->subDays($ageInDays)->getTimestamp());
    }

    config(['backup.backup.destination.disks' => ['cleanup_test_disk']]);
    Config::rebind();

    // Lượt dọn thứ 31.
    Artisan::call('backup:clean', ['--disable-notifications' => true]);

    // Lượt sao lưu thứ 31 — tạo bản của "hôm nay" (0 ngày tuổi).
    Artisan::call('backup:run', ['--only-files' => true, '--disable-notifications' => true]);

    expect($disk->allFiles($name))->toHaveCount(30);
});

it('§10.8 một mình backup:clean (chưa backup:run) giữ lại đúng 29 bản từ 30 bản', function () {
    // Test lát cắt hẹp hơn — cô lập riêng bước DỌN, tách khỏi bước SAO LƯU mới, để khi test trên
    // đỏ thì biết ngay lỗi nằm ở dọn dẹp hay ở tạo bản mới.
    Storage::fake('cleanup_test_disk_2');
    $disk = Storage::disk('cleanup_test_disk_2');

    $name = config('backup.backup.name');

    for ($ageInDays = 1; $ageInDays <= 30; $ageInDays++) {
        $path = "{$name}/vk-crm-fake-age-{$ageInDays}.zip";
        $disk->put($path, 'noi-dung-gia-lap');
        touch($disk->path($path), now()->copy()->subDays($ageInDays)->getTimestamp());
    }

    config(['backup.backup.destination.disks' => ['cleanup_test_disk_2']]);
    Config::rebind();

    Artisan::call('backup:clean', ['--disable-notifications' => true]);

    expect($disk->allFiles($name))->toHaveCount(29);
});
