<?php

namespace App\Actions\Push;

use App\Http\Controllers\Pwa\PushSubscriptionController;
use App\Models\ClientUser;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * M12 R8/R9/R14 — gỡ thiết bị nhận thông báo đẩy của MỘT người. Mọi lối gỡ đều đi qua quan hệ
 * `$owner->pushSubscriptions()` của chính người đó: một id hay một endpoint của người khác không
 * khớp dòng nào, và người gọi trả 404 (SPEC §10.10) — dòng của người kia đứng nguyên.
 *
 *  - {@see self::byEndpoint()} — `DELETE …/push/subscriptions` ({@see PushSubscriptionController}),
 *    trình duyệt tự gỡ máy của nó; Task 6 thêm lối đăng xuất (endpoint lấy từ phiên theo guard).
 *  - {@see self::byId()} — nút "Gỡ" của từng máy trên trang "Thông báo trên điện thoại".
 *  - {@see self::all()} — nút "Gỡ mọi thiết bị" (R14: nút tắt hết; email không tắt được).
 *
 * Mỗi dòng gỡ ghi một audit `push_device_removed` mang ĐÚNG `device_label` — không bao giờ endpoint
 * (R8). Người gỡ luôn là chủ dòng (`$owner` là cả chủ thể lẫn người gây ra).
 *
 * Không chạm thẳng bảng đăng ký (mọi truy vấn qua quan hệ của `$owner`); vẫn nằm trong danh sách
 * cho phép của `PushSubscriptionAccessTest` vì Task 6 dọn theo endpoint trong phiên ở đây.
 */
class ForgetPushDevice
{
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

    /** @return int số máy đã gỡ */
    public function all(User|ClientUser $owner): int
    {
        return $this->forget($owner, $owner->pushSubscriptions());
    }

    /**
     * @param  MorphMany|Builder  $devices  dòng của `$owner`, đã lọc
     */
    private function forget(User|ClientUser $owner, MorphMany|Builder $devices): int
    {
        return DB::transaction(function () use ($owner, $devices): int {
            $rows = $devices->lockForUpdate()->get(['id', 'device_label']);

            foreach ($rows as $row) {
                $row->delete();

                Audit::record('push_device_removed', $owner, ['device_label' => $row->device_label], $owner);
            }

            return $rows->count();
        });
    }
}
