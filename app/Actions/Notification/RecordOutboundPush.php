<?php

namespace App\Actions\Notification;

use App\Enums\OutboundChannel;
use App\Enums\OutboundStatus;
use App\Enums\PushTopic;
use App\Listeners\RecordOutboundPushReport;
use App\Models\OutboundMessage;
use App\Support\Push\VkWebPushMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Minishlink\WebPush\MessageSentReport;
use NotificationChannels\WebPush\PushSubscription;
use NotificationChannels\WebPush\WebPushMessageInterface;
use Throwable;

/**
 * Nhật ký của kênh thông báo đẩy (M12 R13, SPEC §4.15 đính chính 2026-10-04) — "cánh cửa riêng" mà
 * {@see RecordOutboundMessage} (cửa của email) nói một kênh khác phải có. Gọi từ
 * {@see RecordOutboundPushReport}, listener của hai sự kiện mà `ReportHandler` của gói phát cho MỖI
 * máy sau mỗi lần gửi: `NotificationSent` → {@see self::record()} với `sent`, `NotificationFailed` →
 * `failed`. Mỗi máy một dòng.
 *
 * # Không bao giờ ghi endpoint (R8)
 *
 * Endpoint là một URL mang quyền gửi thông báo tới máy đó. Dòng nhật ký mang:
 *  - `recipient` = `{bí danh morph}:{id}` của CHỦ máy (`client_user:12`, `user:7`) — không endpoint;
 *  - `template` = giá trị {@see PushTopic} (trùng tên mẫu thư đi cùng);
 *  - `related_type`/`related_id` = siêu dữ liệu của {@see VkWebPushMessage} — ĐÚNG bản ghi mà dòng thư
 *    tương ứng mang, nên luật "ai xem dòng nào" (`OutboundMessage::scopeVisibleTo()`) áp nguyên và tab
 *    "Thư đã gửi" của trang vụ việc tự hiện dòng push;
 *  - `payload` = chỉ `title` và `body` (hai câu chung của R11);
 *  - `error` = mã HTTP và lý do của máy chủ push ({@see self::error()}), đã gỡ endpoint: máy chủ push
 *    có thể nhắc lại endpoint trong thân trả lời, và Guzzle ghép URL vào câu báo lỗi kết nối
 *    ("… for https://…").
 *
 * # Dòng push không đi vào luật chống trùng nào của thư
 *
 * Mọi truy vấn chống trùng đọc `outbound_messages` (`NotifyClientOf*::alreadyDelivered()`,
 * `NotifyStaffOf*::alreadyDelivered()`, `SendDeadlineReminderMail::alreadyDelivered()`,
 * `SendInstalmentOverdueMail::alreadyReminded()`, `SendStaleMatterMail` và
 * `RemindMissingDocuments::alreadyDelivered()`) lọc `recipient = <email>` — `client_user:12` không bao
 * giờ là một địa chỉ email (không có `@`). Truy vấn duy nhất KHÔNG lọc người nhận,
 * `CheckStaleMatters::recentlyMailed()`, lọc `template = staff.stale_matter` — mẫu mà push cố ý không
 * bao giờ có (R10, test ghim ở `PushTopicTest`). Nút "Gửi lại" của nhật ký thì từ chối mọi dòng không
 * phải email (`ResendOutboundMessage::canResend()`): giá trị chủ đề trùng tên mẫu thư, nên thiếu cổng
 * đó một dòng push hỏng sẽ gửi lại một THƯ.
 *
 * # Không bao giờ ném
 *
 * Hàm chạy BÊN TRONG vòng `handleReports()` của kênh: một lỗi ở đây (CSDL) sẽ cắt ngang việc xử lý báo
 * cáo của các máy còn lại (đăng ký 404/410 không được xoá) và làm job thử lại — gửi lại tới MỌI máy.
 * Lỗi được ghi cảnh báo chỉ mang tên lớp ngoại lệ; dòng nhật ký đó mất, push thì đã đi.
 */
class RecordOutboundPush
{
    /** Độ dài tối đa của phần thân trả lời giữ lại trong `error` (đủ cho `{"reason":"…"}` của Apple). */
    private const BODY_EXCERPT = 300;

    public function record(MessageSentReport $report, PushSubscription $subscription, WebPushMessageInterface $message): void
    {
        try {
            $sent = $report->isSuccess();
            $content = $message->toArray();

            OutboundMessage::query()->withoutGlobalScopes()->create([
                'channel' => OutboundChannel::Push,
                'recipient' => Str::limit($subscription->subscribable_type.':'.$subscription->subscribable_id, 200, ''),
                'template' => $message instanceof VkWebPushMessage ? $message->topic : OutboundMessage::TEMPLATE_UNDECLARED,
                'payload' => [
                    'title' => (string) ($content['title'] ?? ''),
                    'body' => (string) ($content['body'] ?? ''),
                ],
                'related_type' => $message instanceof VkWebPushMessage ? $message->relatedType : null,
                'related_id' => $message instanceof VkWebPushMessage ? $message->relatedId : null,
                'status' => $sent ? OutboundStatus::Sent : OutboundStatus::Failed,
                'sent_at' => $sent ? now() : null,
                'error' => $sent ? null : $this->error($report, $subscription->endpoint),
            ]);
        } catch (Throwable $e) {
            Log::warning('Không ghi được nhật ký thông báo đẩy.', ['exception' => $e::class]);
        }
    }

    /**
     * `HTTP {mã} {lý do}` (cộng một đoạn thân trả lời nếu có) khi máy chủ push trả lời; lý do lỗi kết
     * nối khi không có trả lời. Endpoint (và mọi URL) bị thay trước khi ghi.
     */
    private function error(MessageSentReport $report, string $endpoint): string
    {
        $response = $report->getResponse();

        if ($response === null) {
            return Str::limit($this->scrub($report->getReason(), $endpoint), 1000);
        }

        $line = trim('HTTP '.$response->getStatusCode().' '.$response->getReasonPhrase());
        $stream = $response->getBody();

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $body = trim((string) preg_replace('/\s+/', ' ', $stream->getContents()));
        $body = Str::limit($this->scrub($body, $endpoint), self::BODY_EXCERPT);

        return $body === '' ? $line : $line.': '.$body;
    }

    /**
     * Gỡ endpoint khỏi một câu: nguyên văn, dạng thoát của JSON (`https:\/\/…`), dạng mã hoá URL, phần
     * path riêng — rồi mọi URL còn lại. Host đứng một mình được giữ (`Failed to connect to … port 443`):
     * nó chỉ nói máy chủ push nào, không mang quyền gửi.
     */
    private function scrub(string $text, string $endpoint): string
    {
        $path = (string) parse_url($endpoint, PHP_URL_PATH);
        $forms = array_filter([
            $endpoint,
            str_replace('/', '\/', $endpoint),
            rawurlencode($endpoint),
            urlencode($endpoint),
            strlen($path) > 1 ? $path : null,
            strlen($path) > 1 ? str_replace('/', '\/', $path) : null,
        ]);

        $text = str_replace($forms, '[endpoint]', $text);

        return (string) preg_replace('~https?:(?:\\\\?/){2}[^\s"\'<>]+~i', '[url]', $text);
    }
}
