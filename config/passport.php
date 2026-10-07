<?php

use App\Http\Middleware\Mcp\AddIssuerToAuthorizationResponse;
use App\Http\Middleware\Mcp\RequireConsentForMetadataDocumentClients;
use App\Http\Middleware\Mcp\RestrictConsentScreenToAdminIps;
use App\Http\Middleware\Mcp\RestrictOAuthGrantTypes;
use App\Http\Middleware\Mcp\ValidateOAuthParameters;
use Illuminate\Session\Middleware\AuthenticateSession;

/*
|-------------------------------------------------------------------------------------------
| BẢN PUBLISH CỦA laravel/passport — ĐÃ SỬA ĐÚNG MỘT KHOÁ (M11 Task 1, Task 2, Task 4, Task 5)
|-------------------------------------------------------------------------------------------
|
| Sinh bằng `artisan vendor:publish --tag=passport-config`, rồi đổi đúng `middleware`. Mọi khoá
| khác giữ nguyên mặc định; `tests/Feature/Config/McpPackageConfigTest.php` đối chiếu từng khoá với
| chính tệp còn nằm trong `vendor/`. Phải là BẢN ĐẦY ĐỦ vì `mergeConfigFrom()` gộp NÔNG (lý lẽ đầy
| đủ ở đầu `config/livewire.php`).
|
| Passport là máy chủ uỷ quyền OAuth của MỘT việc duy nhất: cổng MCP cho nhân sự (kế hoạch M11, R1,
| R7). Các thiết lập không nằm trong tệp này (hạn token, tắt device code / personal access) ở
| `App\Providers\AppServiceProvider`, kèm lý do.
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Passport Guard
    |--------------------------------------------------------------------------
    |
    | Here you may specify which authentication guard Passport will use when
    | authenticating users. This value should correspond with one of your
    | guards that is already present in your "auth" configuration file.
    |
    */

    // Màn hình đồng ý `/oauth/authorize` hỏi phiên NHÂN SỰ (`web`, panel `/admin`), không bao giờ
    // phiên khách (`client`). Giữ nguyên mặc định, nhưng có test ghim (McpPackageConfigTest).
    'guard' => 'web',

    // Middleware của nhóm route Passport: đứng trước MỌI route của gói, mỗi lớp chỉ hành động ở route
    // của nó (lý do ở docblock từng lớp). Thứ tự có nghĩa (McpPackageConfigTest ghim):
    // - M11 Task 4: `ADMIN_IP_ALLOWLIST` phủ ba route `/oauth/authorize` (màn hình đồng ý của nhân sự);
    //   ĐẦU danh sách, nên một IP ngoài danh sách nhận 404 trước mọi bước khác;
    // - M11 R1: `/oauth/token` chỉ nhận `authorization_code` và `refresh_token` (vì sao không tắt được
    //   `client_credentials` bằng cờ của gói);
    // - M11 R7 (Task 2): gắn `iss` (RFC 9207) vào mọi phản hồi uỷ quyền; đứng NGOÀI lớp kế tiếp để cả
    //   lỗi mà lớp đó chuyển hướng về client cũng mang `iss`;
    // - M11 R7 (Task 2): PKCE chỉ S256, bắt buộc với mọi client; `resource` (RFC 8707) chỉ được là URL
    //   MCP chuẩn, ở `/oauth/authorize` và `/oauth/token`;
    // - M11 R7 (Task 5): client CIMD (một dòng dùng chung cho mọi nhân sự): `prompt=none` nhận
    //   `consent_required` (mang `iss`) từ trước khi có phiên. Không client nào được tự duyệt là việc của
    //   `App\Http\Controllers\Mcp\ConsentAuthorizationController` (Task 4);
    // - M11 Task 4 (rà soát Task 6, m1): `AuthenticateSession` của Laravel — phiên `web` mang dấu của một
    //   mật khẩu cũ bị đăng xuất ở `/oauth/authorize` (đổi mật khẩu đã thu hồi mọi kết nối AI; một cookie
    //   phiên lấy cắp từ trước không được mở lại kết nối). Lớp này nằm trong danh sách ưu tiên của
    //   Laravel, nên được xếp SAU `StartSession` của nhóm `web` (AuthorizeScreenTest ghim thứ tự thật).
    //   Route không có phiên (`/oauth/token`) thì nó không làm gì.
    'middleware' => [
        RestrictConsentScreenToAdminIps::class,
        RestrictOAuthGrantTypes::class,
        AddIssuerToAuthorizationResponse::class,
        ValidateOAuthParameters::class,
        RequireConsentForMetadataDocumentClients::class,
        AuthenticateSession::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Encryption Keys
    |--------------------------------------------------------------------------
    |
    | Passport uses encryption keys while generating secure access tokens for
    | your application. By default, the keys are stored as local files but
    | can be set via environment variables when that is more convenient.
    |
    */

    'private_key' => env('PASSPORT_PRIVATE_KEY'),

    'public_key' => env('PASSPORT_PUBLIC_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Passport Database Connection
    |--------------------------------------------------------------------------
    |
    | By default, Passport's models will utilize your application's default
    | database connection. If you wish to use a different connection you
    | may specify the configured name of the database connection here.
    |
    */

    'connection' => env('PASSPORT_CONNECTION'),

];
