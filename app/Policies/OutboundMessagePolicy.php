<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ClientUser;
use App\Models\OutboundMessage;
use App\Models\User;

/** Nhật ký thông báo gửi đi: chỉ nhân sự có quyền tra cứu nhật ký mới xem được (SPEC §4.15). */
class OutboundMessagePolicy
{
    public function viewAny(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::AuditLogView->value);
    }

    public function view(User|ClientUser $user, OutboundMessage $message): bool
    {
        return $this->viewAny($user);
    }
}
