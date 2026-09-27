<?php

use App\Actions\Backup\CheckRcloneRemoteFreshness;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| §10.8 — lịch sao lưu: backup:clean + backup:run lúc 02:00, backup:monitor lúc 08:00
|--------------------------------------------------------------------------
|
| Cùng khuôn với test ghim giờ có sẵn của `CheckDeadlines` (`SystemHealthTest`, test "runs the
| schedule on Vietnam time" khẳng định `config('app.timezone')`) — Laravel chạy TOÀN BỘ lịch theo
| múi giờ ứng dụng, nên "02:00"/"08:00" chỉ có ý nghĩa khi múi giờ đó là giờ Việt Nam.
*/

/*
 * Tìm tác vụ theo TÊN (`->name('backup.nightly')`, lượt rà soát cuối M8a, I5) — cùng quy ước M6.5
 * áp cho mọi tác vụ lịch: trong Laravel 13 `name()` và `description()` là BÍ DANH của nhau (cùng
 * ghi `$event->description`), nên mỗi tác vụ chỉ gọi `->name()`, và tên máy đọc được đó là khoá tra
 * cứu ổn định. Rồi khẳng định thêm LỆNH ARTISAN THẬT nó chạy (`$event->command`), vì lệnh mới là
 * thứ chạy lúc 02:00 (fix I7 của Task 1).
 */
function backupScheduleEvent(string $name, string $command): Event
{
    $matches = collect(Schedule::events())
        ->filter(fn (Event $event) => $event->description === $name)
        ->values();

    expect($matches)->toHaveCount(1, "phải có đúng một tác vụ lịch tên {$name}");

    $event = $matches->first();

    expect(preg_match('/ '.preg_quote($command, '/').'$/', (string) $event->command))->toBe(1, "tác vụ {$name} phải chạy {$command}");

    return $event;
}

it('§10.8 config(app.timezone) là giờ Việt Nam, nên 02:00/08:00 là giờ văn phòng', function () {
    expect(config('app.timezone'))->toBe('Asia/Ho_Chi_Minh');
});

it('§10.8 backup.nightly chạy backup:clean lúc 02:00, không chồng lấn, khoá tự hết hạn sau 6 giờ', function () {
    $event = backupScheduleEvent('backup.nightly', 'backup:clean');

    // `expiresAt` 360 phút (fix I5, lượt rà soát cuối M8a): mặc định của `withoutOverlapping()` là
    // 1440 phút — một tiến trình bị giết giữa chừng (máy chủ khởi động lại lúc 02:30) để lại khoá
    // chặn luôn lượt sao lưu của ĐÊM SAU. 6 giờ đủ dài cho một lượt sao lưu vài GB, đủ ngắn để hết
    // hạn trước 02:00 hôm sau.
    expect($event->getExpression())->toBe('0 2 * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(360);
});

it('§10.8 backup.monitor chạy backup:monitor lúc 08:00, không chồng lấn', function () {
    $event = backupScheduleEvent('backup.monitor', 'backup:monitor');

    expect($event->getExpression())->toBe('0 8 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('§10.8 backup.monitor kiểm luôn độ tươi của bản trên đích rclone (fix I4), dù backup:monitor thất bại', function () {
    $event = backupScheduleEvent('backup.monitor', 'backup:monitor');

    $after = (new ReflectionProperty($event, 'afterCallbacks'))->getValue($event);
    expect($after)->toHaveCount(1);

    $event->exitCode = 1;

    $this->mock(CheckRcloneRemoteFreshness::class, fn ($mock) => $mock->shouldReceive('handle')->once());

    app()->call($after[0]);
});

it('§10.8 backup:clean gọi backup:run dù thất bại, không chỉ khi thành công', function () {
    $event = backupScheduleEvent('backup.nightly', 'backup:clean');

    $after = (new ReflectionProperty($event, 'afterCallbacks'))->getValue($event);
    expect($after)->toHaveCount(1);

    // `Event::onSuccess()` cũng nạp callback vào CÙNG mảng `afterCallbacks` (bọc một điều kiện
    // `exitCode === 0` bên trong) — đếm số callback KHÔNG phân biệt được `->then()` với
    // `->onSuccess()`. Mô phỏng `backup:clean` THẤT BẠI (exit code khác 0) rồi tự gọi callback:
    // nếu ai đó đổi `->then()` thành `->onSuccess()`, `Artisan::call('backup:run')` sẽ KHÔNG
    // chạy ở đây, và `shouldReceive(...)->once()` bên dưới sẽ làm test đỏ khi container hết hạn.
    $event->exitCode = 1;

    Artisan::shouldReceive('call')->once()->with('backup:run');

    app()->call($after[0]);
});
