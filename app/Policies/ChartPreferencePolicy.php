<?php

namespace App\Policies;

use App\Models\ChartPreference;
use App\Models\ClientUser;
use App\Models\User;

/**
 * Lựa chọn dạng biểu đồ là của RIÊNG từng nhân sự. Không màn hình nào liệt kê chúng, không resource
 * Filament nào đọc chúng — widget tự đọc dòng của người đang đăng nhập qua
 * {@see ChartPreference::kindFor()}. Policy này là lớp thứ ba của bảo vệ cổng khách (scope, policy,
 * serialize): nó đọc thẳng `user_id` của dòng, không dựa vào scope.
 */
class ChartPreferencePolicy
{
    /** Không ai có danh sách để xem — kể cả quản trị viên: đây không phải dữ liệu để quản lý. */
    public function viewAny(User|ClientUser $user): bool
    {
        return false;
    }

    public function view(User|ClientUser $user, ChartPreference $preference): bool
    {
        return $user instanceof User && (int) $preference->user_id === (int) $user->getKey();
    }
}
