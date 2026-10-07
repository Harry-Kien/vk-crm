<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| M14 Task 7 — mục lịch `storage.office-receipts` (kế hoạch R10: 07:00 hằng ngày, `withoutOverlapping(60)`)
|--------------------------------------------------------------------------
|
| Cùng khuôn `BackupScheduleTest`: tìm theo TÊN, khẳng định LỆNH thật nó chạy. 07:00 là sau lượt kéo
| 01:00 của máy văn phòng (Phụ lục D) và trước lượt kiểm sao lưu 08:00. Khoá chồng lấn 60 phút, không
| 1440 mặc định: một lượt bị giết giữa chừng không được chặn lượt của ngày sau.
*/

it('storage.office-receipts chạy vkcrm:storage:office-receipts lúc 07:00, không chồng lấn, khoá hết hạn sau 60 phút', function () {
    $matches = collect(Schedule::events())
        ->filter(fn (Event $event) => $event->description === 'storage.office-receipts')
        ->values();

    expect($matches)->toHaveCount(1, 'phải có đúng một tác vụ lịch tên storage.office-receipts');

    $event = $matches->first();

    expect(preg_match('/ vkcrm:storage:office-receipts$/', (string) $event->command))->toBe(1)
        ->and($event->getExpression())->toBe('0 7 * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(60);
});
