<?php

namespace App\Actions\Notification;

use App\Enums\OutboundStatus;
use App\Mail\Client\DocumentPublished as DocumentPublishedMail;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\OutboundMessage;
use App\Notifications\Staff\DocumentPublishedMailFailedAlert;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Support\Collection;
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
 *    `published`, còn ngoài nhóm D, chưa xoá mềm) VÀ vụ việc còn mở (trừ gói bàn giao — mục cuối
 *    của docblock này), VÀ `matters.is_published_to_portal` (cùng cách
 *    `App\Jobs\SendDeadlineReminderMail` hỏi `Matter::open()` — brief Task 3 chỉ đích danh câu
 *    này) — một tài liệu có thể đã bị chuyển sang nhóm D hoặc gỡ cổng, và vụ việc có thể đã bị
 *    huỷ, đóng, hoặc tắt công tắc portal, trong cửa sổ hàng đợi giữa lúc sự kiện bắn và lúc
 *    listener chạy.
 *
 * **Fix round 1 (finding Critical 1).** Bản trước KHÔNG hỏi `matters.is_published_to_portal` ở
 * đâu trong đường đi này — không ở đây, không ở `Document::isReleasedToPortal()` (chỉ soi các cột
 * của chính `Document`), không ở `ResolveClientRecipients` (chỉ soi `ClientUser`), và
 * `PublishDocument` không tự guard theo cờ đó (nó không CẦN — cờ này là chuyện của PORTAL, không
 * phải chuyện công bố tài liệu). SPEC §4 gọi cột này là "Công tắc tổng. Tắt thì vụ việc vô hình
 * trên portal dù khách đúng quyền" — mặc định `false`. Thiếu điều kiện này, một tài liệu nhóm B/C
 * được công bố trên một vụ việc còn tắt công tắc portal vẫn gửi thư kèm tên tài liệu, dù
 * `$account->can('view', $matter)` là `false` và portal không hiện gì cả — thư bỏ qua công tắc
 * tổng và mang đúng nội dung mà ranh giới portal đang giấu (R6: "chỉ chứa nội dung đã công bố").
 * Sửa: nạp `Matter` với `->where('is_published_to_portal', true)` ngay cạnh `->open()`, cùng cách
 * `NotifyClientOfStageUpdate::stillReleasedToPortal()` đã làm cho `client.stage_update`.
 *
 * **Gộp M7 vào `main` (PROGRESS "Ghi chú M7", Task 11): vụ còn trên cổng của CHÍNH người nhận.**
 * Ngoài hai cổng trên (vụ còn mở, cờ `is_published_to_portal`), mỗi người nhận R12 còn phải qua
 * {@see ResolveClientRecipients::onPortal()} (`MatterPolicy::view` nhánh khách — gồm "chưa hết hạn
 * tra cứu" của M7 Task 5), để thư không bao giờ nói khác điều cổng đang cho chính khách đó thấy.
 *
 * **Gói bàn giao hồ sơ — ngoại lệ DUY NHẤT của "vụ còn mở" (việc sau gộp M7, làn fu2, phán quyết
 * controller (b)).** Gói bàn giao là tài liệu nhóm B chỉ bao giờ được công bố trên một vụ ĐÃ kết thúc
 * (SPEC §6.12 bước 4, M7 Task 4). Đòi vụ còn mở như M6 Task 3 nghĩa là đúng tài liệu mà cả mục đích
 * là tới tay khách không bao giờ được báo, trong khi cổng vẫn cho khách thấy nó tới hết
 * `client_access_until`. Vì vậy vụ đã kết thúc chỉ cho qua khi tài liệu là gói HIỆN TẠI của vụ
 * ({@see MatterArchive::whereCurrentHandoverPackageIs()}, `handover_document_id` trỏ đúng nó); hạn
 * tra cứu do `onPortal()` quyết (công bố cổng, khách chưa xoá mềm, `client_access_until`), không do
 * một luật thứ hai ở đây. Tài liệu thường trên vụ đã kết thúc giữ hành vi M6: không gửi.
 */
class NotifyClientOfDocumentPublished
{
    public function handle(Document $document): int
    {
        $fresh = $this->stillReleasedToPortal($document);

        if ($fresh === null) {
            return 0;
        }

        $recipients = $this->recipientsForReleased($fresh);

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
     * Những tài khoản ĐỦ ĐIỀU KIỆN nhận thư về tài liệu này NGAY BÂY GIỜ — mọi cổng kiểm tra lúc
     * gửi của {@see self::handle()} gộp lại (tài liệu còn ra tới khách, vụ việc còn mở — hoặc tài
     * liệu là gói bàn giao hiện tại — và còn công bố portal, tài khoản R12 mà vụ còn trên cổng của
     * họ). Rỗng là "không gửi cho ai". Public để nút "Gửi lại" của nhật ký thư
     * ({@see ResendOutboundMessage}) hỏi đúng câu này thay vì viết luật thứ hai.
     *
     * @return Collection<int, ClientUser>
     */
    public function eligibleRecipients(Document $document): Collection
    {
        $fresh = $this->stillReleasedToPortal($document);

        return $fresh === null ? collect() : $this->recipientsForReleased($fresh);
    }

    /**
     * Phần sau cổng "tài liệu còn ra tới khách": vụ việc còn công bố portal (fix round 1, Critical
     * 1) và chưa huỷ (`SoftDeletingScope` mặc định của `Matter::query()`), còn mở — trừ khi tài
     * liệu là gói bàn giao hiện tại của vụ (làn fu2, xem docblock lớp) — rồi tài khoản R12 mà vụ
     * còn trên cổng của chính họ (gộp M7). Bản ghi vụ ĐẦY ĐỦ (không chọn vài cột), vì `onPortal()`
     * hỏi `MatterPolicy::view` trên nó.
     *
     * @return Collection<int, ClientUser>
     */
    private function recipientsForReleased(Document $fresh): Collection
    {
        $matter = Matter::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->whereKey($fresh->matter_id)
            ->where('is_published_to_portal', true)
            ->first();

        if ($matter === null) {
            return collect();
        }

        if (! $matter->isOpen() && MatterArchive::whereCurrentHandoverPackageIs($fresh) === null) {
            return collect();
        }

        $recipients = app(ResolveClientRecipients::class);

        return $recipients->onPortal($matter, $recipients->recipientsFor($matter->client_id));
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
     * (xem docblock lớp) — CỘNG điều kiện mẫu (việc sau gộp M9 + M10, làn fu3, Task 1 mục B):
     * `Document` không chỉ là `related` của mẫu này. `staff.handover_ready` gắn vào chính gói bàn
     * giao, nên một địa chỉ vừa là của nhân sự vừa là tài khoản cổng của khách đã có một dòng `sent`
     * về đúng tài liệu ấy TRƯỚC khi gói được công bố; thiếu điều kiện mẫu, thư báo khách bị bỏ qua
     * im lặng như "đã gửi". Một mẫu sau này gắn vào tài liệu (thư rút công bố chẳng hạn) cũng vậy.
     * Nút "Gửi lại" ({@see ResendTargets}) hỏi cùng hàm này nên đọc cùng định nghĩa.
     */
    public function alreadyDelivered(Document $document, ClientUser $recipient): bool
    {
        return OutboundMessage::query()
            ->withoutGlobalScopes()
            ->where('related_type', $document->getMorphClass())
            ->where('related_id', $document->getKey())
            ->where('template', DocumentPublishedMail::TEMPLATE)
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
