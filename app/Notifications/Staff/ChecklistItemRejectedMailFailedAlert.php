<?php

namespace App\Notifications\Staff;

use App\Models\Matter;
use Illuminate\Notifications\Notification;

/**
 * Thông báo TRONG HỆ THỐNG khi thư `client.document_rejected` hỏng HẲN (hết `$tries` của
 * `App\Listeners\SendChecklistItemRejectedNotification`) — gọi từ
 * `App\Actions\Notification\NotifyClientOfChecklistItemRejected::reportFailure()`.
 *
 * Cùng lý lẽ và cùng hình dạng {@see DocumentPublishedMailFailedAlert} (đọc docblock lớp đó): một
 * `Illuminate\Notifications\Notification` thường vì nơi gọi là một Action, không phải job/listener
 * — `App\Actions\*` không được dùng `Filament\...`.
 */
class ChecklistItemRejectedMailFailedAlert extends Notification
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
            'body' => __('matters.document_rejected_failed_notification.body', ['code' => $this->matter->code]),
            'color' => 'danger',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => 'danger',
            'title' => __('matters.document_rejected_failed_notification.title'),
            'view' => null,
            'viewData' => ['matter_id' => $this->matter->getKey()],
            'format' => 'filament',
        ];
    }
}
