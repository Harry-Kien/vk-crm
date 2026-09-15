<?php

namespace App\Policies;

use App\Models\ClientRequest;
use App\Models\ClientUser;
use App\Models\User;
use App\Policies\Concerns\ChecksMatterAccess;
use App\Policies\Concerns\ChecksPortalVisibility;

class ClientRequestPolicy
{
    use ChecksMatterAccess;
    use ChecksPortalVisibility;

    public function viewAny(User|ClientUser $user): bool
    {
        return true;
    }

    public function view(User|ClientUser $user, ClientRequest $request): bool
    {
        return $user instanceof ClientUser
            ? $this->visibleToPortal($user, $request) && $this->canSeeMatter($user, $request->matter)
            : $this->canSeeMatter($user, $request->matter);
    }

    /** Khách gửi yêu cầu; nhân sự trả lời (SPEC §5 portal). */
    public function create(User|ClientUser $user): bool
    {
        return true;
    }

    public function update(User|ClientUser $user, ClientRequest $request): bool
    {
        return $user instanceof User && $this->canSeeMatter($user, $request->matter);
    }
}
