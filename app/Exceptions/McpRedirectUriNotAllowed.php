<?php

namespace App\Exceptions;

use App\Support\Mcp\RedirectUriAllowlist;
use DomainException;

/**
 * M11 R7 (Task 3) — `App\Actions\Mcp\RegisterMcpClient` từ chối một redirect URI không có trong
 * allowlist ({@see RedirectUriAllowlist}). `App\Http\Controllers\Mcp\RegisterClientController` đổi
 * nó thành 400 `invalid_redirect_uri` (RFC 7591 §3.2.2).
 *
 * Thông điệp là chữ ASCII tiếng Anh, không qua `lang/vi`: nó đi thẳng vào `error_description`,
 * trường mà RFC 7591 §3.2.2 định nghĩa là "Human-readable ASCII text ... used for debugging" — đọc
 * bởi người viết client, không phải nhân sự (rà soát Task 2, m1). Không lặp lại URI bị từ chối.
 */
class McpRedirectUriNotAllowed extends DomainException
{
    public function __construct(public readonly string $redirectUri)
    {
        parent::__construct('redirect_uris contains a URI that is not on this server\'s exact-match allowlist.');
    }
}
