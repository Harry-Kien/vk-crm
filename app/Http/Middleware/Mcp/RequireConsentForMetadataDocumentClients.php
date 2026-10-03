<?php

namespace App\Http\Middleware\Mcp;

use App\Actions\Mcp\ResolveClientIdMetadataDocument;
use App\Support\Mcp\McpClientRepository;
use Closure;
use Illuminate\Http\Request;
use Laravel\Passport\Passport;
use Symfony\Component\HttpFoundation\Response;

/**
 * M11 R7 (Task 5, vòng sửa 1) — client CIMD không bao giờ được Passport tự duyệt ở `GET /oauth/authorize`.
 *
 * `AuthorizationController::authorize()` của Passport 13.8.0 (dòng 84-86) cấp mã ngay, không hiện màn hình đồng
 * ý, khi người đang đăng nhập đã có access token còn hạn, chưa thu hồi cho đúng dòng client đó với các scope được
 * xin (`hasGrantedScopes()`), trừ khi `prompt` có `consent`. Với client DCR, dòng client là của riêng một bản đăng
 * ký (nền tảng đăng ký khi kết nối, Task 3), UUID của nó không công khai và redirect phải khớp bản đăng ký. Với
 * CIMD thì `client_id` là một URL CÔNG KHAI, và mọi nhân sự của cùng nền tảng dùng chung MỘT dòng
 * ({@see ResolveClientIdMetadataDocument}). Khi một nhân sự đang có token, ai khiến trình duyệt của người đó mở
 * `/oauth/authorize` với URL ấy sẽ nhận mã, với PKCE của chính họ, mà nhân sự không thấy gì. Ví dụ: một tiến trình
 * khác trên máy mở cổng loopback riêng (với tài liệu của Claude Code, máy chủ nhận mọi cổng localhost), hay một
 * phiên Claude/ChatGPT của người khác. Đường tự duyệt còn bỏ qua mọi điều kiện từ chối và dòng nhật ký của màn
 * hình đồng ý (Task 4).
 *
 * Lớp này áp khi `client_id` (query, chỗ league đọc) là client CIMD: id có `://` (đúng luật định tuyến của
 * {@see McpClientRepository}), hoặc UUID của một dòng có `metadata_url`, vì dòng CIMD cũng tra được bằng UUID qua
 * đường Passport, và UUID đó nằm trong `aud` của mọi token cấp cho nền tảng. Khi đó:
 * - chuỗi `prompt` có chứa `none` → chuyển hướng `error=consent_required` (OpenID Connect Core §3.1.2.6) về
 *   redirect URI ĐÃ KIỂM, đúng cách {@see ValidateOAuthParameters::rejectAuthorization()} báo lỗi. Không mã nào
 *   được cấp, không gì được lưu vào phiên. Không thể chỉ thêm `consent`: gặp `none`, Passport bỏ mọi giá trị khác
 *   của `prompt` rồi tự duyệt (dòng 57-59);
 * - còn lại → nối ` consent` vào cuối `prompt` (các giá trị khác, như `login`, giữ nguyên), nên Passport luôn hiện
 *   màn hình đồng ý. Passport đọc `prompt` qua `$request->string()` (tức `input()`), nên giá trị được ghi bằng
 *   `merge()`, vào đúng nguồn mà `input()` đọc trước. `prompt` không phải chuỗi (mảng) thì bị thay bằng
 *   ` consent`; Passport với một mảng thì lỗi 500.
 *
 * **Vì sao so theo đoạn con, không tách giá trị như Passport.** Passport tách `prompt` bằng
 * `explode(' ')->map(trim(...))->filter()` (dòng 52). `Collection::map()` truyền KHOÁ làm đối số thứ hai, nên
 * `trim()` không cắt khoảng trắng mà cắt chữ số của vị trí: `none0` (vị trí 0) và `consent none1` (vị trí 1) là
 * `none` với Passport, còn `consent <tab>none` thì không. Một bản tách "đúng" ở đây sẽ lệch với Passport và mở lại
 * đường tự duyệt. Mọi giá trị Passport tách ra, cắt kiểu gì, cũng là một đoạn con của chuỗi `prompt`, nên chuỗi
 * không chứa `none` thì Passport không bao giờ thấy `none`. Chữ ` consent` nối thêm không có chữ số nào, nên luôn
 * là `consent` với Passport. Ngoài `none`, không giá trị `prompt` chuẩn nào (`login`, `consent`, `select_account`
 * của OpenID Connect Core, `create`) chứa chữ `none`.
 *
 * Client DCR và `passport:client` giữ nguyên hành vi của Passport (nhánh tự duyệt ở GET có test ghim trong
 * `OAuthMetadataTest`, và test đối chứng DCR trong `ClientIdMetadataDocumentTest`). Đăng ký cuối
 * `passport.middleware` (`config/passport.php`), bên trong {@see AddIssuerToAuthorizationResponse}, nên lỗi
 * `consent_required` cũng mang `iss`. Chỉ hành động ở `passport.authorizations.authorize`.
 */
class RequireConsentForMetadataDocumentClients
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('passport.authorizations.authorize') || ! self::isMetadataDocumentClient($request->query('client_id'))) {
            return $next($request);
        }

        $prompt = $request->input('prompt');
        $prompt = is_string($prompt) ? $prompt : '';

        if (str_contains($prompt, 'none')) {
            return ValidateOAuthParameters::rejectAuthorization($request, $next, 'consent_required', __('mcp.http.consent_required'));
        }

        $request->merge(['prompt' => $prompt.' consent']);

        return $next($request);
    }

    private static function isMetadataDocumentClient(mixed $clientId): bool
    {
        if (! is_string($clientId)) {
            return false;
        }

        return str_contains($clientId, '://')
            || Passport::client()->newQuery()->whereKey($clientId)->whereNotNull('metadata_url')->exists();
    }
}
