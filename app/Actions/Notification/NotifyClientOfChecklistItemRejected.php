<?php

namespace App\Actions\Notification;

use App\Enums\ChecklistItemStatus;
use App\Enums\OutboundStatus;
use App\Mail\Client\DocumentRejected;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\OutboundMessage;
use App\Notifications\Staff\ChecklistItemRejectedMailFailedAlert;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * SPEC §9 mẫu `client.document_rejected`. Lấp `notify/notify-6`/`checklist/checklist-01`/`e2e/F6`
 * (audit 2026-09-24): `App\Events\ChecklistItemRejected` bắn ở `ReviewChecklistItem::handle()`
 * (nhánh từ chối) từ lâu mà chưa từng có listener — khách bị từ chối giấy tờ không được báo, dù
 * màn hình admin nói với luật sư "Đã gửi yêu cầu nộp lại kèm lý do cho khách." (câu đó SAI cho tới
 * hôm nay — xem `lang/vi/checklist.php`, sửa cùng commit với listener này).
 *
 * Cùng khuôn `NotifyClientOfDocumentPublished`/`NotifyClientOfStageUpdate`, với MỘT khác biệt:
 * **hai lần từ chối khác nhau của CÙNG một đầu mục là hai thư** (khách nộp lại, bị từ chối lần
 * nữa) — khoá chống trùng vì vậy mang thêm LẦN TỪ CHỐI (`reviewed_at`,
 * {@see DocumentRejected::ledgerKeyFor()}), cùng lối bậc@ngày của M6.5 Task 14,
 * không chỉ `related` + `recipient` như tài liệu công bố.
 *
 * **Kiểm tra lại lúc gửi:** đầu mục còn ĐÚNG lần từ chối này — `status` vẫn `rejected` VÀ
 * `reviewed_at` vẫn khớp ảnh chụp lúc sự kiện bắn (khách có thể đã nộp lại, đổi `status` về
 * `pending_review` VÀ đổi `reviewed_at`, trong cửa sổ hàng đợi) — cùng ý brief: "đầu mục còn
 * rejected và chưa bị nộp lại".
 */
class NotifyClientOfChecklistItemRejected
{
    public function handle(MatterChecklistItem $checklistItem): int
    {
        $fresh = $this->stillRejected($checklistItem);

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
                Mail::to($recipient->email)->send(new DocumentRejected($fresh, $recipient));
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
     * Đọc lại TƯƠI rằng đầu mục còn `rejected` VÀ vẫn ĐÚNG lần từ chối mà sự kiện đã bắn ra —
     * so `reviewed_at` bằng GIÂY (`equalTo`, không `is()` — hai đối tượng `Carbon` khác instance
     * cùng thời điểm vẫn phải khớp), vì `ReviewChecklistItem::handle()` ghi lại `reviewed_at` MỚI
     * ở mỗi lần từ chối, kể cả lần TIẾP THEO của cùng đầu mục sau khi khách nộp lại.
     */
    private function stillRejected(MatterChecklistItem $checklistItem): ?MatterChecklistItem
    {
        $fresh = MatterChecklistItem::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->find($checklistItem->getKey());

        if ($fresh === null || $fresh->status !== ChecklistItemStatus::Rejected) {
            return null;
        }

        if ($fresh->reviewed_at === null || $checklistItem->reviewed_at === null) {
            return null;
        }

        if (! $fresh->reviewed_at->equalTo($checklistItem->reviewed_at)) {
            return null;
        }

        return $fresh;
    }

    private function alreadyDelivered(MatterChecklistItem $checklistItem, ClientUser $recipient): bool
    {
        return OutboundMessage::query()
            ->withoutGlobalScopes()
            ->where('related_type', $checklistItem->getMorphClass())
            ->where('related_id', $checklistItem->getKey())
            ->where('recipient', $recipient->email)
            ->where('status', OutboundStatus::Sent)
            ->where('payload->tier', DocumentRejected::ledgerKeyFor($checklistItem))
            ->exists();
    }

    /**
     * Cùng lý lẽ `NotifyClientOfDocumentPublished::reportFailure()`: một
     * `Illuminate\Notifications\Notification` thường (`ChecklistItemRejectedMailFailedAlert`), vì
     * nơi gọi là một Action — không `Filament\Notifications\Notification` (sổ tay lane m6 nhắc
     * thẳng đừng chép lối `NotifyClientOfStageUpdate` đang có sang Action mới).
     */
    public function reportFailure(MatterChecklistItem $checklistItem): void
    {
        $fresh = MatterChecklistItem::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->find($checklistItem->getKey());

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
            $recipient->notify(new ChecklistItemRejectedMailFailedAlert($matter));
        }
    }
}
