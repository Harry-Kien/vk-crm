<?php

namespace App\Actions\Notification;

use App\Models\ClientUser;
use App\Models\Matter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * M6.5 R12: người nhận thư cho KHÁCH về một hồ sơ chỉ là tài khoản cổng đang `is_active`, đã
 * `activated_at` (khách tự tay đổi mật khẩu lần đầu — bằng chứng duy nhất người này làm chủ hộp
 * thư), và thuộc một khách hàng CHƯA bị văn phòng xoá mềm.
 *
 * **Task 3 (chép nguyên văn brief): "tách luật này ra MỘT chỗ dùng chung … và cho
 * `NotifyClientOfStageUpdate` gọi lại — không chép điều kiện sang ba listener mới."** Trước bản
 * sửa này, luật ba điều kiện (is_active + activated_at + client còn tồn tại) nằm PRIVATE trong
 * `NotifyClientOfStageUpdate::eligibleRecipientsQuery()`. Ba mẫu thư mới của Task 3
 * (`client.document_published`, `client.document_rejected`, và phần "nhân sự đặt lại mật khẩu"
 * của `client.activation`) cần ĐÚNG luật đó — chép nó ba lần là chép một luật bảo mật ba lần, và
 * một lần sửa (ví dụ thêm điều kiện thứ tư sau này) có ba chỗ để quên.
 *
 * **Ngoại lệ CÓ CHỦ Ý, không nằm ở đây:** `client.activation` gửi tới CHÍNH tài khoản vừa được
 * cấp/cấp lại quyền truy cập, tài khoản đó `activated_at` CÒN NULL (thư này là thứ chứng minh hộp
 * thư, không phải phần thưởng cho một hộp thư đã chứng minh rồi) — xem
 * `App\Jobs\SendPortalActivationMail::stillEligibleForActivation()`, nơi lặp lại HAI trong BA điều
 * kiện ở đây (is_active + client còn tồn tại) nhưng KHÔNG điều kiện thứ ba.
 */
class ResolveClientRecipients
{
    /** @return Collection<int, ClientUser> */
    public function recipientsFor(int $clientId): Collection
    {
        return $this->eligibleQuery($clientId)->get();
    }

    public function hasEligibleRecipient(int $clientId): bool
    {
        return $this->eligibleQuery($clientId)->exists();
    }

    /**
     * Bước hai của mọi thư cho khách về MỘT vụ việc (gộp M7 vào `main`): trong những tài khoản đã
     * qua luật R12 ở trên, chỉ giữ người mà vụ việc còn nằm trên cổng của CHÍNH họ — hỏi bằng định
     * nghĩa cổng, `Gate::forUser($account)->allows('view', $matter)` (`MatterPolicy::view` nhánh
     * khách: năm điều kiện của `releasedToPortal()`, gồm "chưa hết hạn tra cứu" của M7 Task 5 (R4),
     * cộng tầng truy vấn `visibleToPortal()`), chứ không thêm một định nghĩa thứ ba. Cờ
     * `is_published_to_portal` một mình không đủ: một vụ đã kết thúc và đã quá `client_access_until`
     * rời cổng mà cờ giữ nguyên, tài khoản khách còn hoạt động nhờ một vụ khác, và thư đi kèm liên
     * kết tới một trang trả 404.
     *
     * `$matter` phải là bản ghi ĐẦY ĐỦ đọc tươi (policy đọc `client_id`, `is_published_to_portal`,
     * `deleted_at` và dòng lưu trữ) — không phải một bản chọn vài cột. Dùng bởi
     * `NotifyClientOfStageUpdate`, `NotifyClientOfDocumentPublished`,
     * `NotifyClientOfChecklistItemRejected` và `NotifyClientOfRequestAnswered`, nên lời gửi thật,
     * nút "Gửi lại" của nhật ký thư và câu trên màn hình hỏi cùng một câu.
     *
     * @param  Collection<int, ClientUser>  $accounts
     * @return Collection<int, ClientUser>
     */
    public function onPortal(Matter $matter, Collection $accounts): Collection
    {
        return $accounts
            ->filter(fn (ClientUser $account): bool => Gate::forUser($account)->allows('view', $matter))
            ->values();
    }

    /**
     * `is_active` (tài khoản văn phòng đã chủ động khoá thì không nhận — gửi vào đó mâu thuẫn với
     * chính quyết định khoá).
     *
     * `whereNotNull('activated_at')` (R12, phát hiện `intake/intake-04`, `intake/intake-05`):
     * activated_at chỉ được hệ thống ghi khi khách TỰ TAY đổi mật khẩu lần đầu thành công
     * (`App\Filament\Portal\Pages\Auth\ChangePassword::changePassword()`) — bằng chứng DUY NHẤT
     * người nhận làm chủ hộp thư đã gõ.
     *
     * `whereHas('client')` (rà soát Task 2): `Client::delete()` không tự tắt các `client_users`
     * của khách đó — `ClientUser::client()` mang theo `SoftDeletingScope` của `Client`, nên
     * `whereHas('client')` tự loại đúng những tài khoản mà quan hệ đó rơi về `null`.
     */
    public function eligibleQuery(int $clientId): Builder
    {
        return ClientUser::query()
            ->where('client_id', $clientId)
            ->where('is_active', true)
            ->whereNotNull('activated_at')
            ->whereHas('client');
    }
}
