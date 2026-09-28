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
            'body' => __('requests.new_request_notification.body', [
                'code' => $this->request->matter?->code ?? '',
                'subject' => $this->request->subject,
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
