<?php

/*
|-------------------------------------------------------------------------------------------
| BẢN PUBLISH CỦA laravel/mcp — ĐÃ SỬA ĐÚNG MỘT KHOÁ (M11 Task 1)
|-------------------------------------------------------------------------------------------
|
| Sinh bằng `artisan vendor:publish --tag=mcp-config`, rồi đổi đúng `redirect_domains`. Mọi khoá
| khác giữ nguyên mặc định; `tests/Feature/Config/McpPackageConfigTest.php` đối chiếu từng khoá với
| chính tệp còn nằm trong `vendor/`. Phải là BẢN ĐẦY ĐỦ vì `mergeConfigFrom()` gộp NÔNG (lý lẽ đầy
| đủ ở đầu `config/livewire.php`).
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Redirect Domains
    |--------------------------------------------------------------------------
    |
    | These domains are the domains that OAuth clients are permitted to use
    | for redirect URIs. Each domain should be specified with its scheme
    | and host. Domains not in this list will raise validation errors.
    |
    | An "*" may be used to allow all domains.
    |
    */

    /*
     * M11 R7: KHÔNG `'*'` (mặc định của gói, nghĩa là DCR nhận mọi redirect URI). Rỗng = DCR của gói
     * từ chối MỌI redirect URI, kể cả nếu ai đó nạp route `/oauth/register` trước khi có allowlist.
     * Phép kiểm của gói chỉ so TIỀN TỐ (`Str::startsWith`), không so chính xác, nên danh sách này
     * không bao giờ là nơi khai allowlist thật: Task 3 dựng allowlist so khớp chính xác ở
     * `config/vkcrm.php` (`mcp.redirect_uris`) và ghi lại câu này.
     */
    'redirect_domains' => [],

    /*
    |--------------------------------------------------------------------------
    | Allowed Custom Schemes
    |--------------------------------------------------------------------------
    |
    | Native desktop OAuth clients like Cursor and VS Code use private-use URI
    | schemes (RFC 8252) for redirect callbacks instead of standard schemes
    | like HTTPS. Here, you may list which custom schemes you will allow.
    |
    */

    'custom_schemes' => [
        // 'claude',
        // 'cursor',
        // 'vscode',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization Server
    |--------------------------------------------------------------------------
    |
    | Here you may configure the OAuth authorization server issuer identifier
    | per RFC 8414. This value appears in your protected resource and auth
    | server metadata endpoints. When null, this defaults to `url('/')`.
    |
    */

    'authorization_server' => null,

    /*
    |--------------------------------------------------------------------------
    | Tool Search
    |--------------------------------------------------------------------------
    |
    | Here you may configure the limits enforced during tool search. The max
    | number of tool calls limits how many tools search requests can call
    | while the maximum output bytes value will limit the result sizes.
    |
    */

    'tool_search' => [
        'max_tool_calls' => 10,
        'max_output_bytes' => 65_536,
    ],

];
