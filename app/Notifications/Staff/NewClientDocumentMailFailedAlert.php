<?php

namespace App\Notifications\Staff;

use App\Models\Matter;
use Illuminate\Notifications\Notification;

/**
 * Thông báo TRONG HỆ THỐNG khi thư `staff.new_client_document` hỏng HẲN — gọi từ
 * `App\Actions\Notification\NotifyStaffOfNewClientDocument::reportFailure()`. Cùng hình dạng
 * {@see NewClientRequestMailFailedAlert}.
 */
class NewClientDocumentMailFailedAlert extends Notification
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
            'body' => __('requests.new_document_failed_notification.body', ['code' => $this->matter->code]),
            'color' => 'danger',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => 'danger',
            'title' => __('requests.new_document_failed_notification.title'),
            'view' => null,
            'viewData' => ['matter_id' => $this->matter->getKey()],
            'format' => 'filament',
        ];
    }
}
