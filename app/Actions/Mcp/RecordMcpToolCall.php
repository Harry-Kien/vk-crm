<?php

namespace App\Actions\Mcp;

use App\Enums\McpPlatform;
use App\Enums\McpToolOutcome;
use App\Models\User;
use App\Support\Audit;
use App\Support\Mcp\ToolCallContext;
use Laravel\Passport\Client;

/**
 * Dòng nhật ký `mcp_tool_called` của MỘT lần gọi tool (M11 R8, Task 8) — gọi bởi middleware
 * `App\Http\Middleware\Mcp\AuditToolCall`, đúng một lần mỗi `tools/call`.
 *
 * Causer là người sở hữu token, truyền TƯỜNG MINH: `Audit::record()` không có `$causer` thì đoán từ
 * `auth('web')` rồi `auth('client')`, cả hai rỗng hoặc là một người KHÁC trong request `/mcp`. Không
 * có chủ thể: một lần gọi tool không thuộc về một bản ghi (danh sách, tìm kiếm), và id các đối tượng
 * đã chạm nằm trong `properties`.
 *
 * `properties` — đủ các trường R8 đòi, không gì khác:
 *  - từ {@see ToolCallContext::auditProperties()}: `channel = mcp`, `tool`, `outcome`, `arguments`
 *    (allowlist; văn bản tự do chỉ còn độ dài), `returned_ids`, `returned_count`, `returned_fields`
 *    (tên, không giá trị), `duration_ms`, `correlation_id`;
 *  - `oauth_client_id` và `platform` (suy từ redirect URI đầu tiên của client,
 *    {@see McpPlatform::fromRedirectUri()} — như dòng `mcp_connection_authorized` của Task 4; không bao
 *    giờ `client_name` tự khai);
 *  - `ip`: `$request->ip()` sau `TrustProxies`. Với Claude và ChatGPT đây là IP của NỀN TẢNG, không
 *    của nhân sự [PL:177], [PL:181]; trang Nhật ký hệ thống ghi rõ điều đó.
 * Không bao giờ ghi prompt, nội dung trả về, CCCD, số điện thoại [DC:153], [DC:224], [DC:674].
 *
 * Lần gọi ĐỌC thành công còn được đếm vào ngưỡng "quá 200 bản ghi một giờ" ({@see AlertOnMcpReadVolume}).
 * Dòng này sống ít nhất bằng thời hạn lưu hồ sơ: không tác vụ lịch nào chạy `activitylog:clean`, và
 * con số của lệnh đó ≥ `retention_years × 365` (`RateLimitSpec103Test`), tức ≥ 400 ngày mà R8 đòi cho
 * bằng chứng đánh giá tác động [PL:349] (`AuditTest`).
 */
final class RecordMcpToolCall
{
    public function __construct(private readonly AlertOnMcpReadVolume $readVolume) {}

    public function handle(User $user, ?Client $client, ToolCallContext $call, ?string $ip): void
    {
        $redirectUri = $client?->redirect_uris[0] ?? null;

        Audit::record('mcp_tool_called', null, [
            ...$call->auditProperties(),
            'oauth_client_id' => $client === null ? null : (string) $client->getKey(),
            'platform' => is_string($redirectUri) ? McpPlatform::fromRedirectUri($redirectUri)->value : null,
            'ip' => $ip,
        ], causer: $user);

        if ($call->currentOutcome() === McpToolOutcome::Ok && ! $call->writes()) {
            $this->readVolume->handle($user, $call->returnedCount());
        }
    }
}
