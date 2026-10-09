<?php

namespace App\Actions\Mcp;

use App\Enums\AiRevocationReason;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;

/**
 * Ngắt kết nối AI từ màn hình (M11 R8, Task 15): quản trị bấm "Thu hồi" (một kết nối) hay "Thu hồi
 * tất cả" trên trang "Kết nối AI", hoặc nhân sự tự thu hồi một kết nối trên trang "Kết nối AI của
 * tôi" [DC:144]. Đường DUY NHẤT từ màn hình tới {@see RevokeAiConnections} — Action đó không hỏi quyền
 * (rà soát Task 6, m3), nên ở đây:
 *
 * 1. **Quyền**: `Gate::forUser($actor)->authorize('revokeAiConnections', $target)` TRƯỚC khi chạm
 *    token nào ({@see UserPolicy::revokeAiConnections()}: `settings.manage`, hoặc chính người đó).
 *    Không thì `AuthorizationException`, không thu hồi gì.
 * 2. Gọi {@see RevokeAiConnections} với lý do {@see AiRevocationReason::RevokedBySelf} khi người bấm
 *    là chính người đó, {@see AiRevocationReason::RevokedByAdmin} khi không; `$clientId` = một kết nối
 *    (token và mã của người này cho client đó, không bao giờ dòng client hay token của người khác),
 *    `null` = mọi kết nối. Transaction, khoá dòng `users`, nhật ký `ai_connections_revoked` (causer là
 *    `$actor`) đều ở Action đó. Không hạ `ai_access`: ngắt kết nối không phải rút quyền.
 *
 * Một `$clientId` không có token nào của người này (đã thu hồi, của người khác, gõ bậy) thu hồi 0
 * dòng và không ghi nhật ký — người gửi id client của người khác lên trang của mình không chạm được
 * gì của người đó.
 */
final class DisconnectAiConnections
{
    public function __construct(private readonly RevokeAiConnections $revoke) {}

    /** @return int tổng số dòng đã thu hồi (access token + refresh token + mã uỷ quyền) */
    public function handle(User $actor, User $target, ?string $clientId = null): int
    {
        Gate::forUser($actor)->authorize('revokeAiConnections', $target);

        $reason = $actor->is($target) ? AiRevocationReason::RevokedBySelf : AiRevocationReason::RevokedByAdmin;

        return $this->revoke->handle($target, $reason, $actor, $clientId);
    }
}
