<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ClientUser;
use App\Models\Deadline;
use App\Models\User;
use App\Policies\Concerns\ChecksMatterAccess;
use App\Policies\Concerns\ChecksPortalVisibility;

class DeadlinePolicy
{
    use ChecksMatterAccess;
    use ChecksPortalVisibility;

    public function viewAny(User|ClientUser $user): bool
    {
        return true;
    }

    /**
     * `is_published` đọc thẳng trên bản ghi, đứng cạnh `visibleToPortal()` — cùng lý lẽ với
     * `StageLogPolicy::view()` và `MatterPolicy::releasedToPortal()`, thêm ở M5 Task 2. Khối 6
     * của SPEC §8.3 ("Mốc thời hạn sắp tới — chỉ mốc `is_published`") dựng trên đúng điều kiện
     * này, và một điều kiện chỉ được phát biểu một lần thì không phải một tầng.
     */
    public function view(User|ClientUser $user, Deadline $deadline): bool
    {
        return $user instanceof ClientUser
            ? (bool) $deadline->is_published
                && $this->visibleToPortal($user, $deadline)
                && $this->canSeeMatter($user, $deadline->matter)
            : $this->canSeeMatter($user, $deadline->matter);
    }

    public function create(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::MatterUpdate->value);
    }

    /**
     * Cùng điều kiện với `create()` ở trên, chỉ khác là đã biết vụ việc nên hỏi thẳng
     * `MatterPolicy::update`. Trên bảng quyền SPEC §5 hôm nay điều này chưa loại thêm vai trò
     * nào — bốn vai trò có `matter.view` đều có `matter.update` — nhưng nó gỡ luật ra khỏi bảng
     * quyền hiện hành: hôm nào văn phòng cấp một vai trò chỉ-đọc (`matter.view` mà không
     * `matter.update`, đúng chữ "hạn chế" ở ô trợ lý trong SPEC §5) thì hàng mốc thời hạn khoá
     * lại mà không phải sửa policy. Nó cũng chặn sửa mốc trên một vụ việc đã xoá mềm.
     */
    public function update(User|ClientUser $user, Deadline $deadline): bool
    {
        return $user instanceof User && $this->canUpdateMatter($user, $deadline->matter);
    }

    public function delete(User|ClientUser $user, Deadline $deadline): bool
    {
        return $this->update($user, $deadline);
    }
}
