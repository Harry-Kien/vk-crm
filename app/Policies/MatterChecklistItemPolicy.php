<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ClientUser;
use App\Models\Matter;
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

    /**
     * Thêm một đầu mục riêng cho một vụ việc (M6.5 Task 15, SPEC §4.10/§7.4) — không phải áp
     * mẫu, chỉ một vụ việc cụ thể. Uỷ thẳng cho `MatterPolicy::update()` để "chưa xoá mềm, có
     * `matter.update`, thấy được vụ việc" chỉ tồn tại MỘT chỗ (cùng lý lẽ với
     * `DocumentPolicy::create()`) — KHÔNG uỷ cho `manageTeam()`: task brief nói rõ trợ lý cũng
     * thêm được đầu mục ("việc xin giấy tờ, không phải việc công bố"), khác với quản lý đội ngũ
     * hay đổi `confidentiality` mà R5 loại trừ trợ lý ra. `Role::Assistant->permissions()` đã có
     * sẵn `MatterUpdate`, nên nhánh này tự đúng cho trợ lý mà không cần viết thêm điều kiện.
     */
    public function create(User|ClientUser $user, Matter $matter): bool
    {
        return $this->canUpdateMatter($user, $matter);
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
