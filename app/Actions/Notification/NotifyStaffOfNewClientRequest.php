<?php

namespace App\Actions\Notification;

use App\Actions\Schedule\CheckDeadlines;
use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Mail\Staff\NewClientRequest as NewClientRequestMail;
use App\Models\ClientRequest;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Notifications\Staff\NewClientRequestAlert;
use App\Notifications\Staff\NewClientRequestMailFailedAlert;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * SPEC §9 mẫu `staff.new_client_request` — lấp `requests/REQ-1`/`notify/notify-7`/
 * `roles/roles-08`/`spec-gap/spec-gap-08`/`e2e/F5` (audit 2026-09-24): khách gửi một yêu cầu qua
 * cổng (`App\Actions\Portal\OpenClientRequest`) mà không ai trong văn phòng được báo bằng bất kỳ
 * cách nào, trong khi cổng khách vẫn nói "Văn phòng đã nhận được yêu cầu của anh/chị."
 *
 * **Người nhận = {@see ResolveStaffRecipients} với `$preferred = [assigned_to, luật sư phụ
 * trách, các thành viên đội ngũ vai trợ lý]`** (phán quyết controller, task-4-brief.md, đọc theo
 * SPEC §6.6 bước 9 "lead lawyer và trợ lý"). `assigned_to` gần như luôn `null` lúc một yêu cầu vừa
 * mở (`OpenClientRequest` luôn đặt `assigned_to = null`), nhưng listener chạy trên hàng đợi — nếu
 * văn phòng đã nhận việc (`TriageClientRequest::assign()`) NGAY trước khi job này tới lượt, người
 * đó vẫn nên có mặt; `ResolveStaffRecipients` bỏ qua `null` lặng lẽ nên không cần một nhánh riêng
 * cho trường hợp còn `null`.
 *
 * **Thông báo trong hệ thống VÀ thư, tách rời nhau (M6.5 R2, "tách hai việc để lỗi transport
 * không nuốt thông báo").** Thông báo ghi trước, độc lập với vòng gửi thư — một thư hỏng không
 * được kéo theo mất luôn dấu hiệu trên chuông thông báo. Cả hai đều tự chống trùng: thông báo qua
 * bảng `notifications` (cùng thiết bị {@see CheckDeadlines::alreadyAlerted()}),
 * thư qua nhật ký `outbound_messages` (R3) — cùng khuôn `NotifyClientOfDocumentPublished`.
 *
 * **Kiểm tra lại lúc gửi:** yêu cầu chưa xoá mềm, vụ việc chưa bị huỷ (xoá mềm); KHÔNG hỏi `closed_at` (vòng sửa 1) —
 * giữa lúc sự kiện bắn (đồng bộ, cùng request HTTP) và lúc job hàng đợi thật sự chạy, vụ việc có
 * thể đã bị huỷ. Khách vẫn gửi được trên vụ ĐÃ ĐÓNG còn công bố trên cổng (`ClientRequestPolicy::create`), nên vụ đóng vẫn được báo. Không hỏi `is_published_to_portal`: đó là ranh giới PORTAL của khách
 * hàng (R12), không áp cho thư nội bộ của nhân sự — nhân sự vẫn cần biết có yêu cầu mới dù cổng
 * đang tắt.
 */
class NotifyStaffOfNewClientRequest
{
    public function handle(ClientRequest $request): int
    {
        $fresh = $this->stillOpenRequest($request);

        if ($fresh === null) {
            return 0;
        }

        $matter = $this->existingMatterFor($fresh);

        if ($matter === null) {
            return 0;
        }

        $recipients = app(ResolveStaffRecipients::class)->handle($matter, $this->preferred($fresh, $matter));

        // Thông báo trong hệ thống trước, KHÔNG phụ thuộc vào việc gửi thư có thành công hay
        // không — xem docblock lớp.
        foreach ($recipients as $recipient) {
            if (! $this->alreadyAlerted($recipient, $fresh)) {
                $recipient->notify(new NewClientRequestAlert($fresh));
            }
        }

        $sent = 0;
        $failure = null;

        foreach ($recipients as $recipient) {
            if ($this->alreadyDelivered($fresh, $recipient)) {
                $sent++;

                continue;
            }

            try {
                Mail::to($recipient->email)->send(new NewClientRequestMail($fresh, $recipient));
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
     * **"Trợ lý trong đội ngũ" = vai trò NHÂN SỰ (`App\Enums\Role::Assistant`), không phải
     * `MatterRole` (pivot `matter_user.role_in_matter`).** Cùng cách đọc mà
     * `App\Actions\Schedule\CheckDeadlines::recipientsFor()` đã dùng cho đúng cụm từ này
     * ("Trợ lý trong đội ngũ của CHÍNH vụ việc này, không phải mọi trợ lý của văn phòng") — lọc
     * `$matter->team()` (đúng vụ việc, không phải toàn văn phòng) theo `$u->hasRole(Role::
     * Assistant->value)`. `Gate::view()` ở `ResolveStaffRecipients` tự loại người không được xem
     * vụ (restricted, đã vô hiệu hoá/xoá mềm) — không lọc trước ở đây.
     *
     * @return array<int, User|null>
     */
    private function preferred(ClientRequest $request, Matter $matter): array
    {
        return [
            $request->assignee,
            $matter->leadLawyer,
            ...$matter->team()->get()->filter(fn (User $u): bool => $u->hasRole(Role::Assistant->value))->all(),
        ];
    }

    /**
     * Đọc lại TƯƠI, và cùng luật `withoutGlobalScope(ClientPortalScope::class)` với mọi Notify*
     * khác trong lane này — một job hàng đợi có thể chạy trong tiến trình còn treo ngữ cảnh
     * portal (đo được ở Task 3).
     */
    private function stillOpenRequest(ClientRequest $request): ?ClientRequest
    {
        return ClientRequest::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->find($request->getKey());
    }

    /**
     * Chỉ loại vụ đã XOÁ MỀM (huỷ) — mặc định của `Matter::query()`. KHÔNG `->open()`: khách gửi được
     * yêu cầu trên vụ ĐÃ ĐÓNG còn công bố trên cổng (`ClientRequestPolicy::create`), nên văn phòng vẫn
     * phải nhận báo (vòng sửa 1). Thư nội bộ không phải ranh giới cổng.
     */
    private function existingMatterFor(ClientRequest $request): ?Matter
    {
        return Matter::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->whereKey($request->matter_id)
            ->first(['id', 'client_id', 'code', 'title', 'lead_lawyer_id', 'confidentiality']);
    }

    private function alreadyAlerted(User $recipient, ClientRequest $request): bool
    {
        return $recipient->notifications()
            ->where('type', NewClientRequestAlert::class)
            ->where('data->viewData->client_request_id', $request->getKey())
            ->exists();
    }

    private function alreadyDelivered(ClientRequest $request, User $recipient): bool
    {
        return OutboundMessage::query()
            ->withoutGlobalScopes()
            ->where('related_type', $request->getMorphClass())
            ->where('related_id', $request->getKey())
            ->where('recipient', $recipient->email)
            ->where('status', OutboundStatus::Sent)
            ->exists();
    }

    /**
     * Listener hết `$tries`: báo luật sư phụ trách vụ việc, chuỗi dự phòng của
     * {@see ResolveStaffRecipients} tự lo phần còn lại. Cùng lý lẽ `NotifyClientOfDocumentPublished
     * ::reportFailure()`.
     */
    public function reportFailure(ClientRequest $request): void
    {
        $fresh = ClientRequest::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->withTrashed()
            ->find($request->getKey());

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
            $recipient->notify(new NewClientRequestMailFailedAlert($matter));
        }
    }
}
