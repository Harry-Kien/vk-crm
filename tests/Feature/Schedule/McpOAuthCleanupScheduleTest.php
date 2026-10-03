<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| M11 Task 3 — lịch dọn OAuth: `passport:purge` 03:00, `vkcrm:mcp-prune-clients` 03:15
|--------------------------------------------------------------------------
| Cùng khuôn "ghim giờ" với `InstalmentReminderScheduleTest` (M6.5 Task 14, M9 Task 11): tra tác vụ
| theo TÊN máy đọc được (`->name()`, bí danh của `->description()` trong Laravel 13), khẳng định LỆNH
| ARTISAN THẬT nó chạy, rồi giờ chạy theo giờ Việt Nam (`config('app.timezone')`, ghim ở
| `BackupScheduleTest`). Hai lượt ở 03:00 và 03:15: sau sao lưu 02:00, trước mọi lượt gửi thư buổi
| sáng, không trùng phút với tác vụ nào khác.
*/

function mcpCleanupEvent(string $name, string $command): Event
{
    $matches = collect(Schedule::events())
        ->filter(fn (Event $event) => $event->description === $name)
        ->values();

    expect($matches)->toHaveCount(1, "phải có đúng một tác vụ lịch tên {$name}");

    $event = $matches->first();

    expect(preg_match('/ '.preg_quote($command, '/').'$/', (string) $event->command))->toBe(1, "tác vụ {$name} phải chạy {$command}");

    return $event;
}

it('R7 mcp.tokens.purge chạy passport:purge (mặc định: token và mã đã thu hồi, cùng cái hết hạn quá 7 ngày) lúc 03:00 hằng ngày, không phút nào khác', function () {
    $event = mcpCleanupEvent('mcp.tokens.purge', 'passport:purge');
    $today = today()->startOfDay();

    expect($event->getExpression())->toBe('0 3 * * *');

    $this->travelTo($today->copy()->setTime(3, 0));
    expect($event->isDue(app()))->toBeTrue('phải tới hạn lúc 03:00');

    foreach (['00:00', '02:00', '02:59', '03:01', '03:15', '15:00'] as $notDue) {
        [$hour, $minute] = explode(':', $notDue);
        $this->travelTo($today->copy()->setTime((int) $hour, (int) $minute));
        expect($event->isDue(app()))->toBeFalse("không được tới hạn lúc {$notDue}");
    }
});

it('R7 mcp.clients.prune chạy vkcrm:mcp-prune-clients lúc 03:15 hằng ngày, không phút nào khác', function () {
    $event = mcpCleanupEvent('mcp.clients.prune', 'vkcrm:mcp-prune-clients');
    $today = today()->startOfDay();

    expect($event->getExpression())->toBe('15 3 * * *');

    $this->travelTo($today->copy()->setTime(3, 15));
    expect($event->isDue(app()))->toBeTrue('phải tới hạn lúc 03:15');

    foreach (['00:00', '03:00', '03:14', '03:16', '15:15'] as $notDue) {
        [$hour, $minute] = explode(':', $notDue);
        $this->travelTo($today->copy()->setTime((int) $hour, (int) $minute));
        expect($event->isDue(app()))->toBeFalse("không được tới hạn lúc {$notDue}");
    }
});

it('R7 hai lượt dọn OAuth tới hạn cả ở một ngày khác (hằng ngày, không phải một lần)', function () {
    $this->travelTo(today()->addDays(9)->setTime(3, 0));
    expect(mcpCleanupEvent('mcp.tokens.purge', 'passport:purge')->isDue(app()))->toBeTrue();

    $this->travelTo(today()->setTime(3, 15));
    expect(mcpCleanupEvent('mcp.clients.prune', 'vkcrm:mcp-prune-clients')->isDue(app()))->toBeTrue();
});
