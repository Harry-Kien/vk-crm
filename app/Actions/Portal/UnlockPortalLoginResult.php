<?php

namespace App\Actions\Portal;

/**
 * Kết quả của `UnlockPortalLogin::handle()` — xem docblock lớp đó cho toàn bộ lý lẽ.
 *
 * `$minutesRemaining` chỉ có ý nghĩa khi `$ipStillLocked` là `true` — nó là số phút còn lại của
 * chiều IP KHÔNG được xoá vì không NAT-an toàn (tính bằng
 * `PortalLoginThrottle::availableInMinutes()`, lấy giá trị LỚN NHẤT nếu cả hai bước mật khẩu và
 * mã đều còn khoá), để trang gọi hàm này dựng đúng câu `client_users.actions.unlock_login_success_ip_still_locked`.
 */
final class UnlockPortalLoginResult
{
    public function __construct(
        public readonly bool $ipStillLocked,
        public readonly ?int $minutesRemaining,
    ) {}
}
