<?php

namespace App\Actions\Notification;

use App\Enums\OutboundStatus;
use App\Mail\Client\DocumentPublished as DocumentPublishedMail;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Notifications\Staff\DocumentPublishedMailFailedAlert;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * SPEC §9 mẫu `client.document_published`. Lấp `notify/notify-5`/`docs/docs-3` (audit
 * 2026-09-24): `App\Events\DocumentPublished` bắn ở `PublishDocument::handle()` (chỉ lần công bố
 * ĐẦU, chỉ nhóm B/C) từ lâu mà chưa từng có listener — khách không bao giờ được báo có văn bản
 * mới của toà hay văn phòng.
 *
 * **Cùng khuôn** `NotifyClientOfStageUpdate` (Task 1, khuôn mẫu brief chỉ định): kiểm tra lại lúc
 * gửi, thử từng người nhận độc lập rồi ném lại lỗi đầu tiên, chống gửi trùng bằng nhật ký thư,
 * `reportFailure()` báo luật sư phụ trách qua `ResolveStaffRecipients` khi listener hỏng hẳn.
 *
 * **Khác `NotifyClientOfStageUpdate` ở đúng hai chỗ:**
 *  - Không có cột kiểu `stage_logs.notified_at` để chống trùng ở TẦM DÒNG — `documents` không có
 *    cột "đã báo" (R3 cấm thêm cột không có trong SPEC §4.13). Chống trùng ở đây vì vậy CHỈ ở tầm
 *    "đã gửi cho ĐÚNG người nhận này chưa", giống hệt `alreadyDelivered()` của lớp anh em — không
 *    cần thêm khoá gì khác vào header, vì `DocumentPublished` chỉ bắn ĐÚNG MỘT LẦN cho một tài
 *    liệu (xem docblock sự kiện: "Chỉ lần công bố đầu tiên").
 *  - Kiểm tra lại lúc gửi hỏi `Document::isReleasedToPortal()` (còn `client_can_view`, còn
 *    `published`, còn ngoài nhóm D, chưa xoá mềm) VÀ `Matter::open()` của chính vụ việc (cùng cách
 *    `App\Jobs\SendDeadlineReminderMail` hỏi `Matter::open()` — brief Task 3 chỉ đích danh câu
 *    này) — một tài liệu có thể đã bị chuyển sang nhóm D hoặc gỡ cổng, và vụ việc có thể đã bị huỷ
 *    hoặc đóng, trong cửa sổ hàng đợi giữa lúc sự kiện bắn và lúc listener chạy.
 */
class NotifyClientOfDocumentPublished
{
    public function handle(Document $document): int
    {
        $fresh = $this->stillReleasedToPortal($document);

        if ($fresh === null) {
            return 0;
        }

        $matter = Matter::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->whereKey($fresh->matter_id)
            ->open()
            ->first(['id', 'client_id']);

        if ($matter === null) {
            return 0;
        }

        $recipients = app(ResolveClientRecipients::class)->recipientsFor($matter->client_id);

        if ($recipients->isEmpty()) {
            return 0;
        }

        $sent = 0;
        $failure = null;

        foreach ($recipients as $recipient) {
            if ($this->alreadyDelivered($fresh, $recipient)) {
                $sent++;

                continue;
            }

            try {
                Mail::to($recipient->email)->send(new DocumentPublishedMail($fresh, $recipient));
                $sent++;
            } catch (Throwable $e) {
                $failure ??= $e;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }

        return $sent;
    }

    /**
     * Đọc lại TƯƠI từ CSDL rằng tài liệu còn đúng nghĩa "đã ra tới khách" — xem docblock lớp.
     * `withoutGlobalScope(ClientPortalScope::class)`: cùng lý do `NotifyClientOfStageUpdate::
     * stillReleasedToPortal()`, một job hàng đợi có thể chạy trong tiến trình còn treo ngữ cảnh
     * portal.
     */
    private function stillReleasedToPortal(Document $document): ?Document
    {
        $fresh = Document::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->withTrashed()
            ->find($document->getKey());

        if ($fresh === null || ! $fresh->isReleasedToPortal()) {
            return null;
        }

        return $fresh;
    }

    /**
     * Cùng hình dạng `NotifyClientOfStageUpdate::alreadyDelivered()` — không cần khoá bổ sung
     * (xem docblock lớp).
     */
    private function alreadyDelivered(Document $document, ClientUser $recipient): bool
    {
        return OutboundMessage::query()
            ->withoutGlobalScopes()
            ->where('related_type', $document->getMorphClass())
            ->where('related_id', $document->getKey())
            ->where('recipient', $recipient->email)
            ->where('status', OutboundStatus::Sent)
            ->exists();
    }

    /**
     * Cùng lý lẽ `NotifyClientOfStageUpdate::reportFailure()`: listener hết `$tries`, báo luật sư
     * phụ trách trong hệ thống qua `ResolveStaffRecipients` (R3). KHÁC ở loại thông báo dùng: xem
     * docblock `App\Notifications\Staff\DocumentPublishedMailFailedAlert` — một Action không được
     * dùng `Filament\Notifications\Notification` (sổ tay lane m6 nhắc thẳng đừng chép lối
     * `NotifyClientOfStageUpdate` đang có sang Action mới).
     */
    public function reportFailure(Document $document): void
    {
        $fresh = Document::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->withTrashed()
            ->find($document->getKey());

        if ($fresh === null) {
            return;
        }

        $matter = Matter::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->withTrashed()
            ->find($fresh->matter_id);

        if ($matter === null) {
            return;
        }

        $recipients = app(ResolveStaffRecipients::class)->handle($matter, [$matter->leadLawyer]);

        foreach ($recipients as $recipient) {
            $recipient->notify(new DocumentPublishedMailFailedAlert($matter));
        }
    }
}
