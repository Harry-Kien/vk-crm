<?php

namespace App\Jobs;

use App\Actions\Notification\ResendOutboundMessage;
use App\Actions\Notification\ResendTargets;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Enums\Role;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Notifications\Staff\OutboundResendFailedAlert;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Lượt gửi lại thật của một dòng `outbound_messages` `failed` — dispatch bởi
 * {@see ResendOutboundMessage} bằng `->afterCommit()` (R2: mọi thư qua hàng đợi, sau commit).
 *
 * Payload chỉ mang id dòng nhật ký và id người bấm — không mang người nhận, không mang nội dung.
 * `handle()` đọc lại dòng, tìm lại bản ghi liên quan rồi chạy ĐÚNG đường gửi thật của mẫu
 * ({@see ResendTargets}): Action/Job gốc tự tính lại mọi cổng lúc-gửi và người nhận (R3/R12), tự bỏ
 * qua người đã có dòng `sent`, và `OutboundLedgerTransport` ghi một dòng MỚI cho mỗi thư — dòng cũ
 * không bao giờ bị sửa.
 *
 * `$tries`/`backoff()` là ĐÚNG ngân sách của các listener/job gốc (`SendStageUpdateNotification`,
 * `SendStaleMatterMail`, ...: 5 lượt, 60/300/900/3600 giây) — R2 "Job có `tries` và `backoff`": một
 * lần SMTP chết thoáng qua không biến lần gửi lại thành một lần hỏng. Thử lại an toàn vì đường gửi
 * thật tự bỏ qua người đã có dòng `sent` ở mỗi lượt. Mỗi lượt hỏng để lại một dòng `failed` mới
 * (cùng hình dạng listener gốc), và chỉ khi hết lượt {@see self::failed()} mới báo trong hệ thống.
 */
class ResendOutboundMessageJob implements ShouldQueue
{
    use Queueable;

    /** Một lần hỏng thoáng qua (SMTP chết tạm) không cần báo động ngay; xem `backoff()`. */
    public int $tries = 5;

    public function __construct(
        public readonly int $messageId,
        public readonly int $actorId,
    ) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(): void
    {
        $message = OutboundMessage::query()->withoutGlobalScopes()->find($this->messageId);
        $target = $message === null ? null : ResendTargets::for($message->template);
        $related = $message === null || $target === null ? null : ResendTargets::relatedOf($message, $target);

        // Cửa sổ hàng đợi: bản ghi có thể đã bị xoá cứng, hay (lần từ chối) đã được thay bằng một
        // lần mới giữa lúc bấm và lúc job chạy — cùng các cổng Action đã hỏi lúc bấm.
        if ($target === null || $related === null || ! ResendTargets::isSameInstance($message, $target, $related)) {
            return;
        }

        ($target->send)($related);
    }

    /**
     * Chạy đúng MỘT lần, sau khi CẢ `$tries` lượt đều hỏng: báo trong hệ thống cho NGƯỜI BẤM, qua
     * {@see ResolveStaffRecipients::handle()} với `[người bấm]` — R3: người bấm đã nghỉ việc hay
     * không còn xem được vụ thì chuỗi dự phòng (luật sư phụ trách → quản lý xem được vụ → admin)
     * nhận thay, "không bao giờ im lặng", và không có luật người nhận thứ hai. Dòng không quy được về
     * vụ nào (không xảy ra với tám mẫu gửi lại được, nhưng không đoán) → mọi admin đang hoạt động,
     * cùng lối `SendMissingDocumentsMail::failed()`.
     *
     * Câu chữ không nêu mã vụ việc, tên khách hay địa chỉ (Review Focus 1) — chỉ trỏ về nhật ký thư,
     * nơi các dòng `failed` mới đã ghi lý do.
     */
    public function failed(?Throwable $exception): void
    {
        $matter = OutboundMessage::query()->withoutGlobalScopes()->find($this->messageId)?->relatedMatter();

        $recipients = $matter !== null
            ? app(ResolveStaffRecipients::class)->handle($matter, [User::query()->find($this->actorId)])
            : User::query()->where('is_active', true)->role(Role::Admin->value)->get();

        foreach ($recipients as $recipient) {
            $recipient->notify(new OutboundResendFailedAlert($this->messageId));
        }
    }
}
