<?php

namespace App\Notifications\Staff;

use App\Models\Document;
use Illuminate\Notifications\Notification;

/**
 * Thông báo TRONG HỆ THỐNG khi khách vừa nộp tài liệu — gọi từ
 * `App\Actions\Notification\NotifyStaffOfNewClientDocument::handle()`. Cùng hình dạng và cùng lý
 * lẽ với {@see NewClientRequestAlert} — đọc docblock lớp đó.
 */
class NewClientDocumentAlert extends Notification
{
    public function __construct(
        private readonly Document $firstDocument,
        private readonly int $count,
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
            'body' => __('requests.new_document_notification.body', [
                'code' => $this->firstDocument->matter?->code ?? '',
                'item' => $this->firstDocument->checklistItem?->name ?? '',
                'count' => $this->count,
            ]),
            'color' => 'info',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => 'info',
            'title' => __('requests.new_document_notification.title'),
            'view' => null,
            // Khoá chống trùng: `NotifyStaffOfNewClientDocument::alreadyAlerted()` khoá theo tài
            // liệu ĐẠI DIỆN của lô (`$firstDocument` — mọi tài liệu trong một lô cùng
            // `matter_checklist_item_id`, xem docblock `App\Events\ClientDocumentSubmitted`), nên
            // một khoá theo id của nó là khoá đúng CẢ LÔ, không riêng một tệp.
            'viewData' => ['document_id' => $this->firstDocument->getKey()],
            'format' => 'filament',
        ];
    }
}
