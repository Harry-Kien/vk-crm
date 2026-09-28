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
 * rejected và chưa bị nộp lại". VÀ `matters.is_published_to_portal` (fix round 1, finding
 * Critical 1) — xem docblock lớp anh em `NotifyClientOfDocumentPublished`: cờ này là công tắc
 * tổng của portal, tắt thì vụ việc vô hình dù khách đúng quyền, và không nơi nào khác trên đường
 * đi (`Matter::open()`, `ResolveClientRecipients`, `ReviewChecklistItem`) tự hỏi nó.
 */
class NotifyClientOfChecklistItemRejected
{
    public function handle(MatterChecklistItem $checklistItem): int
    {
        $fresh = $this->stillRejected($checklistItem);

        if ($fresh === null) {
            return 0;
        }

        $matter = $this->notifiableMatter($fresh->matter_id);

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

    /**
     * Vụ việc của đầu mục, CHỈ KHI thư về nó còn được phép đi — `null` nếu không. MỘT định nghĩa
     * cho cả `handle()` (lúc gửi) lẫn {@see self::hasEligibleRecipient()} (lúc màn hình chọn câu):
     *
     *  - `open()`: chưa đóng (`closed_at` null) VÀ chưa xoá mềm — cùng cách
     *    `SendDeadlineReminderMail` hỏi (brief Task 3, "kiểm tra lại lúc gửi").
     *  - `is_published_to_portal` (fix round 1, Critical 1): công tắc tổng của portal — xem docblock
     *    lớp.
     *
     * Fix round 2 (finding 1): điều kiện này từng được viết HAI lần — trong `handle()`, và (thiếu
     * `open()`) trong `hasEligibleRecipient()` — nên toast hứa email cho một lần từ chối trên vụ đã
     * đóng mà `handle()` không bao giờ gửi. Tách ra đây để hai nơi không thể lệch nhau nữa.
     */
    private function notifiableMatter(int $matterId): ?Matter
    {
        return Matter::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->whereKey($matterId)
            ->open()
            ->where('is_published_to_portal', true)
            ->first(['id', 'client_id']);
    }

    /**
     * Fix round 1 (finding Important 2, `lang/vi/checklist.php`): "một email sẽ được gửi báo
     * khách" là một lời hứa CÓ ĐIỀU KIỆN. `ChecklistRelationManager::rejectionNoticeCopy()` gọi hàm
     * này để chọn câu toast/helper text TRUNG THỰC — cùng cách
     * `NotifyClientOfStageUpdate::hasEligibleRecipient()` làm cho form chuyển giai đoạn.
     *
     * Fix round 2 (finding 1): dùng LẠI đúng hai bước chọn người nhận của `handle()` —
     * {@see self::notifiableMatter()} (vụ còn mở, còn bật công bố portal) rồi
     * {@see ResolveClientRecipients::hasEligibleRecipient()} (R12) — không chép điều kiện nào. Bản
     * fix round 1 chép tay `is_published_to_portal` và bỏ sót `open()`, nên từ chối trên vụ đã
     * đóng (vẫn duyệt được: `MatterChecklistItemPolicy::review` không hỏi `closed_at`) hứa một
     * email không bao giờ đi. Hai bước của `handle()` KHÔNG có ở đây —
     * {@see self::stillRejected()} (khách nộp lại trong cửa sổ hàng đợi) và
     * {@see self::alreadyDelivered()} (hàng đợi thử lại, không gửi hai lần) — canh việc gửi MUỘN và
     * gửi LẠI, không phải câu "lần từ chối này có ai nhận thư không" mà màn hình hỏi.
     */
    public function hasEligibleRecipient(MatterChecklistItem $checklistItem): bool
    {
        $matter = $this->notifiableMatter($checklistItem->matter_id);

        return $matter !== null
            && app(ResolveClientRecipients::class)->hasEligibleRecipient($matter->client_id);
    }

    /**
     * Fix round 2 (finding 2): "lý do từ chối có HIỆN cho khách trên cổng khách hàng không" — câu
     * hỏi thứ HAI của câu báo sau khi từ chối, khi {@see self::hasEligibleRecipient()} đã nói không
     * có thư. Hai câu trả lời khác nhau đòi hai câu chữ khác nhau: vụ đã đóng hay khách chưa kích
     * hoạt tài khoản thì lý do VẪN chờ trên cổng; vụ tắt công bố portal hay khách hàng đã xoá thì
     * cổng giấu cả vụ lẫn lý do, và luật sư phải tự gọi khách.
     *
     * Hỏi CHÍNH định nghĩa của cổng, không chép lại nó: chạy truy vấn đầu mục dưới
     * `ClientPortalScope::actingAs()` như thể một tài khoản của CHÍNH khách hàng sở hữu vụ đang mở
     * cổng — `MatterChecklistItem::applyClientPortalConstraints()` (chưa xoá mềm, `whereHas
     * ('matter')`) kéo theo `Matter::applyClientPortalConstraints()` (đúng khách, bật công bố
     * portal, chưa xoá mềm, khách hàng chưa xoá mềm, và mọi điều kiện sau này — M7 thêm
     * `client_access_until` ở đó). Tài khoản dùng để hỏi là một `ClientUser` KHÔNG lưu, chỉ mang
     * `client_id`: điều kiện của cổng chỉ đọc đúng cột đó, và câu hỏi là "khách hàng này có thấy
     * không" — kể cả khi họ chưa có tài khoản nào (khi đó lý do chờ sẵn cho tài khoản đầu tiên).
     * `client_id` đọc bằng `withTrashed()`, không có nhánh "vụ đã xoá mềm" riêng ở đây: câu trả lời
     * cho vụ đó cũng để chính cổng đưa ra (`whereNull('deleted_at')` của nó).
     */
    public function isShownOnPortal(MatterChecklistItem $checklistItem): bool
    {
        $ownClient = (new ClientUser)->forceFill([
            'client_id' => Matter::query()
                ->withoutGlobalScope(ClientPortalScope::class)
                ->withTrashed()
                ->whereKey($checklistItem->matter_id)
                ->value('client_id'),
        ]);

        return ClientPortalScope::actingAs(
            $ownClient,
            fn (): bool => MatterChecklistItem::query()->whereKey($checklistItem->getKey())->exists(),
        );
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
