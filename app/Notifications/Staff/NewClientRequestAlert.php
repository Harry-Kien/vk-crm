<?php

namespace App\Notifications\Staff;

use App\Models\ClientRequest;
use Illuminate\Notifications\Notification;

/**
 * Thông báo TRONG HỆ THỐNG khi khách vừa mở một yêu cầu mới (`requests/REQ-1`) — gọi từ
 * `App\Actions\Notification\NotifyStaffOfNewClientRequest::handle()`.
 *
 * **Vì sao KHÔNG dùng `Filament\Notifications\Notification`.** Nơi gọi nó có thể là chính Action
 * này (`App\Actions\Notification\*`), và `tests/Feature/ArchitectureTest.php` cấm mọi
 * `App\Actions\*` dùng `Filament\...`. Cùng hình dạng `App\Notifications\Staff\
 * DocumentPublishedMailFailedAlert`/`DeadlineOverdueAlert`: một `Illuminate\Notifications\
 * Notification` thường, kênh `database`, `toDatabase()` tự dựng đúng hình dạng mảng mà
 * `Filament\Notifications\Notification::getDatabaseMessage()` sinh ra.
 *
 * **Không `ShouldQueue`.** Kênh `database` chỉ là một câu INSERT, không chạm mạng. R2 ("mọi thư
 * qua hàng đợi") nói về THƯ; thông báo trong hệ thống là việc khác, và tách rời khỏi việc gửi thư
 * đúng là mục đích của lớp này — xem docblock `NotifyStaffOfNewClientRequest::handle()`: "thông
 * báo trong hệ thống là một câu INSERT, không được mất chỉ vì thư hỏng."
 */
class NewClientRequestAlert extends Notification
{
    public function __construct(
        private readonly ClientRequest $request,
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
            // `e()` MỌI giá trị nội suy — vòng sửa 1, finding Critical 1: `subject` do CHÍNH
            // khách gõ, và Filament render body của thông báo trong hệ thống bằng
            // `str($body)->sanitizeHtml()`, mà cấu hình sanitizer của nó vẫn GIỮ LẠI `<a href>`,
            // `<img>` và thuộc tính `style` — một khách gõ một thẻ `<a>` toàn màn hình biến chuông
            // thông báo `/admin` thành một lớp phủ lừa đảo có thể bấm được. Thư `staff.
            // new_client_request` (Blade, `{{ }}`) đã escape đúng từ đầu; đây là kênh còn hở.
            'body' => __('requests.new_request_notification.body', [
                'code' => e($this->request->matter?->code ?? ''),
                'subject' => e($this->request->subject),
            ]),
            'color' => 'info',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => 'info',
            'title' => __('requests.new_request_notification.title'),
            'view' => null,
            // Khoá chống trùng của `App\Actions\Notification\NotifyStaffOfNewClientRequest::
            // alreadyAlerted()` — cùng thiết bị `DeadlineOverdueAlert`.
            'viewData' => ['client_request_id' => $this->request->getKey()],
            'format' => 'filament',
        ];
    }
}
