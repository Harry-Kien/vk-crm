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
            // `e()` `code`/`item` — vòng sửa 1, finding Critical 1 (cùng lý lẽ
            // `NewClientRequestAlert`, đọc docblock lớp đó): `item` (`MatterChecklistItem::name`)
            // do văn phòng gõ khi tạo danh mục hồ sơ, không phải khách, nhưng Filament vẫn render
            // body của thông báo trong hệ thống qua `str($body)->sanitizeHtml()` — cùng kênh hở.
            // `count` là `int`, không cần escape.
            'body' => __('requests.new_document_notification.body', [
                'code' => e($this->firstDocument->matter?->code ?? ''),
                'item' => e($this->firstDocument->checklistItem?->name ?? ''),
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
