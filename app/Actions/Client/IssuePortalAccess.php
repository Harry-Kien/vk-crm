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
 *
 * **Fix round 1 (finding Important 1).** Bản trước ghi audit + dispatch job VÔ ĐIỀU KIỆN, kể cả
 * khi tài khoản `is_active = false` hoặc khách đã bị xoá mềm — `SendPortalActivationMail` tự kiểm
 * `stillEligibleForActivation()` và âm thầm không làm gì, nhưng phía gọi (nút "Cấp lại mật khẩu",
 * `CreateClientUser::afterCreate()`) không có cách nào biết, nên luôn hiện toast thành công dù
 * không ai nhận được gì — một tài khoản mới tạo với `is_active` tắt (hoặc bật lại sau) không bao
 * giờ có mật khẩu để đăng nhập, và không ai biết trừ khi đọc thẳng bảng `jobs`. Sửa: hỏi lại ĐÚNG
 * điều kiện đó ({@see self::isEligible()}, dùng LẠI bởi `SendPortalActivationMail::
 * stillEligibleForActivation()` — một luật, một chỗ) TRƯỚC khi ghi audit/dispatch, và trả về
 * {@see IssuePortalAccessResult} để nơi gọi chọn đúng câu toast thay vì đoán.
 */
class IssuePortalAccess
{
    /**
     * @param  bool  $reissue  Việc sau gộp M6 (làn fu, N1): `true` khi đây là lần CẤP LẠI — nút "Cấp
     *                         lại mật khẩu", đổi email, bật lại một tài khoản chưa từng kích hoạt
     *                         (`EditClientUser`); `false` cho lần tạo tài khoản
     *                         (`CreateClientUser`). Chỉ đổi câu chữ của thư `client.activation`
     *                         (tiêu đề, câu mở, câu "mật khẩu trước không còn dùng được"); không đổi
     *                         điều kiện cấp, audit hay cách sinh mật khẩu.
     */
    public function handle(ClientUser $account, User $actor, bool $reissue = false): IssuePortalAccessResult
    {
        return DB::transaction(function () use ($account, $actor, $reissue): IssuePortalAccessResult {
            $fresh = ClientUser::query()->lockForUpdate()->findOrFail($account->getKey());

            if (! self::isEligible($fresh)) {
                return new IssuePortalAccessResult($fresh, issued: false);
            }

            Audit::record('client_portal_access_issued', $fresh, [
                'client_id' => $fresh->client_id,
            ], $actor);

            SendPortalActivationMail::dispatch($fresh->getKey(), $actor->getKey(), $reissue)->afterCommit();

            return new IssuePortalAccessResult($fresh, issued: true);
        });
    }

    /**
     * Một luật DUY NHẤT: `is_active` VÀ khách chưa xoá mềm (ngoại lệ có chủ đích của R12 — không
     * đòi `activated_at`, xem docblock `App\Actions\Notification\ResolveClientRecipients`). Dùng
     * bởi CẢ Action này (trước khi audit/dispatch) LẪN `App\Jobs\SendPortalActivationMail::
     * stillEligibleForActivation()` (đọc lại lúc job THẬT SỰ chạy, dưới khoá dòng) — hai điểm đọc,
     * một định nghĩa, để một lần sửa sau này (thêm điều kiện thứ ba) không có hai chỗ để quên.
     */
    public static function isEligible(ClientUser $account): bool
    {
        return $account->is_active && $account->client()->exists();
    }
}
