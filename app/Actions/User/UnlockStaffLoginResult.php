<?php

namespace App\Actions\User;

use App\Actions\Portal\UnlockPortalLoginResult;

/**
 * Kết quả của {@see UnlockStaffLogin::handle()}. `$minutesRemaining` chỉ có ý nghĩa khi
 * `$ipStillLocked` là `true`: số phút còn lại của chiều IP KHÔNG được xoá vì không NAT-an toàn
 * (giá trị LỚN NHẤT nếu cả hai bước còn khoá). Cùng hình dạng
 * {@see UnlockPortalLoginResult}.
 */
final class UnlockStaffLoginResult
{
    public function __construct(
        public readonly bool $ipStillLocked,
        public readonly ?int $minutesRemaining,
    ) {}
}
