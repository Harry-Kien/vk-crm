<?php

namespace App\Support\Mcp;

use DateTimeImmutable;
use Laravel\Passport\Bridge\AccessToken;
use Lcobucci\JWT\Token;
use League\OAuth2\Server\Entities\Traits\AccessTokenTrait;

/**
 * M11 R7 — entity access token riêng: claim `aud` của JWT là `[id client, URL MCP]` thay vì chỉ
 * `id client`, để token mang theo máy chủ tài nguyên mà nó được cấp cho (vai trò `aud` của RFC 8707
 * §2 / RFC 9068 §3), và `App\Http\Middleware\Mcp\EnsureTokenAudience` từ chối token không mang URL
 * MCP chuẩn.
 *
 * **Khả thi mà không sửa lõi** (phán quyết Task 2, phương án "ưu tiên" của R7): Passport có điểm mở
 * rộng chính thức `Passport::useAccessTokenEntity()`; `Bridge\AccessTokenRepository::getNewToken()`
 * dựng entity bằng lớp đó cho MỌI grant (authorization_code lẫn refresh_token). Đăng ký ở
 * `AppServiceProvider::boot()`. Không bind lại `AuthorizationServer`, `ResourceServer` hay
 * repository nào.
 *
 * **Thứ tự trong `aud` là một phần của hợp đồng.** Bộ kiểm token của league
 * (`BearerTokenValidator::validateAuthorization()`) đặt `oauth_client_id` bằng `aud[0]`, và
 * `TokenGuard` của Passport tìm client theo giá trị đó. Id client phải đứng ĐẦU; URL MCP đứng sau.
 *
 * **Vì sao lặp lại trait.** `convertToJWT()` của `League\…\AccessTokenTrait` là `private` và chỉ ghi
 * một `aud`. Khoá riêng và cấu hình JWT là thuộc tính `private` của trait trong lớp cha, nên lớp con
 * không đọc được chúng. Lớp này dùng lại `AccessTokenTrait` để có bản riêng của các thuộc tính đó
 * (PHP cho phép: thuộc tính `private` của lớp cha không xung đột), rồi định nghĩa lại đúng một
 * phương thức, `convertToJWT()`: thân y hệt bản của league 9.4.1, chỉ khác lời gọi `permittedFor()`.
 * `tests/Feature/Mcp/OAuthMetadataTest.php` so tập claim của token này với token gốc của Passport, nên
 * một bản league sau này thêm hay đổi claim làm test đó đỏ thay vì âm thầm lệch.
 *
 * URL MCP đọc ở lúc ký ({@see McpEndpoint::resource()}), tức theo cấu hình của request `/oauth/token`.
 */
class McpAccessToken extends AccessToken
{
    use AccessTokenTrait;

    private function convertToJWT(): Token
    {
        $this->initJwtConfiguration();

        return $this->jwtConfiguration->builder()
            ->permittedFor($this->getClient()->getIdentifier(), McpEndpoint::resource())
            ->identifiedBy($this->getIdentifier())
            ->issuedAt(new DateTimeImmutable)
            ->canOnlyBeUsedAfter(new DateTimeImmutable)
            ->expiresAt($this->getExpiryDateTime())
            ->relatedTo($this->getSubjectIdentifier())
            ->withClaim('scopes', $this->getScopes())
            ->getToken($this->jwtConfiguration->signer(), $this->jwtConfiguration->signingKey());
    }
}
