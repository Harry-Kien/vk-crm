<?php

namespace App\Support\Push;

use App\Actions\Notification\RecordOutboundPush;
use App\Enums\PushTopic;
use NotificationChannels\WebPush\WebPushMessage;
use NotificationChannels\WebPush\WebPushMessageInterface;

/**
 * Một thông báo đẩy đã dựng xong (M12 R11, R13) — chỉ {@see PushTopic::message()} tạo ra nó.
 *
 * KHÔNG kế thừa `WebPushMessage` của gói: `toArray()` của lớp đó là `get_object_vars($this)` trừ
 * `options`, nên MỌI thuộc tính thêm vào lớp con (chủ đề, bản ghi liên quan) sẽ lọt vào payload mà
 * máy chủ push chuyển tới màn hình khoá. Ở đây {@see self::toArray()} liệt kê TƯỜNG MINH đúng các
 * khoá của R11 — `title`, `body`, `icon`, `badge`, `tag`, `data.url` — không hơn.
 *
 * Siêu dữ liệu cho nhật ký (`topic`, `relatedType`, `relatedId`) nằm NGOÀI payload: chỉ
 * {@see RecordOutboundPush} đọc nó, qua sự kiện của gói, để dòng `outbound_messages` mang đúng
 * `template` và `related_type`/`related_id` mà dòng thư tương ứng mang.
 *
 * {@see self::getOptions()}: `TTL` và `urgency` (header của RFC 8030) — tuỳ chọn gửi, không vào
 * payload.
 *
 * Đối tượng chỉ mang chuỗi và số, nên `PushAlert` (job hàng đợi) tuần tự hoá được nó vào
 * `jobs.payload` mà không chép model nào — và không có chữ nào ở đó mà màn hình khoá không thấy.
 *
 * {@see WebPushMessage}
 */
final class VkWebPushMessage implements WebPushMessageInterface
{
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly string $icon,
        public readonly string $badge,
        public readonly string $tag,
        public readonly string $url,
        public readonly int $ttl,
        public readonly string $urgency,
        public readonly string $topic,
        public readonly ?string $relatedType,
        public readonly ?int $relatedId,
    ) {}

    /**
     * Payload gửi tới máy (mã hoá aes128gcm trước khi rời máy chủ) — đúng các khoá của R11.
     *
     * @return array{title: string, body: string, icon: string, badge: string, tag: string, data: array{url: string}}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'icon' => $this->icon,
            'badge' => $this->badge,
            'tag' => $this->tag,
            'data' => ['url' => $this->url],
        ];
    }

    /** @return array{TTL: int, urgency: string} */
    public function getOptions(): array
    {
        return ['TTL' => $this->ttl, 'urgency' => $this->urgency];
    }
}
