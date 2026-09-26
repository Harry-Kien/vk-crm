<?php

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

it('§10.8 config(app.timezone) là giờ Việt Nam, nên 02:00/08:00 là giờ văn phòng', function () {
    expect(config('app.timezone'))->toBe('Asia/Ho_Chi_Minh');
});

it('§10.8 đăng ký backup.clean lúc 02:00, không chồng lấn, rồi gọi backup:run', function () {
    $event = collect(Schedule::events())->first(fn ($e) => $e->description === 'Dọn bản sao lưu cũ trước khi sao lưu mới');

    expect($event)->not->toBeNull()
        ->and($event->getExpression())->toBe('0 2 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('§10.8 đăng ký backup.monitor lúc 08:00, không chồng lấn', function () {
    $event = collect(Schedule::events())->first(fn ($e) => $e->description === 'Giám sát sức khoẻ bản sao lưu');

    expect($event)->not->toBeNull()
        ->and($event->getExpression())->toBe('0 8 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('§10.8 backup:clean gọi backup:run dù thất bại, không chỉ khi thành công', function () {
    $event = collect(Schedule::events())->first(fn ($e) => $e->description === 'Dọn bản sao lưu cũ trước khi sao lưu mới');

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
