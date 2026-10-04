<?php

namespace App\Actions\Notification;

use App\Actions\Push\SendPushAlert;
use App\Enums\OutboundStatus;
use App\Enums\PushTopic;
use App\Mail\Client\RequestAnswered as RequestAnsweredMail;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Notifications\Staff\RequestAnsweredMailFailedAlert;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * SPEC §9 mẫu `client.request_answered` (đính chính 2026-09-27, `requests/REQ-4`) — một câu trả
 * lời của văn phòng vừa đưa một luồng vào `answered` LẦN ĐẦU. Dispatch bởi
 * `App\Events\ClientRequestAnswered` (`App\Actions\Portal\ReplyToClientRequest::advanceStatus()`,
 * nhánh nhân sự, đúng lúc trạng thái CHUYỂN — xem docblock ở đó) và gửi qua
 * `App\Listeners\SendClientRequestAnsweredNotification`.
 *
 * Cùng khuôn {@see NotifyClientOfDocumentPublished} — đọc docblock lớp đó cho lý lẽ đầy đủ.
 *
 * **Kiểm tra lại lúc gửi (phán quyết controller): luồng còn tồn tại (chưa xoá mềm — tự động qua
 * `SoftDeletingScope` mặc định của `ClientRequest::query()`), vụ việc chưa xoá mềm, vụ việc còn
 * `is_published_to_portal`.** KHÔNG hỏi `Matter::open()` (khác `NotifyClientOfDocumentPublished`):
 * một vụ việc đã ĐÓNG (nhưng chưa huỷ) vẫn hiện trên cổng — đóng một vụ việc không rút nó khỏi
 * cổng, chỉ dòng tiến độ/tài liệu MỚI mới ngừng phát sinh — và một câu trả lời đã viết ra vẫn là
 * một sự thật khách cần biết dù hồ sơ vừa khép lại.
 *
 * **Không kiểm tra lại trạng thái luồng còn `answered` hay không.** Thư này báo tin về một SỰ
 * KIỆN đã xảy ra ("văn phòng vừa trả lời"), không phải về trạng thái HIỆN TẠI của luồng — một
 * luồng có thể đã chuyển tiếp sang `in_progress` (khách hỏi thêm) hay `closed` (văn phòng đóng)
 * giữa lúc sự kiện bắn và lúc job chạy, và cả hai đều không làm cho việc "văn phòng đã trả lời"
 * trở thành chưa từng xảy ra.
 *
 * **M12 — thông báo đẩy `client.request_answered`:** cùng luật với `NotifyClientOfStageUpdate`
 * (docblock lớp đó, mục "M12 — thông báo đẩy"): {@see SendPushAlert} nhận đúng những tài khoản lượt
 * này vừa gửi thư thành công, sau vòng thư, trước lần ném lại lỗi; chống trùng là sổ thư
 * (`alreadyDelivered()`). Bản ghi đi kèm là câu trả lời đã đọc lại (`$freshReply`) — đúng bản ghi thư
 * ghi vào nhật ký; push không mang nội dung câu hỏi hay câu trả lời (R11, `PushTopic`).
 */
class NotifyClientOfRequestAnswered
{
    public function handle(ClientRequestReply $reply): int
    {
        $freshReply = $this->stillExists($reply);

        if ($freshReply === null) {
            return 0;
        }

        $recipients = $this->recipientsForExisting($freshReply);

        if ($recipients->isEmpty()) {
            return 0;
        }

        $sent = 0;
        $failure = null;
        $mailed = collect();

        foreach ($recipients as $recipient) {
            if ($this->alreadyDelivered($freshReply, $recipient)) {
                $sent++;

                continue;
            }

            try {
                Mail::to($recipient->email)->send(new RequestAnsweredMail($freshReply, $recipient));
                $sent++;
                $mailed->push($recipient);
            } catch (Throwable $e) {
                $failure ??= $e;
            }
        }

        // M12: push cho đúng những người lượt này vừa gửi thư được — xem docblock lớp.
        app(SendPushAlert::class)->handle($mailed, PushTopic::ClientRequestAnswered, $freshReply);

        if ($failure !== null) {
            throw $failure;
        }

        return $sent;
    }

    private function stillExists(ClientRequestReply $reply): ?ClientRequestReply
    {
        return ClientRequestReply::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->find($reply->getKey());
    }

    /** `ClientRequest::query()` đã áp `SoftDeletingScope` mặc định — "chưa xoá mềm" tự động. */
    private function stillExistingThread(ClientRequestReply $reply): ?ClientRequest
    {
        return ClientRequest::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->find($reply->request_id);
    }

    private function publishedMatterFor(ClientRequest $thread): ?Matter
    {
        return Matter::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->whereKey($thread->matter_id)
            ->where('is_published_to_portal', true)
            ->first(['id', 'client_id']);
    }

    /**
     * Những tài khoản ĐỦ ĐIỀU KIỆN nhận thư về câu trả lời này NGAY BÂY GIỜ — mọi cổng kiểm tra
     * lúc gửi của {@see self::handle()} gộp lại (câu trả lời và cuộc trao đổi còn tồn tại, vụ việc
     * còn công bố portal, tài khoản R12). Rỗng là "không gửi cho ai". Public để nút "Gửi lại" của
     * nhật ký thư ({@see ResendOutboundMessage}) hỏi đúng câu này thay vì viết luật thứ hai.
     *
     * @return Collection<int, ClientUser>
     */
    public function eligibleRecipients(ClientRequestReply $reply): Collection
    {
        $fresh = $this->stillExists($reply);

        return $fresh === null ? collect() : $this->recipientsForExisting($fresh);
    }

    /**
     * Phần sau cổng "câu trả lời còn tồn tại": cuộc trao đổi còn, vụ việc còn công bố portal, rồi
     * tài khoản R12.
     *
     * @return Collection<int, ClientUser>
     */
    private function recipientsForExisting(ClientRequestReply $freshReply): Collection
    {
        $thread = $this->stillExistingThread($freshReply);

        if ($thread === null) {
            return collect();
        }

        $matter = $this->publishedMatterFor($thread);

        if ($matter === null) {
            return collect();
        }

        return app(ResolveClientRecipients::class)->recipientsFor($matter->client_id);
    }

    public function alreadyDelivered(ClientRequestReply $reply, ClientUser $recipient): bool
    {
        return OutboundMessage::query()
            ->withoutGlobalScopes()
            ->where('related_type', $reply->getMorphClass())
            ->where('related_id', $reply->getKey())
            ->where('recipient', $recipient->email)
            ->where('status', OutboundStatus::Sent)
            ->exists();
    }

    /**
     * Listener hết `$tries`: báo luật sư phụ trách vụ việc trong hệ thống — cùng lý lẽ
     * `NotifyClientOfDocumentPublished::reportFailure()`.
     */
    public function reportFailure(ClientRequestReply $reply): void
    {
        $fresh = ClientRequestReply::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->find($reply->getKey());

        $thread = $fresh !== null
            ? ClientRequest::query()->withoutGlobalScope(ClientPortalScope::class)->withTrashed()->find($fresh->request_id)
            : null;

        if ($thread === null) {
            return;
        }

        $matter = Matter::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->withTrashed()
            ->find($thread->matter_id);

        if ($matter === null) {
            return;
        }

        $recipients = app(ResolveStaffRecipients::class)->handle($matter, [$matter->leadLawyer]);

        foreach ($recipients as $recipient) {
            $recipient->notify(new RequestAnsweredMailFailedAlert($matter));
        }
    }
}
