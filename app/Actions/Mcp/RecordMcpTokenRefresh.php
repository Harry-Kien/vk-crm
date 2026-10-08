<?php

namespace App\Actions\Mcp;

use App\Enums\McpPlatform;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Passport;

/**
 * Nhật ký lần LÀM MỚI một kết nối AI (M11 R8 "ghi cả kết nối, làm mới token, thu hồi, bật/tắt";
 * Task 17 đóng rà soát Task 8, m5): mỗi lần `/oauth/token` cấp access token mới bằng grant
 * `refresh_token` cho một client MCP, đúng một dòng `mcp_token_refreshed`.
 *
 * Đăng ký ở `AppServiceProvider::boot()` nghe `Laravel\Passport\Events\AccessTokenCreated` — sự kiện
 * `Bridge\AccessTokenRepository::persistNewAccessToken()` phát ngay sau khi lưu dòng
 * `oauth_access_tokens`, cho MỌI grant. Lớp này lọc:
 *  - chỉ request mang `grant_type = refresh_token` (request truyền TƯỜNG MINH từ nơi đăng ký). Token
 *    chỉ được cấp ở `/oauth/token` (`RestrictOAuthGrantTypes`: `authorization_code` và `refresh_token`;
 *    personal access token không phân giải được), nên không cần hỏi thêm route. Lần đổi mã lấy token
 *    đầu tiên đã có dòng `mcp_connection_authorized` của màn hình đồng ý, nên không ghi lại ở đây;
 *  - chỉ token gắn một người còn tồn tại và một client mang cờ `oauth_clients.is_mcp` — client khác
 *    không phải một kết nối AI.
 * Lần làm mới bị league từ chối (mã cũ đã xoay vòng hay bị thu hồi, mã của client khác) không tới được
 * bước lưu token, nên không có dòng.
 *
 * Chủ thể và causer là người sở hữu token, truyền tường minh (`Audit::record()` không có `$causer` thì
 * đoán từ `auth('web')`/`auth('client')`, cả hai rỗng ở `/oauth/token`). `properties`, không gì khác:
 * `channel = mcp`, `oauth_client_id`, `platform` (suy từ redirect URI đầu tiên của client,
 * {@see McpPlatform::fromRedirectUri()} — như `mcp_tool_called` và `mcp_connection_authorized`).
 * Không token, không mã làm mới, không `client_name` tự khai. IP không ghi: lần làm mới của Claude hay
 * ChatGPT đi từ hạ tầng của nền tảng [PL:177], [PL:181].
 */
final class RecordMcpTokenRefresh
{
    public function handle(AccessTokenCreated $event, Request $request): void
    {
        if ($request->input('grant_type') !== 'refresh_token') {
            return;
        }

        $client = Passport::client()->newQuery()->whereKey($event->clientId)->where('is_mcp', true)->first();
        $user = User::query()->find($event->userId);

        if ($client === null || $user === null) {
            return;
        }

        $redirectUri = $client->redirect_uris[0] ?? null;

        Audit::record('mcp_token_refreshed', $user, [
            'channel' => 'mcp',
            'oauth_client_id' => (string) $client->getKey(),
            'platform' => (is_string($redirectUri) ? McpPlatform::fromRedirectUri($redirectUri) : McpPlatform::Other)->value,
        ], causer: $user);
    }
}
