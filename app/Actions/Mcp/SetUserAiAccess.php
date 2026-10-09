<?php

namespace App\Actions\Mcp;

use App\Enums\AiAccessMode;
use App\Enums\AiRevocationReason;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Support\Audit;
use App\Support\Mcp\McpAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Quản trị đặt chế độ truy cập qua AI của MỘT nhân sự (M11 R2): `off` / `read` / `read_write`. Đường
 * duy nhất BẬT `users.ai_access` (cột không nằm trong `User::$fillable`); màn hình là trang "Kết nối AI"
 * của Task 15.
 *
 * Trong một transaction, câu ĐẦU TIÊN khoá dòng `users` của người được đặt; mọi kiểm tra đọc bản đã
 * khoá:
 *
 * 1. **Quyền**: `Gate::forUser($actor)->authorize('setAiAccess', …)` — chỉ người có `settings.manage`
 *    ({@see UserPolicy::setAiAccess()}). Không thì `AuthorizationException`, không ghi gì.
 *    Hỏi TRƯỚC các kiểm tra dưới, để người không có quyền không dò được ai đủ điều kiện.
 * 2. **Chỉ chiều BẬT (`read`, `read_write`) bị kiểm điều kiện**, mỗi lỗi một `ValidationException` gắn
 *    vào ô `ai_access`, câu tiếng Việt:
 *    - tài khoản đang bị vô hiệu hoá;
 *    - người đó không giữ được quyền AI — thiếu `matter.view`, ví dụ kế toán
 *      ({@see McpAccess::canHold()}): mọi tool đọc nội dung vụ việc [DC:114].
 *    Chiều TẮT luôn được: rút quyền chỉ thu hẹp.
 * 3. Đặt lại đúng chế độ đang có: không ghi, không audit.
 * 4. Ghi chế độ mới, một dòng `ai_access_changed` (`from`, `to`), causer là `$actor` tường minh
 *    (SPEC §10.6: đổi phân quyền là sự kiện bắt buộc ghi).
 * 5. Hạ về `off` thì thu hồi mọi kết nối của người đó trong CÙNG transaction
 *    ({@see RevokeAiConnections}, R8). Hạ `read_write` về `read` thì KHÔNG thu hồi: token cũ vẫn đọc
 *    được, còn bốn tool ghi bị từ chối ngay ở request kế tiếp vì quyền ghi được kiểm ở mỗi request
 *    ({@see McpAccess::canWrite()}, R13).
 *
 * Không gửi thư, không thông báo: đây là cấu hình nội bộ.
 */
final class SetUserAiAccess
{
    public function handle(User $target, AiAccessMode $mode, User $actor): User
    {
        return DB::transaction(function () use ($target, $mode, $actor): User {
            /** @var User $locked */
            $locked = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

            Gate::forUser($actor)->authorize('setAiAccess', $locked);

            if ($mode !== AiAccessMode::Off) {
                if (! $locked->is_active) {
                    throw ValidationException::withMessages(['ai_access' => __('ai_access.validation.inactive')]);
                }

                if (! McpAccess::canHold($locked)) {
                    throw ValidationException::withMessages(['ai_access' => __('ai_access.validation.needs_matter_view')]);
                }
            }

            $from = $locked->ai_access;

            if ($from === $mode) {
                return $locked;
            }

            $locked->forceFill(['ai_access' => $mode])->save();

            Audit::record('ai_access_changed', $locked, [
                'from' => $from->value,
                'to' => $mode->value,
            ], $actor);

            if ($mode === AiAccessMode::Off) {
                app(RevokeAiConnections::class)->handle($locked, AiRevocationReason::AiAccessOff, $actor);
            }

            return $locked;
        });
    }
}
