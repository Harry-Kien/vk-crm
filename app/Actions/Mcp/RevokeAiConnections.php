<?php

namespace App\Actions\Mcp;

use App\Enums\AiAccessMode;
use App\Enums\AiRevocationReason;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

/**
 * Thu hồi MỌI kết nối AI của một nhân sự (M11 R8): đánh `revoked` trên mọi access token, mọi refresh
 * token của các access token đó, và mọi mã uỷ quyền chưa đổi của người này. Request `/mcp` kế tiếp của
 * mọi token cũ nhận 401 (Passport kiểm `revoked` ở mỗi lần xác thực), lần làm mới bị từ chối, và một
 * mã vừa cấp mà client chưa kịp đổi cũng không đổi được nữa — thiếu bước cuối, một lần thu hồi ngay
 * sau khi nhân sự bấm "Đồng ý" sẽ để lọt một cặp token mới.
 *
 * # Gọi ở đâu — TRONG transaction của người gọi
 *
 * R8 đòi các sự kiện sau thu hồi token trong CÙNG transaction [DC:148], [DC:227], để không bao giờ có
 * trạng thái "tài khoản đã tắt mà token còn sống" (hay ngược lại, token đã chết mà thay đổi kia không
 * xảy ra): vô hiệu hoá, đổi vai và đặt mật khẩu mới trên trang sửa nhân sự (`EditUser`), nhân sự tự
 * đổi mật khẩu (`EditProfile`), "Đặt lại 2FA" (`ResetStaffTwoFactor`), xoá nhân sự
 * (`DeleteStaffMember`), quản trị hạ `ai_access` về `off` (`SetUserAiAccess`). `DB::transaction()` ở
 * đây lồng vào transaction của người gọi (savepoint), nên người gọi sụp ở bất kỳ bước nào sau đó thì
 * không token nào bị thu hồi.
 *
 * # Thứ tự trong transaction
 *
 * 1. Khoá dòng `users` của người này (câu ĐẦU TIÊN; `withTrashed()` vì `DeleteStaffMember` gọi ngay
 *    sau khi xoá mềm). Người gọi thường đã giữ khoá đó trong cùng transaction — khoá lại là không đổi.
 * 2. Khi {@see AiRevocationReason::turnsAccessOff()}: hạ `ai_access` về `off` (nếu chưa), một dòng
 *    `ai_access_changed` (`from`, `to`, `reason`) — quản trị phải bật lại đích danh.
 * 3. Thu hồi refresh token, access token, mã uỷ quyền. Refresh token tìm qua `access_token_id` của
 *    MỌI access token của người này (kể cả access token đã hết hạn hay đã thu hồi): lịch
 *    `passport:purge` giữ dòng access token chừng nào refresh token của nó còn sống (Task 3), nên không
 *    có refresh token sống nào mồ côi.
 * 4. Một dòng `ai_connections_revoked` (`reason`, số dòng mỗi loại) — CHỈ khi có ít nhất một dòng bị
 *    thu hồi; một lần đổi mật khẩu của người chưa từng kết nối AI không sinh dòng nhật ký rỗng.
 *
 * `$actor` null (lệnh `vkcrm:reset-2fa`) ghi dòng nhật ký không có causer, tường minh — không đoán
 * từ phiên `web` hay `client`.
 *
 * # Một kết nối (`$clientId`, Task 15)
 *
 * Có `$clientId` thì chỉ thu hồi token và mã của người này CHO client đó: access token
 * `client_id = $clientId`, refresh token của các access token đó, mã uỷ quyền `client_id =
 * $clientId` — một "kết nối" trên trang "Kết nối AI". Dòng nhật ký mang thêm `oauth_client_id`.
 * Token của người khác trên cùng client (client CIMD dùng chung cho cả văn phòng, Task 5) và chính
 * dòng client không bị đụng tới. Không `$clientId` = mọi kết nối, như trên.
 *
 * Action này KHÔNG hỏi quyền: người gọi là một bước nội bộ trong transaction của chính nó, hoặc
 * {@see DisconnectAiConnections} — đường của hai màn hình, hỏi `Gate` trước khi gọi tới đây.
 *
 * Không xoá client OAuth nào: client DCR là của một lần kết nối, client CIMD dùng chung cho mọi nhân
 * sự trên một nền tảng (Task 5); lệnh dọn của Task 3 xoá client không còn token sống.
 *
 * Khe hở còn lại (không đóng được mà không sửa Passport): một lần làm mới đang chạy ĐÚNG lúc thu hồi
 * — league đã đọc refresh token cũ là "chưa thu hồi" trước khi transaction này commit — vẫn cấp một
 * cặp token mới. Với vô hiệu hoá, đổi vai, xoá và tắt AI, `EnsureMcpAccess` vẫn chặn cặp đó ở
 * request kế tiếp (nó đọc trạng thái của NGƯỜI, không của token).
 */
final class RevokeAiConnections
{
    /** @return int tổng số dòng đã thu hồi (access token + refresh token + mã uỷ quyền) */
    public function handle(User $target, AiRevocationReason $reason, ?User $actor = null, ?string $clientId = null): int
    {
        return DB::transaction(function () use ($target, $reason, $actor, $clientId): int {
            /** @var User $locked */
            $locked = User::query()->withTrashed()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

            if ($reason->turnsAccessOff() && $locked->ai_access !== AiAccessMode::Off) {
                $from = $locked->ai_access;

                $locked->forceFill(['ai_access' => AiAccessMode::Off])->save();

                Audit::record('ai_access_changed', $locked, [
                    'from' => $from->value,
                    'to' => AiAccessMode::Off->value,
                    'reason' => $reason->value,
                ], $actor, bySystem: $actor === null);
            }

            $userId = $locked->getKey();

            $forClient = fn ($query) => $clientId === null ? $query : $query->where('client_id', $clientId);

            $refreshTokens = Passport::refreshToken()->newQuery()
                ->whereIn('access_token_id', $forClient(Passport::token()->newQuery()->select('id')->where('user_id', $userId)))
                ->where('revoked', false)
                ->update(['revoked' => true]);

            $accessTokens = $forClient(Passport::token()->newQuery()->where('user_id', $userId))
                ->where('revoked', false)
                ->update(['revoked' => true]);

            $authorizationCodes = $forClient(Passport::authCode()->newQuery()->where('user_id', $userId))
                ->where('revoked', false)
                ->update(['revoked' => true]);

            $total = $accessTokens + $refreshTokens + $authorizationCodes;

            if ($total > 0) {
                Audit::record('ai_connections_revoked', $locked, [
                    'reason' => $reason->value,
                    ...($clientId === null ? [] : ['oauth_client_id' => $clientId]),
                    'access_tokens' => $accessTokens,
                    'refresh_tokens' => $refreshTokens,
                    'authorization_codes' => $authorizationCodes,
                ], $actor, bySystem: $actor === null);
            }

            return $total;
        });
    }
}
