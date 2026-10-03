<?php

use App\Http\Controllers\Mcp\AuthorizationServerMetadataController;
use App\Http\Controllers\Mcp\ProtectedResourceMetadataController;
use App\Http\Middleware\Mcp\CheckOrigin;
use App\Http\Middleware\Mcp\EnsureTokenAudience;
use App\Http\Middleware\Mcp\RequireBearerToken;
use App\Mcp\Servers\CrmServer;
use Illuminate\Support\Facades\Route;
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
| Năm middleware thêm ở đây chỉ đứng trước `POST`, theo thứ tự:
|
|   1. CheckOrigin          — Origin ngoài allowlist: 403, TRƯỚC mọi bước xác thực (R7);
|   2. RequireBearerToken   — chỉ header `Authorization: Bearer`: xoá cookie `laravel_token` khỏi
|                             request để `TokenGuard` không đi được đường cookie, và chặn bearer
|                             trống (R1);
|   3. auth:mcp             — token Passport hợp lệ của một `users.id` (guard `mcp`, R1);
|   4. EnsureTokenAudience  — `aud` của token phải chứa đúng URL MCP chuẩn (R7, Task 2). Đứng SAU
|                             bước 3 vì nó đọc claim của token mà bước 3 vừa kiểm chữ ký;
|   5. CheckToken mcp:use   — token phải mang scope `mcp:use` (R7).
|
| Không qua bước 2, 3 hoặc 4 thì request nhận 401 JSON kèm `WWW-Authenticate` trỏ tới PRM
| (`App\Http\Middleware\Mcp\AddWwwAuthenticateHeader`, render JSON ở `bootstrap/app.php`); có gửi
| bearer mà bearer không dùng được thì header mang thêm `error="invalid_token"`. Token hợp lệ nhưng
| thiếu `mcp:use` dừng ở bước 5 với 403 kèm `error="insufficient_scope"` (TransportTest,
| OAuthMetadataTest). `EnsureMcpAccess` (is_active, ai_access, công tắc toàn hệ thống, cam kết R12,
| client mang cờ mcp) đến ở Task 6.
|
| TODO(m11-task6-mcp-enabled-switch): công tắc `mcp.enabled` nằm trong bảng `settings` của m7b
| Task 10, chưa có trên nhánh này. Task 6 (sau khi controller merge `origin/m7-extras`) thêm
| `EnsureMcpAccess` vào danh sách dưới đây, ngay sau `CheckToken`. Không dựng công tắc tạm.
*/

Mcp::web('/'.CrmServer::PATH, CrmServer::class)->middleware([
    CheckOrigin::class,
    RequireBearerToken::class,
    'auth:mcp',
    EnsureTokenAudience::class,
    CheckToken::using('mcp:use'),
]);

/*
|--------------------------------------------------------------------------
| Metadata OAuth công khai (R7, Task 2): PRM (RFC 9728) và AS metadata (RFC 8414)
|--------------------------------------------------------------------------
|
| Bốn URI CỤ THỂ, không mẫu `{path}`. `Mcp::oauthRoutes()` của gói (nếu Task 3 gọi nó để có
| `/oauth/register`) chỉ nhường hai route GỐC khi app đã khai, còn hai route lồng
| `…/{path}` thì nó LUÔN đăng ký. Laravel khoá route theo method + domain + URI, nên một route của app
| khai cùng mẫu `{path}` sẽ bị route của gói đăng ký sau thay mất. URI cụ thể thì là một khoá khác,
| khớp trước mẫu của gói (khi so tuần tự, route đăng ký trước thắng; khi route đã cache, route tĩnh
| được so trước route có tham số). OAuthMetadataTest gọi `Mcp::oauthRoutes()` rồi kiểm cả bốn.
|
| Ngoài nhóm `web`: không phiên, không cookie. Nội dung cố định dựng từ cấu hình, không dữ liệu
| người dùng (lý lẽ §10.7 trong `StaffTwoFactorEscapeRoutesTest`).
*/

Route::get('/.well-known/oauth-protected-resource', ProtectedResourceMetadataController::class)
    ->name('mcp.metadata.protected-resource');

Route::get('/.well-known/oauth-protected-resource/'.CrmServer::PATH, ProtectedResourceMetadataController::class)
    ->name('mcp.metadata.protected-resource.mcp');

Route::get('/.well-known/oauth-authorization-server', AuthorizationServerMetadataController::class)
    ->name('mcp.metadata.authorization-server');

Route::get('/.well-known/oauth-authorization-server/'.CrmServer::PATH, AuthorizationServerMetadataController::class)
    ->name('mcp.metadata.authorization-server.mcp');
