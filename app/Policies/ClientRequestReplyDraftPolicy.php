<?php

namespace App\Policies;

use App\Models\ClientRequest;
use App\Models\ClientRequestReplyDraft;
use App\Models\ClientUser;
use App\Models\User;
use App\Policies\Concerns\ReadsPortalParents;

/**
 * Nháp trả lời một yêu cầu của khách, do AI soạn (M11 R5, Task 7). Khách KHÔNG BAO GIỜ — ở cả hai
 * tầng: scope `1 = 0` ({@see ClientRequestReplyDraft::applyClientPortalConstraints()}) và mọi ability
 * dưới đây trả `false` cho `ClientUser`. Một yêu cầu khách viết được đọc lại toàn bộ luồng trả lời
 * (`ClientRequestReplyPolicy`) — nháp thì khác: nó là câu văn phòng CHƯA quyết định gửi.
 *
 * Nhân sự đọc nháp khi đọc được yêu cầu cha (`ClientRequestPolicy::view`, tức thấy được vụ việc của
 * nó) — không chép lại điều kiện nào. Yêu cầu cha đã xoá mềm → không ai đọc nháp của nó qua đây.
 * Mở nháp để GỬI và bỏ nháp là ability riêng của Task 12.
 */
class ClientRequestReplyDraftPolicy
{
    use ReadsPortalParents;

    public function viewAny(User|ClientUser $user): bool
    {
        return $user instanceof User;
    }

    public function view(User|ClientUser $user, ClientRequestReplyDraft $draft): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $request = $this->parentWithoutPortalScope($draft, 'request');

        return $request instanceof ClientRequest && $user->can('view', $request);
    }

    /** Nháp không xoá được, kể cả với quản trị viên (`IsMcpDraft`; M6.5 R14). */
    public function delete(User|ClientUser $user, ClientRequestReplyDraft $draft): bool
    {
        return false;
    }
}
