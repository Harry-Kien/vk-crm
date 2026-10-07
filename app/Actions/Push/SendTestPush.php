<?php

namespace App\Actions\Push;

use App\Enums\PushTopic;
use App\Models\ClientUser;
use App\Models\User;

/**
 * Nút "Gửi thử" của trang "Thông báo trên điện thoại" (M12 R8; chuyển từ Task 5 sang cuối Task 7 theo
 * phán quyết (b) của controller). Xếp MỘT thông báo đẩy chủ đề {@see PushTopic::Test} cho CHÍNH người
 * bấm — tới mọi máy đã bật của người đó, không ai khác — qua đúng đường {@see SendPushAlert}: nội dung
 * vẫn theo luật nội dung tối thiểu R11 (phán quyết 4: không ngoại lệ nào, kể cả "Gửi thử"), job vẫn
 * nằm trên hàng `push` và để lại dòng nhật ký như mọi lần đẩy.
 *
 * Gọi từ `App\Http\Controllers\Pwa\PushSubscriptionController::test()` (`POST {panel}/push/test`, cùng
 * throttle 10 lượt/phút và — ở admin — cùng cổng 2FA với hai route đăng ký); máy chủ chưa có khoá VAPID
 * thì route trả 404 trước khi tới đây.
 */
class SendTestPush
{
    public function __construct(private readonly SendPushAlert $sendPushAlert) {}

    /**
     * @return int số máy sẽ nhận (0 = người này chưa bật máy nào, không gì được xếp)
     */
    public function handle(User|ClientUser $owner): int
    {
        $devices = $owner->pushSubscriptions()->count();

        if ($devices === 0) {
            return 0;
        }

        return $this->sendPushAlert->handle([$owner], PushTopic::Test) === 1 ? $devices : 0;
    }
}
