<?php

use App\Http\Controllers\Mcp\AuthorizationServerMetadataController;
use App\Http\Controllers\Mcp\ProtectedResourceMetadataController;
use App\Http\Controllers\Mcp\RegisterClientController;
use App\Http\Middleware\Mcp\AuditToolCall;
use App\Http\Middleware\Mcp\CheckOrigin;
use App\Http\Middleware\Mcp\EnsureMcpAccess;
use App\Http\Middleware\Mcp\EnsureMcpClient;
use App\Http\Middleware\Mcp\EnsureTokenAudience;
use App\Http\Middleware\Mcp\RequireBearerToken;
use App\Http\Middleware\Mcp\ThrottleMcp;
use App\Http\Middleware\Mcp\ThrottleMcpAuthenticationFailures;
use App\Mcp\Servers\CrmServer;
use App\Support\Mcp\McpEndpoint;
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
| khác; giới hạn "API 60 request/phút" của SPEC §10.3 gắn ở `/mcp`, bước 8–9 dưới đây (Task 8).
|
| `Mcp::web()` đăng ký `POST /mcp` (kèm ReorderJsonAccept, ValidateMcpHeaders,
| AddWwwAuthenticateHeader của gói) và hai route `GET`/`DELETE /mcp` trả 405 `Allow: POST` [PL:51].
| Mười middleware thêm ở đây chỉ đứng trước `POST`, theo thứ tự:
|
|   1. CheckOrigin          — Origin ngoài allowlist: 403, TRƯỚC mọi bước xác thực (R7);
|   2. ThrottleMcpAuthenticationFailures — một BEARER đã bị từ chối 401 quá 30 lần trong một phút từ
|                             cùng một IP nhận 429 ngay, trước bước kiểm token. Đếm theo IP và dấu
|                             băm của chính bearer đó: bearer khác từ cùng IP (nhân sự khác gọi qua
|                             chung IP của nền tảng) và request không mang bearer không bao giờ bị
|                             chặn (R8, Task 8; rà soát Task 8, I2);
|   3. RequireBearerToken   — chỉ header `Authorization: Bearer`: xoá cookie `laravel_token` khỏi
|                             request để `TokenGuard` không đi được đường cookie, và chặn bearer
|                             trống (R1);
|   4. auth:mcp             — token Passport hợp lệ của một `users.id` (guard `mcp`, R1);
|   5. EnsureTokenAudience  — `aud` của token phải chứa đúng URL MCP chuẩn (R7, Task 2). Đứng SAU
|                             bước 4 vì nó đọc claim của token mà bước 4 vừa kiểm chữ ký;
|   6. EnsureMcpClient      — client của token phải mang cờ `oauth_clients.is_mcp`, tức do đăng ký
|                             động bên dưới tạo (R2/R7, Task 3). Đứng SAU bước 4 vì nó đọc client
|                             mà guard vừa gắn cho token;
|   7. CheckToken mcp:use   — token phải mang scope `mcp:use` (R7);
|   8. AuditToolCall        — mỗi `tools/call` mang token hợp lệ (đã qua bước 4–7) sinh đúng một
|                             dòng `mcp_tool_called`, kể cả lần bị từ chối hay bị chặn (R8, Task 8).
|                             Đứng TRƯỚC bước 10 (rà soát Task 8, I3): lần gọi bị `EnsureMcpAccess`
|                             từ chối — người đã biết, token hợp lệ — cũng có dòng, outcome `denied`,
|                             causer là người sở hữu token. Request bị dừng ở bước 1–7 chưa có token
|                             hợp lệ (hay thiếu scope) nên không có dòng;
|   9. ThrottleMcp          — dịch câu trả lời rate limit của bước gọi tool (`CallCrmTool`,
|                             `App\Support\Mcp\McpRateLimits`: 60 lần một phút, `search`/`fetch` 30,
|                             tool ghi 10 một phút và 100 một ngày; theo người và theo token) ra
|                             HTTP 429, `Retry-After`, `X-RateLimit-*` (R8, Task 8). Đứng NGAY SAU
|                             bước 8, trong lúc trạng thái của lần gọi còn gắn với request;
|  10. EnsureMcpAccess      — NGƯỜI sở hữu token được dùng máy chủ ở chính request này (R2, R12,
|                             Task 6): tài khoản đang hoạt động, `users.ai_access` khác `off` (và
|                             người đó còn có `matter.view`), công tắc `mcp.enabled` trong bảng
|                             `settings`, cam kết chính sách dùng AI đúng phiên bản hiện hành
|                             (`App\Support\Mcp\McpAccess::refusal()`). Đứng sau mọi bước xác thực:
|                             nó đọc người mà bước 4 vừa xác thực, và hai điều kiện còn lại của R2
|                             (client mang cờ mcp, scope) là bước 6 và 7. Bước 8–9 đứng giữa chỉ ghi
|                             nhật ký và dịch rate limit, không cho qua hay chặn gì.
|
| Không qua bước 3, 4, 5, 6 hoặc 10 thì request nhận 401 JSON kèm `WWW-Authenticate` trỏ tới PRM
| (`App\Http\Middleware\Mcp\AddWwwAuthenticateHeader`, render JSON ở `bootstrap/app.php`); có gửi
| bearer mà bearer không dùng được thì header mang thêm `error="invalid_token"`. Token hợp lệ nhưng
| thiếu `mcp:use` dừng ở bước 7 với 403 kèm `error="insufficient_scope"` (TransportTest,
| OAuthMetadataTest, ClientRegistrationTest, AccessControlTest).
|
| Quyền GHI (bốn tool ghi, R13) không kiểm ở đây mà ở từng lần liệt kê và gọi tool:
| `App\Mcp\Tools\Concerns\CrmTool::shouldRegister()` và `App\Mcp\Methods\CrmToolInvoker`.
*/

Mcp::web('/'.CrmServer::PATH, CrmServer::class)->middleware([
    CheckOrigin::class,
    ThrottleMcpAuthenticationFailures::class,
    RequireBearerToken::class,
    'auth:mcp',
    EnsureTokenAudience::class,
    EnsureMcpClient::class,
    CheckToken::using('mcp:use'),
    AuditToolCall::class,
    ThrottleMcp::class,
    EnsureMcpAccess::class,
]);

/*
|--------------------------------------------------------------------------
| Metadata OAuth công khai (R7, Task 2): PRM (RFC 9728) và AS metadata (RFC 8414)
|--------------------------------------------------------------------------
|
| Bốn URI CỤ THỂ, không mẫu `{path}`. `Mcp::oauthRoutes()` của gói (app KHÔNG gọi nó: đăng ký động
| là route riêng ở cuối tệp) chỉ nhường hai route GỐC khi app đã khai, còn hai route lồng
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

/*
|--------------------------------------------------------------------------
| Đăng ký client động (R7, Task 3): DCR, RFC 7591
|--------------------------------------------------------------------------
|
| Controller của app, không phải `OAuthRegisterController` của gói (vì sao: docblock của
| `RegisterClientController`); app không gọi `Mcp::oauthRoutes()`, nên route của gói không tồn tại và
| không đè được bốn route metadata ở trên (rà soát Task 2, m6). Redirect URI so khớp chính xác
| allowlist (`config/vkcrm.php`, `mcp.redirect_uris`), client mang cờ `is_mcp`, throttle mười lần một
| giờ theo IP. Ngoài nhóm `web` (lý lẽ §10.7 trong `StaffTwoFactorEscapeRoutesTest`).
*/

Route::post('/'.McpEndpoint::REGISTRATION_PATH, RegisterClientController::class)
    ->middleware('throttle:'.RegisterClientController::RATE_LIMITER)
    ->name('mcp.oauth.register');
