<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ClientUser;
use App\Models\MatterChecklistItem;
use App\Models\User;
use App\Policies\Concerns\ChecksMatterAccess;
use App\Policies\Concerns\ChecksPortalVisibility;

class MatterChecklistItemPolicy
{
    use ChecksMatterAccess;
    use ChecksPortalVisibility;

    public function viewAny(User|ClientUser $user): bool
    {
        return true;
    }

    public function view(User|ClientUser $user, MatterChecklistItem $item): bool
    {
        return $user instanceof ClientUser
            ? $this->visibleToPortal($user, $item) && $this->canSeeMatter($user, $item->matter)
            : $this->canSeeMatter($user, $item->matter);
    }

    /** Duyệt giấy tờ khách nộp — SPEC §5 checklist.review. */
    public function review(User|ClientUser $user, MatterChecklistItem $item): bool
    {
        return $user instanceof User
            && $user->can(Permission::ChecklistReview->value)
            && $this->canSeeMatter($user, $item->matter);
    }

    public function update(User|ClientUser $user, MatterChecklistItem $item): bool
    {
        return $this->review($user, $item);
    }
}
