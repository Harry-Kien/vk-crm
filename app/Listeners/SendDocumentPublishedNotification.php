<?php

namespace App\Listeners;

use App\Actions\Notification\NotifyClientOfDocumentPublished;
use App\Events\DocumentPublished;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * Nối `App\Events\DocumentPublished` với `NotifyClientOfDocumentPublished` — cùng khuôn
 * `App\Listeners\SendStageUpdateNotification` (xem docblock lớp đó cho toàn bộ lý lẽ về
 * `ShouldQueue`, đăng ký bằng tự dò, và `$tries`/`$backoff`).
 */
class SendDocumentPublishedNotification implements ShouldQueue
{
    public int $tries = 5;

    public array $backoff = [60, 300, 900, 3600];

    public function __construct(private NotifyClientOfDocumentPublished $notify) {}

    public function handle(DocumentPublished $event): void
    {
        $this->notify->handle($event->document);
    }

    public function failed(DocumentPublished $event, ?Throwable $exception): void
    {
        $this->notify->reportFailure($event->document);
    }
}
