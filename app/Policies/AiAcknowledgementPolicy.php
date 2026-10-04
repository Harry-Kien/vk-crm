<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AiAcknowledgement;
use App\Models\ClientUser;
use App\Models\User;

/**
 * Lời cam kết chính sách dùng AI (M11 R12 mục 1). Quản trị (`settings.manage`, cùng quyền với trang
 * "Kết nối AI" của Task 15) xem được mọi dòng; một nhân sự xem được dòng của chính mình (trang "Kết
 * nối AI của tôi"). Khách hàng không bao giờ — bảng còn chặn `1 = 0` ở tầng truy vấn của cổng.
 *
 * Không ability ghi nào: dòng chỉ sinh qua `App\Actions\Mcp\AcknowledgeAiPolicy` (người đó tự cam
 * kết), và không bao giờ sửa hay xoá.
 *
 * Policy tồn tại cũng vì luật M2 (`PortalCoverageTest`): mọi model giới hạn portal đều có policy.
 */
class AiAcknowledgementPolicy
{
    public function viewAny(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::SettingsManage->value);
    }

    public function view(User|ClientUser $user, AiAcknowledgement $acknowledgement): bool
    {
        return $user instanceof User
            && ($this->viewAny($user) || (int) $acknowledgement->user_id === (int) $user->getKey());
    }
}
