<?php

namespace App\Support\Mcp;

use App\Actions\Mcp\ResolveClientIdMetadataDocument;
use App\Http\Middleware\Mcp\RequireConsentForMetadataDocumentClients;
use Laravel\Passport\Bridge\Client as ClientEntity;
use Laravel\Passport\Bridge\ClientRepository;
use League\OAuth2\Server\Entities\ClientEntityInterface;

/**
 * M11 R7 (Task 5) — repository client của league/oauth2-server, thay `Bridge\ClientRepository` của
 * Passport qua container (`AppServiceProvider::register()`; `PassportServiceProvider` phân giải lớp
 * đó bằng `make()` khi dựng `AuthorizationServer`). Đây là lớp DUY NHẤT của Passport/league bị thay
 * cho CIMD (cổng dừng của kế hoạch: "sửa hơn một lớp lõi" thì dừng).
 *
 * - `client_id` có `://` (một URL; UUID của Passport không bao giờ có): một client CIMD, đổi ra
 *   dòng `oauth_clients` qua {@see ResolveClientIdMetadataDocument} — nơi DUY NHẤT quyết URL nào
 *   được nhận (cờ tắt, không phải `https://`, URL sai, tài liệu hỏng → `null` → league trả
 *   `invalid_client`). Entity mang `id` UUID của dòng, không mang URL: mã uỷ quyền, token, `aud[0]`
 *   và mọi phép so của league (`client_id` của mã với client, của refresh token với client) đều chạy
 *   trên UUID, nên client gửi URL ở `/oauth/authorize` lẫn `/oauth/token` đều khớp.
 * - mọi id khác (UUID của client DCR hay `passport:client`): đúng như Passport.
 *
 * Một dòng CIMD dùng chung cho mọi nhân sự của nền tảng, nên `/oauth/authorize` với client CIMD (bằng URL hay bằng
 * UUID của dòng) không bao giờ được Passport tự duyệt: {@see RequireConsentForMetadataDocumentClients}.
 *
 * `validateClient()` không đổi: client CIMD là client công khai, league không gọi hàm đó cho chúng
 * (`AbstractGrant::validateClient()` chỉ gọi khi `isConfidential()`), và với URL thì Passport tìm
 * theo id, không thấy, trả `false`.
 *
 * **Loopback `localhost` bỏ qua cổng, chỉ cho client CIMD.** league 9.4.1
 * (`RedirectUriValidator::isLoopbackUri()`) bỏ qua cổng cho `127.0.0.1` và `[::1]`, nhưng so
 * `localhost` chính xác cả cổng; tài liệu CIMD của Claude Code khai `http://localhost/callback` không
 * cổng [DC:739] còn Claude Code mở một cổng mới mỗi phiên. Khi redirect URI của request đang phục vụ
 * (`redirect_uri` trong query của `/oauth/authorize` hay thân của `/oauth/token`) khớp một redirect
 * URI đã lưu của client theo luật loopback của R7 ({@see RedirectUriAllowlist::sameLoopback()}: cùng
 * host loopback, cùng phần sau cổng, cổng 1–65535), entity mang thêm ĐÚNG URI đó, và league so
 * chính xác với nó. Client DCR không cần: chúng đăng ký đúng cổng ở mỗi lần kết nối (Task 3).
 */
class McpClientRepository extends ClientRepository
{
    public function getClientEntity(string $clientIdentifier): ?ClientEntityInterface
    {
        if (! str_contains($clientIdentifier, '://')) {
            return parent::getClientEntity($clientIdentifier);
        }

        $client = app(ResolveClientIdMetadataDocument::class)->handle($clientIdentifier);

        if ($client === null) {
            return null;
        }

        return new ClientEntity(
            $client->getKey(),
            $client->name,
            self::withRequestedLoopbackUri($client->redirect_uris),
            false,
            $client->provider,
            $client->grant_types,
        );
    }

    /**
     * @param  list<string>  $redirectUris
     * @return list<string>
     */
    private static function withRequestedLoopbackUri(array $redirectUris): array
    {
        $requested = request()->input('redirect_uri');

        if (! is_string($requested)) {
            return $redirectUris;
        }

        foreach ($redirectUris as $redirectUri) {
            if (RedirectUriAllowlist::sameLoopback($redirectUri, $requested)) {
                return [...$redirectUris, $requested];
            }
        }

        return $redirectUris;
    }
}
