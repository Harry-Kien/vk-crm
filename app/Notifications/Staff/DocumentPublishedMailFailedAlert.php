<?php

namespace App\Notifications\Staff;

use App\Models\Matter;
use Illuminate\Notifications\Notification;

/**
 * Thông báo TRONG HỆ THỐNG khi thư `client.document_published` hỏng HẲN (hết `$tries` của
 * `App\Listeners\SendDocumentPublishedNotification`) — gọi từ
 * `App\Actions\Notification\NotifyClientOfDocumentPublished::reportFailure()`.
 *
 * **Vì sao lớp này KHÔNG dùng `Filament\Notifications\Notification`.** Nơi gọi nó là một Action
 * (`App\Actions\Notification\NotifyClientOfDocumentPublished`), và `tests/Feature/
 * ArchitectureTest.php` ("nghiệp vụ không phụ thuộc vào Filament") cấm mọi `App\Actions\*` dùng
 * `Filament\...` — kể cả `Notification::make()->sendToDatabase()`/`->toDatabase()`. Cùng lý lẽ và
 * cùng hình dạng `App\Notifications\Staff\DeadlineOverdueAlert` (đọc docblock lớp đó): một
 * `Illuminate\Notifications\Notification` thường, kênh `database`, `toDatabase()` tự dựng ĐÚNG
 * hình dạng mảng mà `Filament\Notifications\Notification::getDatabaseMessage()` sinh ra, để chuông
 * của panel admin đọc được dòng này qua `Notification::fromDatabase()` như mọi thông báo khác.
 *
 * Sổ tay lane m6 ghi rõ: "`NotifyClientOfStageUpdate` hiện import `Filament\Notifications\
 * Notification` dù nằm trong `App\Actions` — đừng chép lối đó sang Action mới." Lớp này là kết quả
 * của lời nhắc đó, không phải một lối tắt bị sao chép.
 *
 * Không `ShouldQueue`: kênh `database` chỉ là một câu INSERT, không chạm mạng — R2 ("mọi thư qua
 * hàng đợi") nói về THƯ, và `->notify()` trên một Notification không `ShouldQueue` chạy đồng bộ,
 * không xếp thêm một job nào — đúng lúc gọi nó là chính bên trong `failed()` của một job KHÁC vừa
 * hỏng hẳn.
 */
class DocumentPublishedMailFailedAlert extends Notification
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
            'body' => __('matters.document_published_failed_notification.body', ['code' => $this->matter->code]),
            'color' => 'danger',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => 'danger',
            'title' => __('matters.document_published_failed_notification.title'),
            'view' => null,
            'viewData' => ['matter_id' => $this->matter->getKey()],
            'format' => 'filament',
        ];
    }
}
