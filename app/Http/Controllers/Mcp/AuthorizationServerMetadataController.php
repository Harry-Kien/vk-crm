<?php

namespace App\Http\Controllers\Mcp;

use App\Actions\Mcp\ResolveClientIdMetadataDocument;
use App\Http\Middleware\Mcp\AddIssuerToAuthorizationResponse;
use App\Http\Middleware\Mcp\RestrictOAuthGrantTypes;
use App\Http\Middleware\Mcp\ValidateOAuthParameters;
use App\Support\Mcp\McpEndpoint;
use Illuminate\Http\JsonResponse;
use Laravel\Mcp\Server\Registrar;

/**
 * M11 R7 — Authorization Server Metadata (RFC 8414) TỰ KHAI, phục vụ ở
 * `/.well-known/oauth-authorization-server` và `/.well-known/oauth-authorization-server/mcp`.
 * Bản của laravel/mcp (`Registrar::authorizationServerMetadata()`) thiếu
 * `token_endpoint_auth_methods_supported` và hai cờ dưới đây [PL:28].
 *
 * - `token_endpoint_auth_methods_supported: ["none"]`: ChatGPT đòi trường này [PL:70]; Claude chỉ
 *   chọn CIMD khi có `none` [PL:67]. Client do DCR tạo là client công khai.
 * - `code_challenge_methods_supported: ["S256"]`: đúng luật mà
 *   {@see ValidateOAuthParameters} áp (league còn nhận `plain`).
 * - `grant_types_supported`: đọc từ {@see RestrictOAuthGrantTypes::ALLOWED_GRANT_TYPES}, cùng nguồn
 *   với phép chặn grant ở `/oauth/token`.
 * - `scopes_supported: ["mcp:use"]`: một scope, không `offline_access` [DC:634].
 * - `registration_endpoint`: DCR ở `/oauth/register` ({@see McpEndpoint::REGISTRATION_PATH}). Route
 *   do Task 3 đăng ký, kèm allowlist redirect so khớp chính xác và throttle.
 * - `authorization_response_iss_parameter_supported: true` CHỈ KHI middleware gắn `iss` đang đứng
 *   trong nhóm route của Passport ({@see AddIssuerToAuthorizationResponse::isActive()}) — R7: "chỉ khi
 *   đã trả được `iss`".
 * - `client_id_metadata_document_supported: true` CHỈ KHI máy chủ nhận `client_id` dạng URL
 *   ({@see ResolveClientIdMetadataDocument::enabled()}, cờ `vkcrm.mcp.client_id_metadata_documents`):
 *   cùng một cờ quyết cả việc quảng bá lẫn việc nhận (Task 5). Mặc định tắt (cổng dừng của Task 5
 *   chưa đạt): Claude và ChatGPT tự lùi về DCR [DC:715], [PL:179].
 *
 * Mọi URL dựng từ cấu hình ({@see McpEndpoint}), không từ host của request; `issuer` bằng đúng
 * `iss` trong phản hồi uỷ quyền và `authorization_servers[0]` của PRM. Không đọc
 * `config('mcp.authorization_server')` của gói: issuer của chính máy chủ này không phải thứ cấu hình
 * được thành một giá trị khác (RFC 8414 §3.3: issuer trong metadata phải bằng issuer dùng để dựng URL
 * metadata).
 *
 * Ở đường lồng `…/mcp`, đọc chặt RFC 8414 §3.1 và §3.3 thì URL đó thuộc issuer `<gốc>/mcp`, nên một
 * client nghiêm sẽ thấy `issuer` lệch. Đường này vẫn trả issuer gốc (máy chủ chỉ có MỘT issuer): nó
 * tồn tại để client đoán URL metadata từ URL MCP nhận đủ trường, thay vì bản thiếu của gói (route
 * `{path}` của `Mcp::oauthRoutes()`), như rà soát Task 0 (mục 8) đề xuất.
 */
class AuthorizationServerMetadataController
{
    public function __invoke(): JsonResponse
    {
        $metadata = [
            'issuer' => McpEndpoint::issuer(),
            'authorization_endpoint' => McpEndpoint::authorizationEndpoint(),
            'token_endpoint' => McpEndpoint::tokenEndpoint(),
            'registration_endpoint' => McpEndpoint::registrationEndpoint(),
            'scopes_supported' => [Registrar::OAUTH_SCOPE],
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => RestrictOAuthGrantTypes::ALLOWED_GRANT_TYPES,
            'token_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256'],
        ];

        if (AddIssuerToAuthorizationResponse::isActive()) {
            $metadata['authorization_response_iss_parameter_supported'] = true;
        }

        if (ResolveClientIdMetadataDocument::enabled()) {
            $metadata['client_id_metadata_document_supported'] = true;
        }

        return response()->json($metadata, options: JSON_UNESCAPED_SLASHES);
    }
}
