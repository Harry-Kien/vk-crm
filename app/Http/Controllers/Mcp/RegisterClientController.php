<?php

namespace App\Http\Controllers\Mcp;

use App\Actions\Mcp\RegisterMcpClient;
use App\Exceptions\McpRedirectUriNotAllowed;
use App\Support\Mcp\McpEndpoint;
use App\Support\Mcp\RedirectUriAllowlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Registrar;

/**
 * M11 R7 (Task 3) — đăng ký client động (DCR, RFC 7591) ở `POST /oauth/register`
 * ({@see McpEndpoint::REGISTRATION_PATH}, điểm AS metadata quảng bá ở `registration_endpoint`).
 *
 * **Thay hẳn `Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController` của gói**, không bọc nó:
 * phép kiểm của gói so TIỀN TỐ (`Str::startsWith`) với `mcp.redirect_domains`, nên `/../x`, query hay
 * một đường dẫn bất kỳ dưới tên miền được phép đều lọt; và nó không gắn được cờ `is_mcp`. App không
 * gọi `Mcp::oauthRoutes()` nên route của gói không bao giờ được đăng ký (`routes/ai.php`).
 *
 * Hình dạng giữ theo controller của gói (đã chạy với Claude): JSON vào, 201 với `client_id`,
 * `grant_types`, `response_types`, `redirect_uris`, `scope`, `token_endpoint_auth_method: none`, thêm
 * `client_name` đã lưu (RFC 7591 §3.2.1 đòi trả mọi metadata đã đăng ký). Trường client xin mà máy
 * chủ không làm theo (`grant_types`, `token_endpoint_auth_method`, `logo_uri`…) bị thay hoặc bỏ qua,
 * như RFC 7591 §2 cho phép: client luôn công khai, chỉ `authorization_code` + `refresh_token`
 * ({@see RegisterMcpClient}).
 *
 * Lỗi (RFC 7591 §3.2.2), 400 JSON:
 * - `invalid_redirect_uri`: `redirect_uris` thiếu, rỗng, không phải danh sách, quá
 *   {@see self::MAX_REDIRECT_URIS} mục, có mục không phải chuỗi, hoặc có mục ngoài allowlist
 *   ({@see RedirectUriAllowlist}). Một mục sai là cả lần đăng ký thất bại, không client nào được tạo.
 * - `invalid_client_metadata`: `client_name` không phải chuỗi hoặc dài quá 255 ký tự — độ dài cột
 *   `oauth_clients.name`; MariaDB strict biến chuỗi dài hơn thành lỗi 500. Không khai `client_name`
 *   thì tên là host của redirect URI đầu tiên. Trường `name` (ngoài RFC) mà controller của gói nhận
 *   thì bỏ qua.
 * `error_description` là chữ ASCII tiếng Anh, không qua `lang/vi`: RFC 7591 §3.2.2 định nghĩa nó là
 * "Human-readable ASCII text ... used for debugging", đọc bởi người viết client (rà soát Task 2, m1).
 *
 * Throttle theo IP: {@see self::REGISTRATIONS_PER_HOUR} lần một giờ, đếm MỌI lần gọi kể cả lần hỏng
 * (limiter {@see self::RATE_LIMITER}, đăng ký ở `AppServiceProvider::boot()`); quá thì 429 kèm
 * `Retry-After` và `X-RateLimit-*` ({@see self::tooManyRegistrations()}). DCR tạo một client mới ở MỖI
 * lần kết nối [PL:67], nên mười lần một giờ là dư cho một văn phòng, và route của gói không có
 * throttle nào [PL:54].
 *
 * Ngoài nhóm `web`: không phiên, không cookie, không CSRF; không đọc gì của người dùng.
 */
final class RegisterClientController
{
    public const RATE_LIMITER = 'mcp-client-registration';

    public const REGISTRATIONS_PER_HOUR = 10;

    public const MAX_REDIRECT_URIS = 10;

    /** Độ dài cột `oauth_clients.name` (`string`, migration của Passport). */
    public const MAX_NAME_LENGTH = 255;

    public function __invoke(Request $request, RegisterMcpClient $register): JsonResponse
    {
        // `required` loại cả thiếu lẫn danh sách rỗng; `list` loại chuỗi và đối tượng có khoá.
        $validator = Validator::make($request->all(), [
            'redirect_uris' => ['required', 'list', 'max:'.self::MAX_REDIRECT_URIS],
            'redirect_uris.*' => ['string'],
            'client_name' => ['nullable', 'string', 'max:'.self::MAX_NAME_LENGTH],
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();

            return $errors->has('redirect_uris') || $errors->has('redirect_uris.*')
                ? self::error('invalid_redirect_uri', 'redirect_uris must be a list of 1 to '.self::MAX_REDIRECT_URIS.' URI strings.')
                : self::error('invalid_client_metadata', 'client_name must be a string of at most '.self::MAX_NAME_LENGTH.' characters.');
        }

        /** @var list<string> $redirectUris */
        $redirectUris = $validator->validated()['redirect_uris'];
        // URI ngoài allowlist (có thể không có host) làm `handle()` ném trước khi tên được dùng.
        $name = $request->input('client_name') ?? (string) parse_url($redirectUris[0], PHP_URL_HOST);

        try {
            $client = $register->handle((string) $name, $redirectUris);
        } catch (McpRedirectUriNotAllowed $exception) {
            return self::error('invalid_redirect_uri', $exception->getMessage());
        }

        return response()->json([
            'client_id' => (string) $client->getKey(),
            'client_name' => $client->name,
            'redirect_uris' => $client->redirect_uris,
            'grant_types' => $client->grant_types,
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'scope' => Registrar::OAUTH_SCOPE,
        ], 201);
    }

    /**
     * Phản hồi 429 của limiter {@see self::RATE_LIMITER}: JSON cùng hình dạng lỗi của RFC 7591 (dù RFC
     * không định nghĩa mã cho trường hợp này), giữ nguyên `Retry-After` và `X-RateLimit-*` mà
     * `ThrottleRequests` truyền vào.
     *
     * @param  array<string, int|string>  $headers
     */
    public static function tooManyRegistrations(Request $request, array $headers): JsonResponse
    {
        return response()->json([
            'error' => 'too_many_requests',
            'error_description' => 'Too many client registrations from this address. Retry after the time given in Retry-After.',
        ], 429, $headers);
    }

    private static function error(string $code, string $description): JsonResponse
    {
        return response()->json(['error' => $code, 'error_description' => $description], 400);
    }
}
