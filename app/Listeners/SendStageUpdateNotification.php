<?php

namespace App\Listeners;

use App\Actions\Notification\NotifyClientOfStageUpdate;
use App\Events\StageLogPublished;

/**
 * Nối `StageLogPublished` với Action gửi thư. Mỏng có chủ ý: luật nằm trong Action, listener chỉ
 * là sợi dây — đúng luật của CLAUDE.md, và nhờ vậy một test gọi thẳng Action được mà không phải
 * dựng sự kiện.
 *
 * ĐĂNG KÝ BẰNG TỰ DÒ, không đăng ký tường minh. `Application::configure()` gọi `withEvents()`
 * theo mặc định, nên Laravel tự tìm lớp trong `app/Listeners` có phương thức `handle` và suy
 * kiểu sự kiện từ tham số. Vòng làm nhật ký thư đã trả giá cho bài học ngược lại: đặt tên
 * `handleMessageSending` RỒI đăng ký thêm bằng tay khiến mỗi thư sinh hai dòng nhật ký. Ở đây
 * chỉ có đúng một đường đăng ký, và có test khẳng định sự kiện thật sự gọi tới lớp này.
 */
class SendStageUpdateNotification
{
    public function __construct(private NotifyClientOfStageUpdate $notify) {}

    public function handle(StageLogPublished $event): void
    {
        $this->notify->handle($event->stageLog);
    }
}
