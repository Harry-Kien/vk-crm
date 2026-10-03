<?php

namespace App\Actions\Portal;

/**
 * Kết quả của `UnlockPortalLogin::handle()` — xem docblock lớp đó cho toàn bộ lý lẽ.
 *
 * `$minutesRemaining` chỉ có ý nghĩa khi `$ipStillLocked` là `true` — nó là số phút còn lại của
 * chiều IP KHÔNG được xoá vì không NAT-an toàn (tính bằng
 * `PortalLoginThrottle::availableInMinutes()`, lấy giá trị LỚN NHẤT nếu cả hai bước mật khẩu và
 * mã đều còn khoá), để trang gọi hàm này dựng đúng câu `client_users.actions.unlock_login_success_ip_still_locked`.
 *
 * `$anyAddressLockedMinutes` (final review I2): số phút còn lại lâu nhất của BẤT KỲ khoá địa chỉ
 * nào của guard `client` còn chạm trần sau lần mở khoá, `null` nếu không còn khoá nào. Khách có
 * thể bị khoá chỉ vì lần hỏng của người khác cùng mạng, và khi đó hệ thống không biết khách đang ở
 * địa chỉ nào (xem `App\Actions\Concerns\ClearsNatSafeIpLocks`). Trang gọi hàm này chỉ hứa "đăng
 * nhập lại được ngay" khi `$ipStillLocked` là `false` VÀ giá trị này là `null`; còn lại (chiều IP
 * của chính khách đã sạch nhưng một địa chỉ khác còn khoá) dựng câu
 * `client_users.actions.unlock_login_success_other_address_locked`.
 */
final class UnlockPortalLoginResult
{
    public function __construct(
        public readonly bool $ipStillLocked,
        public readonly ?int $minutesRemaining,
        public readonly ?int $anyAddressLockedMinutes,
    ) {}
}
