<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ClientUser;
use App\Models\IntakeParty;
use App\Models\IntakeRequest;
use App\Models\User;

/**
 * Quyền trên một bên đối lập khai lúc tiếp nhận (M10). Theo đúng thành ngữ mọi bản ghi con:
 * thấy được bản ghi tiếp nhận cha ({@see IntakeRequest::isVisibleTo()}) thì thấy được
 * các bên của nó; sửa và xoá đi theo quyền sửa bản ghi cha ({@see IntakeRequestPolicy::update()}).
 *
 * `delete` có mặt (không như `IntakeRequestPolicy`) vì gỡ một bên NHẬP NHẦM khỏi form là thao tác
 * bình thường; ẩn danh (R7b) mới là cập nhật, và nó không đi qua policy này.
 *
 * `create` không có ngữ cảnh bản ghi cha (Laravel gọi `create` chỉ với tên lớp): một chốt chặn theo
 * QUYỀN (`intake.create`); phạm vi theo bản ghi cha nằm ở `update`.
 *
 * **`ClientUser` luôn bị từ chối, mọi ability** — khớp `applyClientPortalConstraints()` của model.
 */
class IntakePartyPolicy
{
    public function viewAny(User|ClientUser $user): bool
    {
        return $user instanceof User
            && ($user->can(Permission::IntakeCreate->value) || $user->can(Permission::IntakeViewAny->value));
    }

    public function view(User|ClientUser $user, IntakeParty $party): bool
    {
        return $user instanceof User && $party->intakeRequest->isVisibleTo($user);
    }

    public function create(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::IntakeCreate->value);
    }

    public function update(User|ClientUser $user, IntakeParty $party): bool
    {
        return $this->view($user, $party);
    }

    public function delete(User|ClientUser $user, IntakeParty $party): bool
    {
        return $this->update($user, $party);
    }
}
