<?php

namespace App\Policies;

use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\StageLogDraft;
use App\Models\User;
use App\Policies\Concerns\ChecksMatterAccess;
use App\Policies\Concerns\ReadsPortalParents;

/**
 * Nháp cập nhật tiến độ do AI soạn (M11 R5, Task 7). Khách KHÔNG BAO GIỜ — ở cả hai tầng: scope
 * `1 = 0` ({@see StageLogDraft::applyClientPortalConstraints()}) và mọi ability dưới đây trả `false`
 * cho `ClientUser`. Nhân sự đọc nháp của một vụ khi thấy được vụ đó (`MatterPolicy::view`, qua
 * {@see ChecksMatterAccess}) — cùng phạm vi với dòng tiến độ thật, vì người mở nháp trên tab Tiến độ
 * (Task 12) là người của đội ngũ. Mở nháp để GỬI và bỏ nháp là ability riêng của Task 12.
 *
 * Không ai xoá được nháp ({@see self::delete()}): chỉ bỏ, kèm lý do.
 */
class StageLogDraftPolicy
{
    use ChecksMatterAccess;
    use ReadsPortalParents;

    public function viewAny(User|ClientUser $user): bool
    {
        return $user instanceof User;
    }

    public function view(User|ClientUser $user, StageLogDraft $draft): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $matter = $this->parentWithoutPortalScope($draft, 'matter');

        return $matter instanceof Matter && $this->canSeeMatter($user, $matter);
    }

    /** Nháp không xoá được, kể cả với quản trị viên (`IsMcpDraft`; M6.5 R14). */
    public function delete(User|ClientUser $user, StageLogDraft $draft): bool
    {
        return false;
    }
}
