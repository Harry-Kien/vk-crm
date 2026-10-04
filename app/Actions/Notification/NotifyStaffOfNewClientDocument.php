<?php

namespace App\Actions\Notification;

use App\Actions\Push\SendPushAlert;
use App\Enums\OutboundStatus;
use App\Enums\PushTopic;
use App\Enums\Role;
use App\Mail\Staff\NewClientDocument as NewClientDocumentMail;
use App\Models\Document;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Notifications\Staff\NewClientDocumentAlert;
use App\Notifications\Staff\NewClientDocumentMailFailedAlert;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * SPEC §9 mẫu `staff.new_client_document` — khách vừa nộp tài liệu qua cổng
 * (`App\Actions\Document\SubmitClientDocument`), kích hoạt bởi `App\Events\ClientDocumentSubmitted`.
 * Đọc THẲNG collection tài liệu của sự kiện (M6.5 Task 17 ruling: MỘT sự kiện cho MỘT LẦN NỘP,
 * mang toàn bộ tài liệu của lô), không tự truy vấn lại danh sách và không tự bắn sự kiện mới.
 *
 * Cùng khuôn {@see NotifyStaffOfNewClientRequest} — đọc docblock lớp đó cho lý lẽ đầy đủ về
 * tách thông báo/thư và về `$preferred`. **Khác ở một chỗ: không có khái niệm "assigned_to" cho
 * một lần nộp tài liệu** (`Document`/`MatterChecklistItem` không có cột người phụ trách nào —
 * SPEC không định nghĩa "ai giữ việc kiểm tra một đầu mục"), nên `$preferred` ở đây chỉ có luật
 * sư phụ trách và các trợ lý trong đội ngũ, không có phần tử đầu.
 *
 * Không hỏi `is_published_to_portal` — cùng lý do {@see NotifyStaffOfNewClientRequest}: đây là
 * thư nội bộ, không phải thư cho khách.
 *
 * **M12 — thông báo đẩy `staff.new_client_document`:** cùng luật với lớp anh em (docblock
 * {@see NotifyStaffOfNewClientRequest}, mục "M12"): {@see SendPushAlert} nhận đúng những người lượt
 * này vừa gửi thư được, sau vòng thư, trước lần ném lại lỗi; chống trùng là sổ thư
 * (`alreadyDelivered()`). Bản ghi đi kèm là tài liệu đại diện ĐÃ đọc lại (`$fresh`) — đúng bản ghi
 * thư ghi vào nhật ký. Chạm vào mở tab "Danh mục hồ sơ" của vụ, nơi nhân sự duyệt giấy tờ khách nộp
 * (phán quyết (f), `PushTopic`).
 */
class NotifyStaffOfNewClientDocument
{
    public function handle(Collection $documents): int
    {
        if ($documents->isEmpty()) {
            return 0;
        }

        $fresh = $this->stillExists($documents->first());

        if ($fresh === null) {
            return 0;
        }

        $count = $this->freshCount($documents);
        $recipients = $this->recipientsForExisting($fresh);

        foreach ($recipients as $recipient) {
            if (! $this->alreadyAlerted($recipient, $fresh)) {
                $recipient->notify(new NewClientDocumentAlert($fresh, $count));
            }
        }

        $sent = 0;
        $failure = null;
        $mailed = collect();

        foreach ($recipients as $recipient) {
            if ($this->alreadyDelivered($fresh, $recipient)) {
                $sent++;

                continue;
            }

            try {
                Mail::to($recipient->email)->send(new NewClientDocumentMail($fresh, $count, $recipient));
                $sent++;
                $mailed->push($recipient);
            } catch (Throwable $e) {
                $failure ??= $e;
            }
        }

        // M12: push cho đúng những người lượt này vừa gửi thư được — xem docblock lớp.
        app(SendPushAlert::class)->handle($mailed, PushTopic::StaffNewClientDocument, $fresh);

        if ($failure !== null) {
            throw $failure;
        }

        return $sent;
    }

    /**
     * Những nhân sự ĐỦ ĐIỀU KIỆN nhận thư về lô tệp có `$representative` là tệp đại diện NGAY BÂY
     * GIỜ — mọi cổng kiểm tra lúc gửi của {@see self::handle()} gộp lại (tệp và vụ việc còn tồn
     * tại, rồi {@see ResolveStaffRecipients::handle()} với danh sách ưu tiên của
     * {@see self::preferred()}: R3, còn đi làm và xem được vụ). Public để nút "Gửi lại" của nhật
     * ký thư ({@see ResendOutboundMessage}) hỏi đúng câu này thay vì viết luật thứ hai.
     *
     * @return SupportCollection<int, User>
     */
    public function eligibleRecipients(Document $representative): SupportCollection
    {
        $fresh = $this->stillExists($representative);

        return $fresh === null ? collect() : $this->recipientsForExisting($fresh);
    }

    /**
     * Phần sau cổng "tệp đại diện còn tồn tại": vụ việc còn (kể cả đã đóng — xem
     * {@see self::existingMatterFor()}), rồi người nhận R3.
     *
     * @return SupportCollection<int, User>
     */
    private function recipientsForExisting(Document $fresh): SupportCollection
    {
        $matter = $this->existingMatterFor($fresh);

        if ($matter === null) {
            return collect();
        }

        return app(ResolveStaffRecipients::class)->handle($matter, $this->preferred($matter));
    }

    /**
     * "Trợ lý trong đội ngũ" = vai trò NHÂN SỰ (`App\Enums\Role::Assistant`) — cùng cách đọc
     * `App\Actions\Schedule\CheckDeadlines::recipientsFor()` đã dùng, xem docblock
     * `NotifyStaffOfNewClientRequest::preferred()`.
     *
     * @return array<int, User|null>
     */
    private function preferred(Matter $matter): array
    {
        return [
            $matter->leadLawyer,
            ...$matter->team()->get()->filter(fn (User $u): bool => $u->hasRole(Role::Assistant->value))->all(),
        ];
    }

    /**
     * Tài liệu ĐẠI DIỆN của lô, đọc lại TƯƠI — `withoutGlobalScope(ClientPortalScope::class)`
     * cùng lý do mọi Notify* khác. KHÔNG `withTrashed()` ở đây: một tài liệu vừa nộp bị xoá mềm
     * trước khi job chạy (hiếm, nhưng có thể — văn phòng gỡ nhầm) không còn gì để báo tin.
     */
    private function stillExists(Document $document): ?Document
    {
        return Document::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->find($document->getKey());
    }

    /**
     * Chỉ loại vụ đã XOÁ MỀM (huỷ) — mặc định của `Matter::query()`. KHÔNG `->open()`: khách nộp được
     * tệp vào vụ ĐÃ ĐÓNG còn công bố trên cổng (`DocumentPolicy::create`), nên văn phòng vẫn phải nhận báo
     * (vòng sửa 1). Thư nội bộ không phải ranh giới cổng.
     */
    private function existingMatterFor(Document $document): ?Matter
    {
        return Matter::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->whereKey($document->matter_id)
            ->first(['id', 'client_id', 'code', 'title', 'lead_lawyer_id', 'confidentiality']);
    }

    /**
     * Số tệp thật của lô lúc GỬI, không lúc sự kiện bắn: một trong số các tệp CÓ THỂ đã bị xoá
     * mềm giữa hai thời điểm đó. Đếm lại BÊN TRONG chính `$documents` của sự kiện
     * (`whereKey($documents->modelKeys())`), KHÔNG truy vấn lại theo `matter_checklist_item_id` +
     * `version` (vòng sửa 1, finding Important 1): một câu truy vấn theo cặp đó không có điều
     * kiện lọc nhóm, nên đếm luôn CẢ tài liệu nội bộ (nhóm D, văn phòng tự gắn, không phải khách
     * nộp) và tài liệu của MỘT LẦN NỘP KHÁC (R10: một lần nộp bổ sung tái dùng cùng version, nên
     * hai sự kiện `ClientDocumentSubmitted` liên tiếp trên cùng đầu mục có cùng version nhưng
     * khác `$documents`) gắn cùng đầu mục/version — vi phạm đúng ruling "đọc thẳng collection
     * này, không tự truy vấn lại". Đếm theo khoá chính của `$documents` giữ nguyên phạm vi ĐÚNG
     * MỘT LẦN NỘP mà sự kiện mang theo, và vẫn loại tệp bị xoá mềm SAU khi sự kiện bắn (mặc định
     * `Document::query()` không thấy hàng đã xoá mềm).
     */
    private function freshCount(Collection $documents): int
    {
        return Document::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->whereKey($documents->modelKeys())
            ->count();
    }

    private function alreadyAlerted(User $recipient, Document $representative): bool
    {
        return $recipient->notifications()
            ->where('type', NewClientDocumentAlert::class)
            ->where('data->viewData->document_id', $representative->getKey())
            ->exists();
    }

    public function alreadyDelivered(Document $representative, User $recipient): bool
    {
        return OutboundMessage::query()
            ->withoutGlobalScopes()
            ->where('related_type', $representative->getMorphClass())
            ->where('related_id', $representative->getKey())
            ->where('recipient', $recipient->email)
            ->where('status', OutboundStatus::Sent)
            ->exists();
    }

    public function reportFailure(Collection $documents): void
    {
        if ($documents->isEmpty()) {
            return;
        }

        $fresh = Document::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->withTrashed()
            ->find($documents->first()->getKey());

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
            $recipient->notify(new NewClientDocumentMailFailedAlert($matter));
        }
    }
}
