<?php

namespace App\Actions\Mcp;

use App\Enums\McpPlatform;
use Carbon\CarbonImmutable;

/**
 * MỘT kết nối AI của một nhân sự (M11 R8, Task 15) như hai trang "Kết nối AI" hiện ra — DTO của
 * {@see ListAiConnections::forUser()}, không phải model.
 *
 *  - `clientId`: `oauth_clients.id` — khoá của nút "Thu hồi" dòng này;
 *  - `platform` + `host`: nền tảng suy từ HOST của redirect URI đầu tiên của client, kèm chính host
 *    đó ({@see McpPlatform::fromRedirectUri()}), không bao giờ `client_name` tự khai [DC:139];
 *  - `connectedAt`: lần đồng ý gần nhất của người này cho client này (dòng `mcp_connection_authorized`),
 *    hoặc token cũ nhất còn trong bảng khi không có dòng đó;
 *  - `lastUsedAt`: lần gọi tool gần nhất qua client này (dòng `mcp_tool_called`), `null` = chưa gọi.
 */
final readonly class AiConnection
{
    public function __construct(
        public string $clientId,
        public McpPlatform $platform,
        public string $host,
        public CarbonImmutable $connectedAt,
        public ?CarbonImmutable $lastUsedAt,
    ) {}
}
