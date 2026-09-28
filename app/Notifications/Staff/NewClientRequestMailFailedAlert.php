<?php

namespace App\Notifications\Staff;

use App\Models\Matter;
use Illuminate\Notifications\Notification;

/**
 * Thông báo TRONG HỆ THỐNG khi thư `staff.new_client_request` hỏng HẲN (hết `$tries` của
 * `App\Listeners\SendNewClientRequestNotification`) — gọi từ `App\Actions\Notification\
 * NotifyStaffOfNewClientRequest::reportFailure()`. Cùng hình dạng và cùng lý lẽ với
 * `App\Notifications\Staff\DocumentPublishedMailFailedAlert` — đọc docblock lớp đó.
 */
class NewClientRequestMailFailedAlert extends Notification
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
            'body' => __('requests.new_request_failed_notification.body', ['code' => $this->matter->code]),
            'color' => 'danger',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => 'danger',
            'title' => __('requests.new_request_failed_notification.title'),
            'view' => null,
            'viewData' => ['matter_id' => $this->matter->getKey()],
            'format' => 'filament',
        ];
    }
}
