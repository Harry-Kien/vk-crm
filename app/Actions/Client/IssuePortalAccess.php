<?php

namespace App\Actions\Client;

use App\Jobs\SendPortalActivationMail;
use App\Models\ClientUser;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Cấp (hoặc cấp LẠI) quyền truy cập cổng khách hàng — SPEC §9 mẫu `client.activation`, task 3.
 *
 * **Lỗ hổng lấp ở task này (chép nguyên văn brief):** trước bản sửa này, tài khoản portal được
 * tạo bằng cách một luật sư GÕ TAY mật khẩu vào form rồi đọc cho khách qua điện thoại — không có
 * thư kích hoạt nào cả. `ClientUserForm` đã bỏ hẳn ô mật khẩu; `CreateClientUser`/`EditClientUser`
 * gọi Action này để cấp quyền, và Action này là nơi DUY NHẤT một tài khoản cổng có được một mật
 * khẩu mà khách có thể đọc.
 *
 * **Vì sao Action này KHÔNG tự sinh mật khẩu.** Xem docblock `App\Jobs\SendPortalActivationMail`:
 * mật khẩu tạm không bao giờ được nằm trong một thuộc tính sẽ bị serialize vào bảng `jobs` (một
 * Mailable/Job xếp hàng serialize MỌI thuộc tính công khai của nó). Action này vì vậy chỉ ghi audit
 * và DISPATCH một job — job đó mới là nơi sinh mật khẩu, ghi hash, và gửi thư, tất cả trong CÙNG
 * một lần chạy (không có gì để mà serialize giữa hai bước).
 *
 * **`->afterCommit()`, không `event(...)` kiểu `ShouldDispatchAfterCommit`.** Action này không
 * dựng ra một sự kiện domain nào (không có "PortalAccessIssued" nào khác lắng nghe) — job xếp
 * hàng THẲNG là đủ, và `SendPortalActivationMail::dispatch(...)->afterCommit()` (helper tĩnh của
 * trait `Dispatchable`) cho đúng bảo đảm "sau commit" mà R2 đòi, mà không cần thêm một lớp Event
 * chỉ để có đúng MỘT listener.
 */
class IssuePortalAccess
{
    public function handle(ClientUser $account, User $actor): ClientUser
    {
        return DB::transaction(function () use ($account, $actor): ClientUser {
            $fresh = ClientUser::query()->lockForUpdate()->findOrFail($account->getKey());

            Audit::record('client_portal_access_issued', $fresh, [
                'client_id' => $fresh->client_id,
            ], $actor);

            SendPortalActivationMail::dispatch($fresh->getKey(), $actor->getKey())->afterCommit();

            return $fresh;
        });
    }
}
