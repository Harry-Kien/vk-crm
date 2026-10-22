<?php

namespace App\Actions\User;

use App\Actions\Push\ForgetPushDevice;
use App\Http\Middleware\EndDisabledStaffSessions;
use App\Http\Middleware\RejectStaffSessionsFromBeforeReset;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cắt mọi lối vào ĐANG MỞ của một nhân sự vừa mất quyền vào — MỘT định nghĩa cho ba đường: "Khoá truy cập
 * ngay" ({@see SuspendStaffAccess}), tắt "Đang hoạt động" trên trang sửa nhân sự (đường nghỉ việc thường) và
 * xoá nhân sự ({@see DeleteStaffMember}). Rà soát toàn hệ thống 2026-10-22 (làn security): trước bản này chỉ
 * đường thứ nhất làm đủ; hai đường kia dựa vào {@see EndDisabledStaffSessions}, vốn chỉ đăng xuất ở request
 * KẾ TIẾP khi tài khoản còn tắt — bật lại (bấm nhầm, người đó quay lại làm, "Khôi phục" người đã xoá) trước
 * request đó trả lại nguyên phiên cũ, và điện thoại cũ lại nhận thông báo đẩy về hồ sơ.
 *
 * Ba việc, gọi TRONG transaction của người gọi, trên dòng `users` người gọi đã khoá:
 *  1. tăng `session_epoch` ({@see RejectStaffSessionsFromBeforeReset}) — phiên cũ chết ở request kế tiếp
 *     của nó, kể cả khi tài khoản đã được bật lại;
 *  2. đổi `remember_token` — cookie "ghi nhớ đăng nhập" cũ (nếu còn) hết hiệu lực;
 *  3. SAU commit: gỡ mọi máy nhận thông báo đẩy ({@see ForgetPushDevice::all()}, lý do `$pushReason`) — một
 *     máy nằm im không gửi request nào nên bước 1 không tới được nó. Lần lưu rollback thì máy còn nguyên.
 *
 * Kết nối AI không ở đây: mỗi đường tự gọi `RevokeAiConnections` với lý do của mình.
 */
final class EndStaffSessions
{
    public function handle(User $locked, ?User $actor, string $pushReason): void
    {
        $locked->forceFill([
            'session_epoch' => (int) $locked->session_epoch + 1,
            'remember_token' => Str::random(60),
        ])->save();

        DB::afterCommit(fn () => app(ForgetPushDevice::class)->all($locked, $actor, $pushReason));
    }
}
