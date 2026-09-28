<?php

namespace App\Listeners;

use App\Actions\Notification\NotifyStaffOfNewClientRequest;
use App\Events\ClientRequestOpened;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * Nối `App\Events\ClientRequestOpened` với `NotifyStaffOfNewClientRequest` — cùng khuôn
 * `App\Listeners\SendDocumentPublishedNotification` (xem docblock lớp đó cho lý lẽ về
 * `ShouldQueue`, đăng ký bằng tự dò, và `$tries`/`$backoff`).
 */
class SendNewClientRequestNotification implements ShouldQueue
{
    public int $tries = 5;

    public array $backoff = [60, 300, 900, 3600];

    public function __construct(private NotifyStaffOfNewClientRequest $notify) {}

    public function handle(ClientRequestOpened $event): void
    {
        $this->notify->handle($event->request);
    }

    public function failed(ClientRequestOpened $event, ?Throwable $exception): void
    {
        $this->notify->reportFailure($event->request);
    }
}
