<?php

use App\Http\Middleware\Mcp\CheckOrigin;
use App\Http\Middleware\Mcp\RequireBearerToken;
use App\Mcp\Servers\CrmServer;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Passport\Http\Middleware\CheckToken;

/*
|--------------------------------------------------------------------------
| Máy chủ MCP cho nhân sự (kế hoạch M11)
|--------------------------------------------------------------------------
|
| laravel/mcp tự nạp tệp này (`McpServiceProvider::registerRoutes()`) bằng `Route::group([], …)`,
| tức KHÔNG qua nhóm `web` hay `api`: không phiên, không cookie, không CSRF [PL:52]. Đừng thêm nhóm
| `web`. Cũng đừng chuyển route xuống dưới `api/`: `RateLimitSpec103Test` canh `api/*` theo một luật
| khác (giới hạn 60/phút của `/mcp` là việc của Task 8).
|
| `Mcp::web()` đăng ký `POST /mcp` (kèm ReorderJsonAccept, ValidateMcpHeaders,
| AddWwwAuthenticateHeader của gói) và hai route `GET`/`DELETE /mcp` trả 405 `Allow: POST` [PL:51].
| Bốn middleware thêm ở đây chỉ đứng trước `POST`, theo thứ tự:
|
|   1. CheckOrigin         — Origin ngoài allowlist: 403, TRƯỚC mọi bước xác thực (R7);
|   2. RequireBearerToken  — chỉ header `Authorization: Bearer`: xoá cookie `laravel_token` khỏi
|                            request để `TokenGuard` không đi được đường cookie, và chặn bearer
|                            trống (R1);
|   3. auth:mcp            — token Passport hợp lệ của một `users.id` (guard `mcp`, R1);
|   4. CheckToken mcp:use  — token phải mang scope `mcp:use` (R7, phương án ràng buộc bằng cấu trúc).
|
| Không qua bước 2 hoặc 3 thì request nhận 401 JSON kèm `WWW-Authenticate` trỏ tới PRM
| (`App\Http\Middleware\Mcp\AddWwwAuthenticateHeader`, render JSON ở `bootstrap/app.php`). Token
| hợp lệ nhưng thiếu `mcp:use` dừng ở bước 4 với 403, không kèm header đó (TransportTest).
| `EnsureMcpAccess` (is_active, ai_access, công tắc toàn hệ thống, cam kết R12, client mang cờ mcp)
| đến ở Task 6.
|
| TODO(m11-task6-mcp-enabled-switch): công tắc `mcp.enabled` nằm trong bảng `settings` của m7b
| Task 10, chưa có trên nhánh này. Task 6 (sau khi controller merge `origin/m7-extras`) thêm
| `EnsureMcpAccess` vào danh sách dưới đây, ngay sau `CheckToken`. Không dựng công tắc tạm.
*/

Mcp::web('/'.CrmServer::PATH, CrmServer::class)->middleware([
    CheckOrigin::class,
    RequireBearerToken::class,
    'auth:mcp',
    CheckToken::using('mcp:use'),
]);
