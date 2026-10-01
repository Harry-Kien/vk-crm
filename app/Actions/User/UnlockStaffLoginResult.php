<?php

namespace App\Actions\User;

use App\Actions\Portal\UnlockPortalLoginResult;

/**
 * Kết quả của {@see UnlockStaffLogin::handle()}. `$minutesRemaining` chỉ có ý nghĩa khi
 * `$ipStillLocked` là `true`: số phút còn lại của chiều IP KHÔNG được xoá vì không NAT-an toàn
 * (giá trị LỚN NHẤT nếu cả hai bước còn khoá). `$anyAddressLockedMinutes` (final review I2): số
 * phút còn lại lâu nhất của BẤT KỲ khoá địa chỉ nào của guard `web` còn chạm trần sau lần mở khoá,
 * `null` nếu không còn — trang chỉ hứa "đăng nhập lại được ngay" khi không còn khoá nào. Cùng hình
 * dạng {@see UnlockPortalLoginResult}.
 */
final class UnlockStaffLoginResult
{
    public function __construct(
        public readonly bool $ipStillLocked,
        public readonly ?int $minutesRemaining,
        public readonly ?int $anyAddressLockedMinutes,
    ) {}
}
