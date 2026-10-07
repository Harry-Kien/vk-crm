<?php

use App\Actions\Schedule\CheckDocumentStoreHealth;
use App\Models\SystemHealth;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| M14 Task 5 — mục lịch `storage.health` (kế hoạch Task 5: mỗi giờ, `withoutOverlapping(30)`)
|--------------------------------------------------------------------------
*/

function t5StorageHealthEvent(): Event
{
    $matches = collect(Schedule::events())
        ->filter(fn (Event $event) => $event->description === 'storage.health')
        ->values();

    expect($matches)->toHaveCount(1, 'phải có đúng một tác vụ lịch tên storage.health');

    return $matches->first();
}

it('storage.health chạy mỗi giờ ở phút 20, không chồng lấn, khoá tự hết hạn sau 30 phút', function () {
    $event = t5StorageHealthEvent();

    expect($event)->toBeInstanceOf(CallbackEvent::class)
        ->and($event->getExpression())->toBe('20 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(30);
});

it('storage.health gọi đúng CheckDocumentStoreHealth (chạy mục lịch thật ghi dòng sức khoẻ)', function () {
    Http::preventStrayRequests();
    config(['vkcrm.storage.driver' => 'local']);

    expect(class_exists(CheckDocumentStoreHealth::class))->toBeTrue();

    t5StorageHealthEvent()->run(app());

    expect(SystemHealth::query()->where('singleton', 1)->value('document_store_checked_at'))->not->toBeNull();
});
