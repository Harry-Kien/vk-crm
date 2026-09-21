<?php

namespace App\Actions\Portal;

use App\Actions\Concerns\ChecksAccountActive;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Enums\ClientRequestStatus;
use App\Models\ClientRequest;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Hộp thư của văn phòng, phần KHÔNG phải viết chữ: **nhận, giao việc, đổi trạng thái** —
 * SPEC §7.2 tab "Yêu cầu từ khách".
 *
 * # Vì sao có Action thứ ba, khi kế hoạch M5 chỉ liệt kê hai
 *
 * Kế hoạch Task 6 liệt kê `OpenClientRequest` và `ReplyToClientRequest`. Nhưng SPEC §7.2 giao cho
 * văn phòng **bốn** động từ — "nhận, đổi trạng thái, gán người xử lý, trả lời" — và ba động từ
 * đầu là những lần GHI vào `client_requests`. CLAUDE.md nói thẳng: *nghiệp vụ nằm trong
 * `app/Actions/`; Filament resource/controller/job chỉ gọi Action*, và đó không phải một quy ước
 * trang trí — vòng rà soát M3 đã bắt `ViewMatter` gọi thẳng `$record->update()` và phải tách ra
 * thành `SetMatterPortalPublication` vì đúng lý do đang áp dụng ở đây: cổng quyền, kiểm tra
 * trạng thái và dòng nhật ký phải sống cùng một chỗ với lần ghi.
 *
 * Nên lệch so với kế hoạch là **có**, và nó được ghi ra chứ không làm lặng lẽ: một tệp Action nữa,
 * cùng namespace `Portal` với hai tệp kia. Namespace nói về TÍNH NĂNG (cuộc trao đổi với khách),
 * không về việc ai thao tác — `ReplyToClientRequest` cũng nhận cả hai phía.
 *
 * # Hai phương thức, không phải một `handle()` mang bốn tham số
 *
 * "Giao cho ai" và "đang ở đâu" là hai câu hỏi, và gộp chúng vào một chữ ký sẽ đẻ ra một tham số
 * sentinel để phân biệt "gán cho không ai" với "đừng đụng vào người đang giữ" — đúng loại tham số
 * mà nơi gọi truyền sai một lần là im lặng xoá mất người phụ trách. Hai phương thức, mỗi cái một
 * câu, dùng chung {@see self::open()} cho phần đọc lại và gác cổng.
 *
 * # "Giao việc" cũng là "nhận"
 *
 * SPEC §7.2 kể "nhận" như một động từ riêng, nhưng nó không phải một cột riêng: một yêu cầu được
 * nhận là một yêu cầu **đã có người đứng tên và không còn là `new`**. Nên {@see self::assign()}
 * tự đẩy `new → in_progress`, và trợ lý bấm "Giao việc" chọn chính mình là đã "nhận". Một nút
 * thứ hai chỉ để đổi một chữ sẽ là một nút người ta quên bấm, và khi đó cột trạng thái nói sai.
 *
 * Chiều ngược lại **không** tự động: gỡ người phụ trách (`null`) KHÔNG kéo trạng thái về `new`.
 * `new` nghĩa là "chưa ai trong văn phòng nhìn thấy", và một khi đã có người nhìn thì điều đó
 * không thành chưa xảy ra được nữa.
 *
 * # Người được giao việc phải MỞ ĐƯỢC hồ sơ
 *
 * {@see self::assign()} hỏi `MatterPolicy::update` **trên người được giao**, không chỉ trên người
 * đang giao. Giao một yêu cầu cho người không mở được vụ việc là đẩy nó vào một hàng đợi không ai
 * nhìn thấy: nó biến mất khỏi "chưa ai nhận" mà không ai làm được gì với nó. Với một vụ việc
 * `restricted` (SPEC §4.6) đây còn là một cách rò rỉ tên hồ sơ qua một ô chọn.
 *
 * # Không đọc `auth()`, không tin tham số
 *
 * Cùng kỷ luật với {@see OpenClientRequest} và {@see ReplyToClientRequest}.
 */
class TriageClientRequest
{
    use ChecksAccountActive;
    use ReadsWithoutPortalScope;

    /**
     * Giao một yêu cầu cho một người, hoặc gỡ người đang giữ ra (`$assignee === null`).
     */
    public function assign(ClientRequest $request, User $actor, ?User $assignee): ClientRequest
    {
        [$thread, $matter] = $this->open($request, $actor);

        if ($assignee !== null && ! Gate::forUser($assignee)->allows('update', $matter)) {
            // `ValidationException` chứ không `AuthorizationException`: câu này nói về Ô CHỌN —
            // người đang giao có quyền, họ chỉ vừa chọn sai người — và nó phải hiện ngay dưới ô
            // đó. Khoá là tên trần `assigned_to`, trùng tên ô trong modal; `ReportsActionFailures`
            // dịch nó sang state path thật.
            throw ValidationException::withMessages([
                'assigned_to' => [__('requests.validation.assignee_cannot_open')],
            ]);
        }

        $previous = $thread->assigned_to;

        $thread->assigned_to = $assignee?->getKey();

        // "Giao việc" cũng là "nhận" — xem docblock lớp. Một chiều, không có chiều ngược lại.
        if ($assignee !== null && $thread->status === ClientRequestStatus::New) {
            $thread->status = ClientRequestStatus::InProgress;
        }

        $thread->save();

        Audit::record('client_request_assigned', $thread, [
            'matter_id' => $thread->matter_id,
            'client_id' => $matter->client_id,
            'from' => $previous,
            'to' => $thread->assigned_to,
        ], causer: $actor);

        return $thread;
    }

    /**
     * Đặt trạng thái tay: `new → in_progress → answered → closed`, và ngược lại khi cần mở lại
     * một việc đã đóng sớm.
     *
     * **Không có ma trận chuyển trạng thái nào ở đây, và đó là một quyết định.** `Matter` có một
     * (`allowed_next`, SPEC §4.5) vì giai đoạn tố tụng là một quy trình pháp lý có thứ tự; một
     * cuộc trao đổi qua lại thì không. Một trợ lý bấm nhầm "Đã đóng" phải mở lại được ngay, và
     * một luật sư trả lời qua điện thoại rồi đánh dấu thẳng "Đã trả lời" là việc đúng, không phải
     * một bước nhảy cóc.
     *
     * `answered_at` được ghi khi trạng thái ĐẾN `answered` và cột còn trống — trường hợp thật là
     * "văn phòng đã gọi điện trả lời rồi mới vào đánh dấu". Nếu cột đã có giá trị thì giữ nguyên:
     * nó là dấu thời gian văn phòng trả lời lần đầu, và {@see ReplyToClientRequest} mới là nơi
     * làm nó mới lại, vì ở đó có một câu trả lời thật vừa được viết ra. Rời khỏi `answered`
     * **không** xoá cột: một sự kiện đã xảy ra thì không viết lại được cho khớp một cái nhãn.
     */
    public function setStatus(ClientRequest $request, User $actor, ClientRequestStatus $status): ClientRequest
    {
        [$thread, $matter] = $this->open($request, $actor);

        $previous = $thread->status;

        $thread->status = $status;

        if ($status === ClientRequestStatus::Answered && $thread->answered_at === null) {
            $thread->answered_at = now();
        }

        $thread->save();

        Audit::record('client_request_status_changed', $thread, [
            'matter_id' => $thread->matter_id,
            'client_id' => $matter->client_id,
            'from' => $previous->value,
            'to' => $status->value,
        ], causer: $actor);

        return $thread;
    }

    /**
     * Đọc lại hàng thật, nạp sẵn vụ việc bằng một truy vấn đã gỡ scope, rồi gác cổng.
     *
     * `setRelation('matter', ...)` TRƯỚC khi `Gate` chạm vào đối tượng, cùng lý do đã đo ở M4:
     * một quan hệ nạp lười chạy dưới guard NÀO ĐANG MỞ, nên với một phiên portal đang mở trong
     * cùng trình duyệt (chuyện thường ngày lúc demo) `$request->matter` trả `null` và một nhân sự
     * đủ quyền bị từ chối oan.
     *
     * @return array{0: ClientRequest, 1: Matter}
     */
    private function open(ClientRequest $request, User $actor): array
    {
        $thread = $this->scopelessly(ClientRequest::query())->find($request->getKey()) ?? $this->refuse();

        $matter = $this->scopelessly(Matter::query())->find($thread->matter_id);
        $thread->setRelation('matter', $matter);

        if (! $this->accountIsActive($actor)) {
            $this->refuse();
        }

        // `ClientRequestPolicy::update` — tức `MatterPolicy::update`, không phải "thấy được vụ
        // việc". Kế toán không có `matter.update` nên không nhận, không giao và không đổi trạng
        // thái yêu cầu của khách (SPEC §5).
        if ($matter === null || Gate::forUser($actor)->inspect('update', $thread)->denied()) {
            $this->refuse();
        }

        return [$thread, $matter];
    }

    /**
     * **Mọi lý do, MỘT câu** — SPEC §10.10, và §10.10 không chừa ngoại lệ cho người trong văn
     * phòng: M3 đã áp đúng luật này cho cả panel nội bộ với chính ví dụ kế toán
     * (`AnswerDeniedPanelRequestsWithNotFound`). Yêu cầu không tồn tại, thuộc một vụ việc người
     * hỏi không mở được, vụ việc đã bị xoá mềm, tài khoản đã bị vô hiệu hoá: cùng một câu.
     */
    private function refuse(): never
    {
        throw new AuthorizationException(__('requests.unavailable'));
    }
}
