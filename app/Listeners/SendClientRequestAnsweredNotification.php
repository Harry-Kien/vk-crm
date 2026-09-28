<?php

namespace App\Listeners;

use App\Actions\Notification\NotifyClientOfRequestAnswered;
use App\Events\ClientRequestAnswered;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * Nối `App\Events\ClientRequestAnswered` với `NotifyClientOfRequestAnswered` — cùng khuôn
 * `App\Listeners\SendDocumentPublishedNotification`.
 */
class SendClientRequestAnsweredNotification implements ShouldQueue
{
    public int $tries = 5;

    public array $backoff = [60, 300, 900, 3600];

    public function __construct(private NotifyClientOfRequestAnswered $notify) {}

    public function handle(ClientRequestAnswered $event): void
    {
        $this->notify->handle($event->reply);
    }

    public function failed(ClientRequestAnswered $event, ?Throwable $exception): void
    {
        $this->notify->reportFailure($event->reply);
    }
}
