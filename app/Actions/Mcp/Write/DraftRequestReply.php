<?php

namespace App\Actions\Mcp\Write;

use App\Actions\Mcp\McpMatterScope;
use App\Actions\Mcp\UseReplyDraft;
use App\Actions\Mcp\Write\Concerns\WritesIdempotentDrafts;
use App\Actions\Portal\ReplyToClientRequest;
use App\Exceptions\ClientRequestNotOpen;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\ClientRequestReplyDraft;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Tool `draft_request_reply` (kế hoạch M11, bảng tool 13; R5, R6, R11): ghi một NHÁP trả lời một yêu
 * cầu của khách vào `client_request_reply_drafts` — không bao giờ vào `client_request_replies`, không
 * đổi trạng thái luồng, không nhận người nhận. Một người trong `/admin` mở nháp ở tab "Yêu cầu từ
 * khách", sửa, rồi bấm Gửi ({@see UseReplyDraft} → `ReplyToClientRequest`, Task 12) dưới tên của chính
 * họ. Nội dung nháp có viết gì ("gửi ngay cho khách") cũng chỉ là chữ trong một bảng cổng khách không
 * đọc.
 *
 * Thứ tự:
 *  1. yêu cầu thuộc một vụ trong tập R3, chưa rút, và qua `ClientRequestPolicy::view` — đúng điều kiện
 *     của `get_client_request`; không thì `null` ("Không tìm thấy");
 *  2. cổng của web: `ClientRequestReplyPolicy::create` với ĐÚNG luồng đó — câu
 *     {@see ReplyToClientRequest} hỏi (không chép luật); không qua thì `mcp.tool_errors.forbidden`;
 *  3. luồng đã đóng: lỗi mang câu của văn phòng ({@see ClientRequestNotOpen::closedForStaff()}) — nháp
 *     cho một luồng đã đóng không gửi được. Hỏi SAU cổng quyền, như `ReplyToClientRequest`;
 *  4. một transaction theo thứ tự khoá chung — dòng `matters` TRƯỚC (câu đầu tiên), rồi dòng yêu cầu
 *     (đọc lại dưới khoá: rút hay đóng giữa chừng thì như bước 1 và 3), rồi khoá idempotency
 *     ({@see WritesIdempotentDrafts}) và nháp.
 *
 * Server ÉP người soạn (`created_by` = `$actor`).
 */
final class DraftRequestReply
{
    use WritesIdempotentDrafts;

    /** Cùng trần với câu trả lời trên web ({@see ReplyToClientRequest::CONTENT_MAX}): nháp phải gửi được nguyên văn. */
    public const CONTENT_MAX_LENGTH = ReplyToClientRequest::CONTENT_MAX;

    public function __construct(private readonly McpMatterScope $scope) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, int $requestId, string $content, string $idempotencyKey): ?DraftOutcome
    {
        $thread = $this->visibleThread($actor, $requestId);

        if ($thread === null) {
            return null;
        }

        if (Gate::forUser($actor)->inspect('create', [ClientRequestReply::class, $thread])->denied()) {
            throw new AuthorizationException(__('mcp.tool_errors.forbidden'));
        }

        $this->guardOpen($thread);

        $key = $this->normalizedKey($idempotencyKey);
        $content = trim($content);

        return $this->writeOnce(
            fn (): ?DraftOutcome => DB::transaction(function () use ($actor, $thread, $key, $content): ?DraftOutcome {
                // Câu ĐẦU TIÊN chạm CSDL: khoá vụ, rồi khoá yêu cầu (thứ tự khoá chung).
                $matter = $this->scopelessly(Matter::query())->lockForUpdate()->find($thread->matter_id);
                $locked = $this->scopelessly(ClientRequest::query())->lockForUpdate()->find($thread->getKey());

                if ($matter === null || $locked === null) {
                    return null;
                }

                $locked->setRelation('matter', $matter);
                $this->guardOpen($locked);

                $existing = $this->existingDraft(ClientRequestReplyDraft::class, $actor, $key);

                if ($existing !== null) {
                    return $this->replay($existing, $locked, $content);
                }

                $draft = new ClientRequestReplyDraft([
                    'request_id' => $locked->getKey(),
                    'content' => $content,
                    'idempotency_key' => $key,
                ]);
                $draft->forceFill(['created_by' => $actor->getKey()])->save();

                return new DraftOutcome($draft->refresh()->setRelation('request', $locked), created: true);
            }),
            fn (): DraftOutcome => $this->replay(
                $this->existingDraft(ClientRequestReplyDraft::class, $actor, $key) ?? throw $this->conflict(),
                $thread,
                $content,
            ),
        );
    }

    /**
     * Yêu cầu trong tập R3 mà người này xem được trên web, nạp sẵn vụ (bỏ scope cổng) TRƯỚC khi Gate
     * chạm vào: policy đọc `$request->matter`, và một quan hệ nạp lười chạy dưới phiên cổng khách đang
     * mở (nếu có) sẽ trả `null`.
     */
    private function visibleThread(User $actor, int $requestId): ?ClientRequest
    {
        $thread = $this->scope->constrain(ClientRequest::query(), $actor)->whereKey($requestId)->first();

        if ($thread === null) {
            return null;
        }

        $thread->setRelation('matter', $this->scopelessly(Matter::query())->find($thread->matter_id));

        return Gate::forUser($actor)->allows('view', $thread) ? $thread : null;
    }

    /** @throws ValidationException */
    private function guardOpen(ClientRequest $thread): void
    {
        if (! ClientRequestNotOpen::accepts($thread->status)) {
            throw ValidationException::withMessages([
                'request_id' => [ClientRequestNotOpen::closedForStaff()->getMessage()],
            ]);
        }
    }

    private function replay(ClientRequestReplyDraft $existing, ClientRequest $thread, string $content): DraftOutcome
    {
        if ((int) $existing->request_id !== (int) $thread->getKey() || $existing->content !== $content) {
            throw $this->conflict();
        }

        return new DraftOutcome($existing->setRelation('request', $thread), created: false);
    }
}
