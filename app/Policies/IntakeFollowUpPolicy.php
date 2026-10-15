<?php

namespace App\Policies;

use App\Actions\Intake\LogIntakeFollowUp;
use App\Enums\Permission;
use App\Models\ClientUser;
use App\Models\IntakeFollowUp;
use App\Models\IntakeRequest;
use App\Models\User;

/**
 * Quyền trên một lần chăm sóc lead (làn kd, 2026-10-14). Thành ngữ bản ghi con như `IntakePartyPolicy`:
 * thấy được lead cha ({@see IntakeRequest::isVisibleTo()}) thì thấy các lần chăm sóc của nó. Ghi một lần
 * chăm sóc là việc của {@see LogIntakeFollowUp} (cổng `update` của lead cha); `create` ở đây chỉ là chốt
 * chặn theo QUYỀN. **Không ai sửa hay xoá một lần chăm sóc** — dòng là lịch sử; ẩn danh (R7) là cập nhật
 * của `AnonymiseProspect`, không đi qua policy này. Filament coi ability thiếu phương thức là CHO PHÉP, nên
 * phải khai đủ.
 *
 * **`ClientUser` luôn bị từ chối, mọi ability** — khớp `applyClientPortalConstraints()` của model.
 */
class IntakeFollowUpPolicy
{
    public function viewAny(User|ClientUser $user): bool
    {
        return $user instanceof User
            && ($user->can(Permission::IntakeCreate->value) || $user->can(Permission::IntakeViewAny->value));
    }

    public function view(User|ClientUser $user, IntakeFollowUp $followUp): bool
    {
        return $user instanceof User && $followUp->intakeRequest->isVisibleTo($user);
    }

    public function create(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::IntakeCreate->value);
    }

    public function update(User|ClientUser $user, IntakeFollowUp $followUp): bool
    {
        return false;
    }

    public function delete(User|ClientUser $user, IntakeFollowUp $followUp): bool
    {
        return false;
    }

    public function deleteAny(User|ClientUser $user): bool
    {
        return false;
    }

    public function restore(User|ClientUser $user, IntakeFollowUp $followUp): bool
    {
        return false;
    }

    public function forceDelete(User|ClientUser $user, IntakeFollowUp $followUp): bool
    {
        return false;
    }
}
