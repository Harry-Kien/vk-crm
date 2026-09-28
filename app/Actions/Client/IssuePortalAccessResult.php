<?php

namespace App\Actions\Client;

use App\Models\ClientUser;

/**
 * Kết quả của {@see IssuePortalAccess::handle()} — fix round 1 (finding Important 1).
 *
 * Bản trước trả thẳng `ClientUser` và LUÔN ghi audit + dispatch job, kể cả khi tài khoản
 * `is_active = false` hoặc khách đã bị xoá mềm — trang "Cấp lại mật khẩu" vì vậy luôn hiện toast
 * thành công dù `SendPortalActivationMail` âm thầm không làm gì (chính job đó tự kiểm
 * `stillEligibleForActivation()`, nhưng KHÔNG có gì đọc lại quyết định đó ở phía gọi). Một giá trị
 * trả về boolean-only sẽ không đủ: nơi gọi (trang Sửa/Tạo tài khoản) cần CẢ bản ghi mới nhất lẫn
 * việc có audit/dispatch hay không, để chọn đúng câu toast — không đoán.
 */
final class IssuePortalAccessResult
{
    public function __construct(
        public readonly ClientUser $account,
        public readonly bool $issued,
    ) {}
}
