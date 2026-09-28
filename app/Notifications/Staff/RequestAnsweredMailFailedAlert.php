<?php

namespace App\Notifications\Staff;

use App\Models\Matter;
use Illuminate\Notifications\Notification;

/**
 * Thông báo TRONG HỆ THỐNG khi thư `client.request_answered` hỏng HẲN — gọi từ
 * `App\Actions\Notification\NotifyClientOfRequestAnswered::reportFailure()`. Cùng hình dạng
 * `App\Notifications\Staff\DocumentPublishedMailFailedAlert`.
 */
class RequestAnsweredMailFailedAlert extends Notification
{
    public function __construct(
        private readonly Matter $matter,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'actions' => [],
            'body' => __('matters.request_answered_failed_notification.body', ['code' => $this->matter->code]),
            'color' => 'danger',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => 'danger',
            'title' => __('matters.request_answered_failed_notification.title'),
            'view' => null,
            'viewData' => ['matter_id' => $this->matter->getKey()],
            'format' => 'filament',
        ];
    }
}
