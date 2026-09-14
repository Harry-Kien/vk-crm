<?php

namespace App\Policies;

use App\Models\ClientRequestReply;
use App\Models\ClientUser;
use App\Models\User;
use App\Policies\Concerns\ChecksMatterAccess;

/** Trả lời yêu cầu của khách: thừa hưởng quyền xem của vụ việc cha, chỉ nhân sự. */
class ClientRequestReplyPolicy
{
    use ChecksMatterAccess;

    public function viewAny(User|ClientUser $user): bool
    {
        return $user instanceof User;
    }

    public function view(User|ClientUser $user, ClientRequestReply $reply): bool
    {
        return $user instanceof User && $this->canSeeMatter($user, $reply->request->matter);
    }
}
