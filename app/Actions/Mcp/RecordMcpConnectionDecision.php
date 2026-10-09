<?php

namespace App\Actions\Mcp;

use App\Enums\McpAccessRefusal;
use App\Enums\McpPlatform;
use App\Models\User;
use App\Support\Audit;
use App\Support\Mcp\ConsentRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * Nhật ký của màn hình đồng ý OAuth (M11 Task 4, R8 "ghi cả kết nối"): mỗi lần một kết nối AI được
 * đồng ý (`mcp_connection_authorized`) hay không được (`mcp_connection_denied`), đúng một dòng.
 *
 * Chủ thể và causer là chính tài khoản của phiên, truyền TƯỜNG MINH (không đoán từ `auth()`).
 * Thuộc tính, không gì khác:
 *  - `oauth_client_id`: dòng `oauth_clients` của yêu cầu ({@see ConsentRequest::clientId()});
 *  - `platform`: nền tảng suy từ host redirect ({@see McpPlatform::fromRedirectUri()});
 *  - `redirect_host`: chính host đó ({@see ConsentRequest::displayHost()});
 *  - `mode` (chỉ khi đồng ý): `users.ai_access` lúc đồng ý — kết nối đọc hay đọc và ghi;
 *  - `reason` (chỉ khi không): `user` khi chính người đó bấm "Từ chối", hoặc giá trị của
 *    {@see McpAccessRefusal} khi màn hình từ chối thay họ.
 *
 * KHÔNG ghi `client_name` (client tự khai lúc đăng ký động, ai cũng đặt được "Claude chính chủ"),
 * `state`, `code_challenge`, hay URL đầy đủ của redirect (đường dẫn redirect của ChatGPT mang mã
 * callback của từng kết nối).
 */
final class RecordMcpConnectionDecision
{
    /** `reason` của dòng `mcp_connection_denied` khi chính người đó bấm "Từ chối". */
    public const DENIED_BY_USER = 'user';

    public function authorized(User $user, string $clientId, string $redirectUri): void
    {
        Audit::record('mcp_connection_authorized', $user, [
            ...self::connection($clientId, $redirectUri),
            'mode' => $user->ai_access->value,
        ], causer: $user);
    }

    /**
     * @param  McpAccessRefusal|null  $refusal  `null` = chính người đó bấm "Từ chối"
     */
    public function denied(Model $account, string $clientId, string $redirectUri, ?McpAccessRefusal $refusal): void
    {
        Audit::record('mcp_connection_denied', $account, [
            ...self::connection($clientId, $redirectUri),
            'reason' => $refusal?->value ?? self::DENIED_BY_USER,
        ], causer: $account);
    }

    /** @return array{oauth_client_id: string, platform: string, redirect_host: string} */
    private static function connection(string $clientId, string $redirectUri): array
    {
        return [
            'oauth_client_id' => $clientId,
            'platform' => McpPlatform::fromRedirectUri($redirectUri)->value,
            'redirect_host' => ConsentRequest::displayHost($redirectUri),
        ];
    }
}
