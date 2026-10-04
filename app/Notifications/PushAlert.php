<?php

namespace App\Notifications;

use App\Actions\Notification\RecordOutboundPush;
use App\Actions\Push\SendPushAlert;
use App\Enums\PushTopic;
use App\Support\Push\VapidKeys;
use App\Support\Push\VkWebPushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * Một thông báo đẩy tới MỌI máy đã bật của một người (M12 R12) — chỉ {@see SendPushAlert} dựng nó
 * (test cấu trúc `tests/Feature/Push/PushStructureTest.php`), và nó là lớp DUY NHẤT gửi qua
 * `WebPushChannel`.
 *
 * - `ShouldQueue` + `afterCommit` (M6.5 R2): xếp sau commit, không bao giờ chạy trong transaction
 *   của nghiệp vụ; rollback thì không có gì được gửi.
 * - Hàng đợi RIÊNG {@see self::QUEUE}: mỗi máy là một request HTTP ra máy chủ push (hạn 10 giây,
 *   `config/webpush.php`). Máy chủ push chậm nhân với số máy lúc 07:00 sẽ giữ hết lượt `queue.drain`
 *   (`--max-time=50`) trong khi thư nhắc hạn đứng chờ; lịch `queue.push` (`routes/console.php`) rút
 *   hàng này riêng.
 * - `tries = 3`, `backoff = [60, 300]`: chỉ thử lại khi CẢ job ném (CSDL, cấu hình hỏng). Một máy
 *   trả 4xx/5xx không phải lỗi của job — kênh ghi báo cáo từng máy (`NotificationSent` /
 *   `NotificationFailed`, {@see RecordOutboundPush}) và không gửi lại: push là tiện ích, thư mới là
 *   chứng cứ (R12).
 *
 * Thông điệp đã dựng sẵn ở lúc xếp ({@see PushTopic::message()}): đối tượng chỉ chuỗi và số, nên
 * `jobs.payload` không chép model nào, và một bản ghi bị xoá giữa lúc xếp và lúc gửi không làm hỏng
 * job. Người nhận đã chốt ở lúc xếp (R10: đúng collection người nhận của thư); trong vài phút chờ
 * hàng đợi, một tài khoản vừa bị vô hiệu vẫn có thể nhận MỘT câu chung — cùng cửa sổ của thư xếp
 * hàng, và nội dung không mang gì của hồ sơ (R11).
 *
 * Khoá VAPID bị gỡ giữa lúc xếp và lúc gửi: {@see self::shouldSend()} bỏ job êm (R7: push tắt êm),
 * thay vì để máy chủ push từ chối từng máy bằng 401/403.
 */
class PushAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'push';

    public int $tries = 3;

    public function __construct(public readonly VkWebPushMessage $message)
    {
        $this->afterCommit();
        $this->onQueue(self::QUEUE);
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    /** @return list<class-string> */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return VapidKeys::configured();
    }

    public function toWebPush(object $notifiable, Notification $notification): VkWebPushMessage
    {
        return $this->message;
    }
}
