<?php

namespace App\Actions\User;

use App\Actions\Concerns\ClearsNatSafeIpLocks;
use App\Actions\Portal\UnlockPortalLogin;
use App\Models\User;
use App\Support\Audit;
use App\Support\StaffLoginThrottle;
use Illuminate\Support\Facades\Gate;

/**
 * "Mở khoá đăng nhập" của NHÂN SỰ (SPEC §10.3, M8 Task 3) — bản của cổng `/admin` cho
 * {@see UnlockPortalLogin}. Một quản trị viên xoá khoá đếm thay một đồng
 * nghiệp bị khoá tạm sau 5 lần sai (mật khẩu, mã TOTP hoặc mã khôi phục).
 *
 * Xoá CẢ HAI chiều tài khoản (bước mật khẩu và bước mã) trong một lần gọi; chiều ĐỊA CHỈ MẠNG chỉ
 * được xoá khi NAT-an toàn — luật đọc ở {@see ClearsNatSafeIpLocks} (đọc dòng `login_failed` guard
 * `web`, kể cả dòng `step = code` mà `App\Filament\Admin\Pages\Auth\Login` ghi ở bước mã). Chiều IP
 * còn khoá thì kết quả nói rõ kèm số phút còn lại, để câu trả lời không hứa suông. Final review I2:
 * kết quả còn nói một địa chỉ KHÁC có còn khoá không (`anyAddressLockedMinutes`) — nhân sự bị khoá
 * chỉ vì lần hỏng của đồng nghiệp cùng NAT văn phòng không có dòng nào của riêng mình, nên trước
 * bản sửa họ nhận câu "đăng nhập lại được ngay" mà vẫn bị chặn.
 *
 * **Chỉ quản trị viên** (`UserPolicy::unlockLogin()`, cùng cổng `settings.manage` với mọi thao tác
 * quản trị nhân sự). Gate được hỏi lại BÊN TRONG Action — Action có thể được gọi từ nơi khác ngoài
 * nút ở `EditUser` — theo thành ngữ phòng thủ hai lớp của dự án.
 *
 * Ghi audit `staff_login_unlocked` (chủ thể = người được mở khoá, causer = admin), kèm
 * `ip_still_locked`. Không ghi bất kỳ bí mật nào.
 */
final class UnlockStaffLogin
{
    use ClearsNatSafeIpLocks;

    public function handle(User $account, User $actor): UnlockStaffLoginResult
    {
        Gate::forUser($actor)->authorize('unlockLogin', $account);

        StaffLoginThrottle::clearAccountLocks($account);

        [$ipStillLocked, $minutes, $anyAddressLockedMinutes] = $this->clearSafeIpDimensions($account, StaffLoginThrottle::class, 'web');

        Audit::record('staff_login_unlocked', $account, [
            'guard' => 'web',
            'ip_still_locked' => $ipStillLocked,
        ], $actor);

        return new UnlockStaffLoginResult($ipStillLocked, $minutes, $anyAddressLockedMinutes);
    }
}
