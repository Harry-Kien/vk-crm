<?php

namespace App\Policies;

use App\Models\ClientUser;
use App\Models\NativePushDevice;
use App\Models\User;

/**
 * Máy iPhone nhận thông báo là của RIÊNG từng nhân sự. Không màn hình nào liệt kê chúng (ứng dụng
 * chỉ đăng ký/gỡ máy của chính phiên đang dùng qua `NativeDeviceController`). Policy này là lớp
 * thứ ba của bảo vệ cổng khách (scope, policy, serialize): nó đọc thẳng `user_id`, không dựa vào scope.
 */
class NativePushDevicePolicy
{
    /** Không ai có danh sách để xem — kể cả quản trị viên: token là địa chỉ nhận thông báo, không phải dữ liệu để quản lý. */
    public function viewAny(User|ClientUser $user): bool
    {
        return false;
    }

    public function view(User|ClientUser $user, NativePushDevice $device): bool
    {
        return $user instanceof User && (int) $device->user_id === (int) $user->getKey();
    }
}
