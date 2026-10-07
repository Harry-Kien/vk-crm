<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ClientUser;
use App\Models\PerformanceSnapshot;
use App\Models\User;

/**
 * Ảnh chụp số liệu hằng ngày (M13 R10): dữ liệu nhân sự nội bộ, hệ thống ghi.
 *
 * - Khách: không gì cả.
 * - Ghi (tạo, sửa, xoá, khôi phục, xoá vĩnh viễn): không ai qua màn hình. Chỉ tác vụ chụp
 *   (`CapturePerformanceSnapshots`, không người đăng nhập) ghi và dọn dòng quá hạn.
 * - Đọc một dòng: đúng {@see PerformanceSnapshot::visibleLevels()} cho người của dòng đó — không viết
 *   luật xem thứ hai. `viewAny` là lớp ngoài cùng hình dạng với `canView()` của hai widget xu hướng
 *   (`matter.view` hoặc `performance.viewAny`), không thay cho kiểm tra theo người.
 */
class PerformanceSnapshotPolicy
{
    public function viewAny(User|ClientUser $user): bool
    {
        return $user instanceof User
            && ($user->can(Permission::MatterView->value) || $user->can(Permission::PerformanceViewAny->value));
    }

    public function view(User|ClientUser $user, PerformanceSnapshot $snapshot): bool
    {
        return $user instanceof User
            && $snapshot->user instanceof User
            && in_array($snapshot->confidentiality, PerformanceSnapshot::visibleLevels($user, $snapshot->user), true);
    }

    public function create(User|ClientUser $user): bool
    {
        return false;
    }

    public function update(User|ClientUser $user, PerformanceSnapshot $snapshot): bool
    {
        return false;
    }

    public function delete(User|ClientUser $user, PerformanceSnapshot $snapshot): bool
    {
        return false;
    }

    public function restore(User|ClientUser $user, PerformanceSnapshot $snapshot): bool
    {
        return false;
    }

    public function forceDelete(User|ClientUser $user, PerformanceSnapshot $snapshot): bool
    {
        return false;
    }
}
