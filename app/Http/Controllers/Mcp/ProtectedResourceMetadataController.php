<?php

namespace App\Http\Controllers\Mcp;

use App\Support\Mcp\McpEndpoint;
use Illuminate\Http\JsonResponse;
use Laravel\Mcp\Server\Registrar;

/**
 * M11 R7 — Protected Resource Metadata (RFC 9728) của máy chủ MCP, phục vụ ở CẢ
 * `/.well-known/oauth-protected-resource` lẫn `/.well-known/oauth-protected-resource/mcp` [DC:629].
 * `WWW-Authenticate` của mọi 401 từ `/mcp` trỏ tới đường thứ hai.
 *
 * - `resource`: đúng URL MCP chuẩn, không `/` cuối, ở CẢ HAI đường (R7). RFC 9728 §3.3 nói `resource`
 *   phải bằng định danh dùng để dựng URL metadata, nên ở đường gốc giá trị "đúng sách" là origin; R7
 *   chọn URL MCP vì client lùi về đường gốc khi không có đường lồng, và đặc tả MCP đòi client so
 *   `resource` với URL máy chủ nó đang gọi.
 * - `authorization_servers`: chỉ một mục, issuer chính, vì Claude chỉ dùng mục đầu [DC:715].
 * - `bearer_methods_supported: ["header"]`: chỉ header `Authorization` (R1; không query, không thân).
 *
 * Mọi URL dựng từ cấu hình ({@see McpEndpoint}), không từ host của request. Nội dung cố định, không
 * đọc phiên, không dữ liệu người dùng.
 */
class ProtectedResourceMetadataController
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'resource' => McpEndpoint::resource(),
            'authorization_servers' => [McpEndpoint::issuer()],
            'scopes_supported' => [Registrar::OAUTH_SCOPE],
            'bearer_methods_supported' => ['header'],
        ], options: JSON_UNESCAPED_SLASHES);
    }
}
