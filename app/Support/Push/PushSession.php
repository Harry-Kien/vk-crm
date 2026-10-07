<?php

namespace App\Support\Push;

use App\Http\Controllers\Pwa\PushSubscriptionController;

/**
 * M12 R8/R9 — tên hai khoá phiên của thông báo đẩy, theo GUARD (`web` của `/admin`, `client` của
 * `/portal`). MỘT định nghĩa cho nơi ghi ({@see PushSubscriptionController}, trang "Thông báo trên
 * điện thoại") và nơi đọc (thẻ `register.js`; listener đăng xuất của Task 6).
 *
 * Vì sao theo guard (phán quyết (c) của controller, brief Task 6 "Bẫy khoá phiên"): mỗi service
 * worker (scope `/admin`, scope `/portal`) có đăng ký push RIÊNG, tức endpoint riêng; một trình
 * duyệt vào cả hai panel có HAI endpoint nhưng hai panel chung MỘT phiên (cùng cookie,
 * `config/session.php` path `/`). Một khoá chung bị panel kiểm sau cùng ghi đè → đăng xuất panel kia
 * tìm nhầm endpoint và bỏ sót thiết bị của chính nó.
 *
 *  - {@see self::endpointKey()} — endpoint của TRÌNH DUYỆT NÀY, chỉ khi dòng của nó thuộc đúng
 *    người đang đăng nhập ở guard đó (lượt kiểm thấy "của mình", hoặc vừa bấm Bật). Đăng xuất gỡ
 *    đúng dòng này (R9). Không bao giờ in ra HTML: trang thiết bị so nó ở máy chủ.
 *  - {@see self::checkedKey()} — lượt kiểm `sync=1` đã chạy trong PHIÊN MÁY CHỦ này. Thay cho
 *    `sessionStorage` của kế hoạch: `sessionStorage` sống theo THẺ trình duyệt, nên hết phiên rồi
 *    đăng nhập lại trong cùng thẻ sẽ không kiểm lại, phiên mới không có {@see self::endpointKey()}
 *    và lần đăng xuất sau không gỡ máy này — push của người đó tiếp tục tới máy dùng chung. Đăng
 *    xuất `invalidate()` cả phiên, nên người đăng nhập kế tiếp luôn được kiểm lại.
 */
final class PushSession
{
    public static function endpointKey(string $guard): string
    {
        return 'push.endpoint.'.$guard;
    }

    public static function checkedKey(string $guard): string
    {
        return 'push.checked.'.$guard;
    }
}
