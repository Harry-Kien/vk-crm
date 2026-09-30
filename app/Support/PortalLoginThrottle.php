<?php

namespace App\Support;

use App\Models\ClientUser;

/**
 * Giới hạn tần suất đăng nhập CỔNG KHÁCH HÀNG (guard `client`) — SPEC §10.3.
 *
 * Toàn bộ luật, con số và lý lẽ nằm ở {@see LoginThrottle}; lớp này chỉ khai hai điều riêng của
 * cổng khách: model tài khoản ({@see ClientUser}) và tiền tố khoá `portal-login`. Tiền tố đó là
 * chuỗi khoá cache có từ M5 (`portal-login-account:`, `portal-login-email:`, `portal-login-ip:`,
 * `portal-login-code-account:`, `portal-login-code-ip:`) và cố ý giữ nguyên khi luật được tổng
 * quát hoá cho nhân sự ở M8 Task 3 ({@see StaffLoginThrottle}).
 */
final class PortalLoginThrottle extends LoginThrottle
{
    protected static function accountModel(): string
    {
        return ClientUser::class;
    }

    protected static function keyPrefix(): string
    {
        return 'portal-login';
    }
}
