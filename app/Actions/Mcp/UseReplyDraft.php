<?php

namespace App\Actions\Mcp;

use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Actions\Portal\ReplyToClientRequest;
use App\Exceptions\McpDraftNotPending;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\ClientRequestReplyDraft;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Gửi một câu trả lời cho khách từ một nháp do AI soạn (M11 R5, Task 12) — bước "người bấm Gửi
 * trong `/admin`" của tool `draft_request_reply`.
 *
 * Câu trả lời thật đi qua ĐÚNG `ReplyToClientRequest`, dưới tên `$actor` là NGƯỜI BẤM, với nội dung
 * người bấm gửi lên từ form (đã sửa hay chưa) — không phải bản trong nháp. Mọi luật của luồng web giữ
 * nguyên ở Action đó: tài khoản còn hiệu lực, `ClientRequestReplyPolicy::create` với chính cuộc trao
 * đổi, cuộc trao đổi còn mở, nội dung 1–5000 ký tự, trạng thái chuyển sang "Đã trả lời".
 *
 * Một transaction, thứ tự khoá: dòng `client_requests` TRƯỚC (cùng dòng `ReplyToClientRequest` khoá
 * đầu tiên), rồi dòng nháp:
 *  1. khoá cuộc trao đổi; không còn, hay người bấm không được viết trả lời vào nó → câu từ chối
 *     chung, TRƯỚC khi nói gì về nháp (SPEC §10.10);
 *  2. khoá nháp; nháp của cuộc trao đổi khác → cùng câu; nháp đã dùng hoặc đã bỏ →
 *     {@see McpDraftNotPending}, không ghi gì;
 *  3. `ReplyToClientRequest` (transaction lồng, khoá lại cùng dòng);
 *  4. nháp trỏ `used_reply_id` tới câu trả lời, và audit `mcp_draft_used` (chủ thể là câu trả lời)
 *     mang id nháp và người soạn nháp.
 *
 * Thư "văn phòng đã trả lời" cho khách là sự kiện `ClientRequestAnswered` của `ReplyToClientRequest`,
 * đợi commit như mọi câu trả lời gõ tay.
 */
class UseReplyDraft
{
    use ReadsWithoutPortalScope;

    public function handle(ClientRequestReplyDraft $draft, ClientRequest $request, User $actor, string $content): ClientRequestReply
    {
        return DB::transaction(function () use ($draft, $request, $actor, $content): ClientRequestReply {
            // Câu ĐẦU TIÊN chạm CSDL: khoá cuộc trao đổi.
            $thread = $this->scopelessly(ClientRequest::query())->lockForUpdate()->find($request->getKey());

            if ($thread === null) {
                $this->refuse();
            }

            // Quan hệ `matter` nạp sẵn, không scope, TRƯỚC khi Gate chạm vào — cùng lý do với
            // `ReplyToClientRequest`. Vụ đã xoá mềm ra `null` và policy từ chối.
            $thread->setRelation('matter', $this->scopelessly(Matter::query())->find($thread->matter_id));

            if (Gate::forUser($actor)->denies('create', [ClientRequestReply::class, $thread])) {
                $this->refuse();
            }

            $fresh = $this->scopelessly(ClientRequestReplyDraft::query())->lockForUpdate()->find($draft->getKey());

            if ($fresh === null || $fresh->request_id !== $thread->getKey()) {
                $this->refuse();
            }

            if (! $fresh->isPending()) {
                throw McpDraftNotPending::make();
            }

            $reply = app(ReplyToClientRequest::class)->handle($thread, $actor, $content);

            $fresh->forceFill([ClientRequestReplyDraft::usedColumn() => $reply->getKey()])->save();

            Audit::record('mcp_draft_used', $reply, [
                'matter_id' => $thread->matter_id,
                'client_request_id' => $thread->getKey(),
                'client_request_reply_id' => $reply->getKey(),
                'draft_type' => ClientRequestReplyDraft::draftType(),
                'draft_id' => $fresh->getKey(),
                'draft_created_by' => $fresh->created_by,
            ], causer: $actor);

            return $reply;
        });
    }

    /** Cuộc trao đổi không còn, không có quyền, hay nháp không thuộc nó: một câu (SPEC §10.10). */
    private function refuse(): never
    {
        throw new AuthorizationException(__('ai_drafts.unavailable'));
    }
}
