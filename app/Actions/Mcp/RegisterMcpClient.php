<?php

namespace App\Actions\Mcp;

use App\Exceptions\McpRedirectUriNotAllowed;
use App\Support\Mcp\RedirectUriAllowlist;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

/**
 * M11 R7 (Task 3) — tạo một client OAuth cho máy chủ MCP: đường DUY NHẤT sinh ra client mang cờ
 * `oauth_clients.is_mcp`, thứ `/mcp` đòi ở mọi request (`App\Http\Middleware\Mcp\EnsureMcpClient`).
 * Gọi từ đăng ký client động (`POST /oauth/register`, `App\Http\Controllers\Mcp\RegisterClientController`).
 *
 * Client tạo ra luôn có cùng một hình dạng, bất kể client xin gì (RFC 7591 §2 cho máy chủ thay giá
 * trị client xin):
 * - **công khai** (không secret, `token_endpoint_auth_method: none`);
 * - grant `authorization_code` + `refresh_token`, không device code
 *   (`ClientRepository::createAuthorizationCodeGrantClient()` của Passport);
 * - mọi redirect URI nằm trong allowlist so khớp chính xác ({@see RedirectUriAllowlist}). Một URI
 *   ngoài allowlist làm cả lần đăng ký thất bại ({@see McpRedirectUriNotAllowed}) TRƯỚC khi ghi gì.
 *
 * Tạo client và gắn cờ trong CÙNG transaction: không còn trạng thái "client DCR mà thiếu cờ" (token
 * của nó sẽ bị `/mcp` từ chối, và lệnh dọn không bao giờ xoá nó).
 *
 * Không ghi nhật ký: lời gọi này vô danh (chưa có nhân sự nào), và một client mới không mở được gì
 * khi chưa có nhân sự đồng ý ở `/oauth/authorize`. Việc kết nối được ghi ở màn hình đồng ý (Task 4).
 */
class RegisterMcpClient
{
    public function __construct(private readonly ClientRepository $clients) {}

    /**
     * @param  list<string>  $redirectUris
     *
     * @throws McpRedirectUriNotAllowed
     */
    public function handle(string $name, array $redirectUris): Client
    {
        foreach ($redirectUris as $redirectUri) {
            if (! RedirectUriAllowlist::allows($redirectUri)) {
                throw new McpRedirectUriNotAllowed($redirectUri);
            }
        }

        return Passport::client()->getConnection()->transaction(function () use ($name, $redirectUris): Client {
            $client = $this->clients->createAuthorizationCodeGrantClient(
                $name,
                $redirectUris,
                confidential: false,
                enableDeviceFlow: false,
            );

            $client->forceFill(['is_mcp' => true])->save();

            return $client;
        });
    }
}
