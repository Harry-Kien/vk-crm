<?php

namespace App\Actions\Push;

use App\Http\Controllers\Pwa\PushSubscriptionController;
use App\Listeners\ForgetPushDeviceOnLogout;
use App\Models\ClientUser;
use App\Models\User;
use App\Support\Audit;
use App\Support\Push\PushSession;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * M12 R8/R9/R14 — gỡ thiết bị nhận thông báo đẩy của MỘT người. Mọi lối gỡ đều đi qua quan hệ
 * `$owner->pushSubscriptions()` của chính người đó: một id hay một endpoint của người khác không
 * khớp dòng nào, và người gọi trả 404 (SPEC §10.10) — dòng của người kia đứng nguyên.
 *
 *  - {@see self::onLogout()} — đăng xuất và cắt phiên (R9, Task 6): gỡ đúng máy đang đăng xuất,
 *    endpoint lấy từ phiên theo guard ({@see PushSession}); gọi từ {@see ForgetPushDeviceOnLogout}.
 *  - {@see self::byEndpoint()} — `DELETE …/push/subscriptions` ({@see PushSubscriptionController}).
 *    Hôm nay `register.js` không gọi route này (nhánh khoá lệch R7 chỉ `unsubscribe()` tại chỗ);
 *    route giữ cho người gọi về sau và cho việc gỡ một endpoint của chính mình bằng tay.
 *  - {@see self::byId()} — nút "Gỡ" của từng máy trên trang "Thông báo trên điện thoại".
 *  - {@see self::all()} — nút "Gỡ mọi thiết bị" của chính người đó (R14: nút tắt hết; email không
 *    tắt được), và ba lối VĂN PHÒNG gỡ thay (việc sau gộp M12, làn fu4) — mỗi lối mang một lý do:
 *    {@see self::REASON_EMAIL_CHANGED} (`UpdatePortalAccount`, đổi email cổng),
 *    {@see self::REASON_TWO_FACTOR_RESET} (`ResetStaffTwoFactor`, "Đặt lại 2FA"),
 *    {@see self::REASON_OFFICE} (nút "Gỡ mọi máy nhận thông báo" trên trang tài khoản cổng).
 *
 * Mỗi dòng gỡ ghi một audit `push_device_removed` mang ĐÚNG `device_label` — không bao giờ endpoint
 * (R8). Người tự gỡ máy của mình: chủ dòng là cả chủ thể lẫn người gây ra, `properties` chỉ có
 * `device_label`. Văn phòng gỡ thay: chủ thể vẫn là chủ dòng, người gây ra là nhân sự đã bấm (lệnh
 * console: không ai), và `properties` thêm `reason`.
 *
 * Không chạm thẳng bảng đăng ký (mọi truy vấn qua quan hệ của `$owner`); vẫn nằm trong danh sách
 * cho phép của `PushSubscriptionAccessTest` vì lối đăng xuất gỡ theo endpoint trong phiên ở đây.
 */
class ForgetPushDevice
{
    /** Văn phòng đổi email cổng: địa chỉ mới là một người giữ mới, chưa xác minh (R10, R12). */
    public const REASON_EMAIL_CHANGED = 'email_changed';

    /** "Đặt lại 2FA": nhân sự mất điện thoại — máy đó thôi đổ chuông (SPEC §10.7). */
    public const REASON_TWO_FACTOR_RESET = 'two_factor_reset';

    /** Khách gọi văn phòng báo mất máy; nhân sự bấm nút trên trang tài khoản cổng. */
    public const REASON_OFFICE = 'office';

    /** "Khoá truy cập ngay" (`SuspendStaffAccess`, làn fb mục A5): nhân sự nghỉ đột xuất hay bị nghi lộ dữ liệu. */
    public const REASON_STAFF_SUSPENDED = 'staff_suspended';

    /**
     * R9 — `$owner` vừa đăng xuất khỏi `$guard` trên trình duyệt mang phiên `$session`: gỡ máy của
     * trình duyệt này, nếu phiên còn nhớ endpoint của nó VÀ dòng đó thuộc đúng `$owner`.
     *
     * Chạy TRƯỚC khi phiên bị xoá — mọi đường đăng xuất gọi `logout()`/`logoutCurrentDevice()` rồi
     * mới `invalidate()`/`flush()`. Luôn xoá cả hai khoá phiên của `$guard`, kể cả khi không gỡ được
     * dòng nào: đường "Đặt lại 2FA" (`RejectStaffSessionsFromBeforeReset`) KHÔNG huỷ phiên, và người
     * đăng nhập lại trong cùng phiên phải được kiểm lại từ đầu (lượt `sync=1` mới) — không thì phiên
     * mới không có endpoint và lần đăng xuất sau không gỡ máy này.
     *
     * Chỉ guard đang đăng xuất: hai panel chung MỘT cookie phiên (`config/session.php` path `/`), nên
     * đăng xuất một panel huỷ phiên của cả hai, nhưng đăng ký push của panel kia (service worker
     * khác, endpoint khác, chủ khác) đứng nguyên — chủ của nó chưa bấm đăng xuất.
     *
     * Không bao giờ ném: dọn thiết bị là vệ sinh, không phải điều kiện để ra khỏi phiên. Một lỗi CSDL
     * ném ra từ đây biến nút Đăng xuất thành trang lỗi 500, và phiên KHÔNG bị huỷ. Nhật ký chỉ mang
     * tên lớp ngoại lệ, guard và chủ máy: thông điệp của `QueryException` chứa câu SQL kèm endpoint
     * (một URL mang quyền gửi, R8). Máy không gỡ được thì vẫn nhận push của `$owner` cho tới khi người
     * đó gỡ nó ở trang "Thông báo trên điện thoại", hoặc lượt dọn hằng ngày bỏ nó sau 180 ngày không
     * mở ứng dụng.
     *
     * @return bool `true` khi đã gỡ máy của trình duyệt này
     */
    public function onLogout(User|ClientUser $owner, string $guard, Session $session): bool
    {
        $endpoint = $session->pull(PushSession::endpointKey($guard));
        $session->forget(PushSession::checkedKey($guard));

        // Khoá chỉ được ghi sau khi endpoint đã qua luật của `RegisterPushDevice`; vẫn kiểm hình dạng
        // (không ném) để một giá trị lạ không bao giờ vào câu WHERE trên cột `ascii`.
        if (! is_string($endpoint) || Validator::make(
            ['endpoint' => $endpoint],
            ['endpoint' => RegisterPushDevice::endpointRule(knownHost: false)],
        )->fails()) {
            return false;
        }

        try {
            return $this->forget($owner, $owner->pushSubscriptions()->where('endpoint', $endpoint)) > 0;
        } catch (Throwable $exception) {
            Log::warning('Không gỡ được thiết bị nhận thông báo đẩy lúc đăng xuất; người dùng vẫn được đăng xuất.', [
                'guard' => $guard,
                'owner' => $owner->getMorphClass().':'.$owner->getKey(),
                'exception' => $exception::class,
            ]);

            return false;
        }
    }

    /**
     * @return bool `false` khi endpoint không phải của `$owner` (người gọi trả 404)
     */
    public function byEndpoint(User|ClientUser $owner, mixed $endpoint): bool
    {
        // Endpoint vẫn phải đúng hình dạng (ASCII in được, đủ ngắn) trước khi vào câu WHERE: cột là
        // `ascii`, và câu so sánh với một chuỗi ngoài ASCII là lỗi CSDL kèm giá trị trong log.
        Validator::make(
            ['endpoint' => $endpoint],
            ['endpoint' => RegisterPushDevice::endpointRule(knownHost: false)],
            RegisterPushDevice::endpointMessages(),
        )->validate();

        return $this->forget($owner, $owner->pushSubscriptions()->where('endpoint', $endpoint)) > 0;
    }

    /**
     * @return bool `false` khi id không phải một máy của `$owner` (người gọi trả 404)
     */
    public function byId(User|ClientUser $owner, int $id): bool
    {
        return $this->forget($owner, $owner->pushSubscriptions()->whereKey($id)) > 0;
    }

    /**
     * Gỡ mọi máy của `$owner`. Không `$reason`: chính chủ bấm "Gỡ mọi thiết bị". Có `$reason` (một
     * hằng `REASON_*`): văn phòng gỡ thay — `$by` là nhân sự đã bấm, `null` khi lệnh console gây ra
     * (dòng nhật ký không người gây ra, kể cả khi có ai đang đăng nhập).
     *
     * Người gọi chạy nó SAU commit của việc đã quyết định gỡ (đổi email, đặt lại 2FA): việc đó
     * rollback thì máy còn nguyên.
     *
     * @return int số máy đã gỡ
     */
    public function all(User|ClientUser $owner, ?User $by = null, ?string $reason = null): int
    {
        return $this->forget($owner, $owner->pushSubscriptions(), $by, $reason);
    }

    /**
     * @param  MorphMany|Builder  $devices  dòng của `$owner`, đã lọc
     */
    private function forget(User|ClientUser $owner, MorphMany|Builder $devices, ?User $by = null, ?string $reason = null): int
    {
        return DB::transaction(function () use ($owner, $devices, $by, $reason): int {
            $rows = $devices->lockForUpdate()->get(['id', 'device_label']);

            foreach ($rows as $row) {
                $row->delete();

                if ($reason === null) {
                    Audit::record('push_device_removed', $owner, ['device_label' => $row->device_label], $owner);
                } else {
                    Audit::record(
                        'push_device_removed',
                        $owner,
                        ['device_label' => $row->device_label, 'reason' => $reason],
                        $by,
                        bySystem: $by === null,
                    );
                }
            }

            return $rows->count();
        });
    }
}
