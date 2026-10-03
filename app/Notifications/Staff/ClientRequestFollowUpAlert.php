<?php

namespace App\Notifications\Staff;

use App\Models\ClientRequestReply;
use Illuminate\Notifications\Notification;

/**
 * Thông báo TRONG HỆ THỐNG khi khách viết THÊM vào một luồng đã có (`requests/REQ-2`, đính chính
 * 2026-09-27) — gọi từ `App\Actions\Portal\ReplyToClientRequest::handle()`, NGOÀI transaction
 * (xem docblock ở đó cho lý do bắt buộc: luật kiến trúc cấm `->notify(` bên trong
 * `DB::transaction()` ở `app/Actions`).
 *
 * **Không thư đi kèm — quyết định của implementer, controller có thể đổi.** Brief để "thư tuỳ
 * chọn"; SPEC §9 không liệt kê một mẫu thư nào cho việc này (bảng mẫu chỉ có `client.
 * request_answered`, không có gì đối xứng phía nhân sự cho một câu hỏi tiếp), nên thêm một mẫu
 * thư mới ở đây là mở rộng ngoài SPEC mà không có yêu cầu rõ — trong khi thông báo trong hệ thống
 * (bắt buộc theo brief) đã đóng đúng lỗ hổng REQ-2 nêu tên: "không ai trong văn phòng được báo."
 *
 * Cùng hình dạng {@see NewClientRequestAlert} — không `ShouldQueue`, kênh `database`.
 */
class ClientRequestFollowUpAlert extends Notification
{
    public function __construct(
        private readonly ClientRequestReply $reply,
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
            // `e()` `code` — vòng sửa 1, finding Critical 1 (cùng lý lẽ `NewClientRequestAlert`).
            'body' => __('requests.followup_notification.body', [
                'code' => e($this->reply->request?->matter?->code ?? ''),
            ]),
            'color' => 'warning',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => 'warning',
            'title' => __('requests.followup_notification.title'),
            'view' => null,
            // Khoá theo CHÍNH câu trả lời (một lần khách viết thêm = một dòng
            // `client_request_replies`, không lặp lại được), nên không cần chống trùng thêm —
            // đây là khoá NHẬN DẠNG, không phải khoá chống trùng.
            'viewData' => ['client_request_reply_id' => $this->reply->getKey()],
            'format' => 'filament',
        ];
    }
}
