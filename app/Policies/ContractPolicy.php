<?php

namespace App\Policies;

use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\User;
use App\Policies\Concerns\ChecksMatterAccess;

/**
 * Đủ để `PortalCoverageTest` xanh (mọi model bị `RestrictedToClientPortal` phải có policy) và
 * để chặn `ClientUser` — Task 2 không dựng màn hình nào đọc bảng này. Định nghĩa ĐẦY ĐỦ theo P3
 * ("ai thấy và ghi tiền") — `billing.view` + `Matter::listableBy()`, không phải `matter.view` —
 * là việc của Task 3 (giao Opus), nơi quyền `billing.view` mới tồn tại. Tạm thời mượn
 * `canSeeMatter()` (tức `matter.view`) làm điều kiện xem tối thiểu, cùng hình dạng với
 * `MatterArchivePolicy` — một model chưa có Action/màn hình riêng.
 */
class ContractPolicy
{
    use ChecksMatterAccess;

    public function viewAny(User|ClientUser $user): bool
    {
        return $user instanceof User;
    }

    public function view(User|ClientUser $user, Contract $contract): bool
    {
        return $user instanceof User && $this->canSeeMatter($user, $contract->matter);
    }
}
