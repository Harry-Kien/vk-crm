<?php

namespace App\Support\Mcp;

use App\Mcp\Servers\CrmServer;
use Filament\Facades\Filament;

/**
 * M11 R7 — MỘT URL chuẩn cho máy chủ MCP và máy chủ uỷ quyền OAuth, dựng từ CẤU HÌNH, không từ
 * request đang phục vụ.
 *
 * Mọi chỗ khai URL ra ngoài đọc từ đây: `resource` và `authorization_servers` của PRM (RFC 9728),
 * `issuer` và các điểm cuối của AS metadata (RFC 8414), `iss` trong phản hồi uỷ quyền (RFC 9207),
 * `aud` của access token, phép kiểm `resource` (RFC 8707) và `aud`, và `resource_metadata` trong
 * `WWW-Authenticate`. `url()` của Laravel lấy scheme + host của REQUEST hiện tại: khi
 * `ADMIN_DOMAIN`/`PORTAL_DOMAIN` tách hai tên miền, một client gọi qua tên miền cổng khách sẽ nhận
 * một URL khác URL chuẩn (rà soát Task 1, m6) — cùng lỗi mà `App\Support\PortalUrl` đã sửa cho
 * liên kết trong thư.
 *
 * **Phán quyết (Task 2): gốc chuẩn là tên miền QUẢN TRỊ.** `ADMIN_DOMAIN` khi có, còn không thì
 * host (kèm cổng) của `APP_URL`; scheme luôn lấy từ `APP_URL`. Lý do: màn hình đồng ý
 * `/oauth/authorize` hỏi phiên nhân sự (guard `web`, panel `/admin`), nên máy chủ uỷ quyền phải ở
 * tên miền có phiên đó; `/mcp` đứng cùng gốc để `issuer` và `resource` chung một origin. Không thêm
 * biến môi trường mới: hai giá trị trên đã là nguồn sự thật của tên miền. Route `/mcp` và
 * `/.well-known/*` vẫn trả lời trên mọi host (không ràng `domain()`): một client trỏ nhầm tên miền
 * nhận metadata mang URL chuẩn, khác URL nó đang gọi, và client phải từ chối khi `resource` của PRM
 * lệch URL nó đã dùng (RFC 9728 §3.3). Token cấp ra vẫn chỉ mang `aud` chuẩn.
 *
 * Chỉ scheme, host, cổng của `APP_URL` được dùng; đường dẫn (nếu có) bị bỏ, vì app chạy ở gốc tên
 * miền (`/admin`, `/portal`, `/mcp`).
 */
final class McpEndpoint
{
    /** Đường dẫn DCR (RFC 7591). Task 3 đăng ký route ở đúng đường dẫn này. */
    public const REGISTRATION_PATH = 'oauth/register';

    /**
     * Slug của trang "Kết nối AI của tôi" trong panel `/admin` (Task 15): trang hiện chính sách dùng
     * AI và ô cam kết (R12). Màn hình đồng ý (Task 4) trỏ tới đây từ trước khi trang tồn tại; trang của
     * Task 15 khai ĐÚNG slug này.
     */
    public const MY_AI_CONNECTIONS_SLUG = 'ket-noi-ai-cua-toi';

    /** Scheme + host [+ cổng], chữ thường, không `/` cuối. Ví dụ `https://khachhang.luatvukhang.com`. */
    public static function origin(): string
    {
        $app = parse_url((string) config('app.url'));
        $scheme = strtolower($app['scheme'] ?? 'https');
        $adminDomain = config('vkcrm.admin_domain');

        if (is_string($adminDomain) && $adminDomain !== '') {
            return $scheme.'://'.strtolower($adminDomain);
        }

        return $scheme.'://'.strtolower($app['host'] ?? 'localhost').(isset($app['port']) ? ':'.$app['port'] : '');
    }

    /** Issuer của máy chủ uỷ quyền (RFC 8414 §2): đúng gốc chuẩn, không đường dẫn. */
    public static function issuer(): string
    {
        return self::origin();
    }

    /** URL của máy chủ MCP, cũng là `resource` (RFC 8707) và phần tử thứ hai của `aud`. Không `/` cuối. */
    public static function resource(): string
    {
        return self::origin().'/'.CrmServer::PATH;
    }

    /** URL của PRM mà `WWW-Authenticate` trỏ tới (RFC 9728 §5.1). */
    public static function protectedResourceMetadataUrl(): string
    {
        return self::origin().'/.well-known/oauth-protected-resource/'.CrmServer::PATH;
    }

    public static function authorizationEndpoint(): string
    {
        return self::origin().route('passport.authorizations.authorize', absolute: false);
    }

    public static function tokenEndpoint(): string
    {
        return self::origin().route('passport.token', absolute: false);
    }

    public static function registrationEndpoint(): string
    {
        return self::origin().'/'.self::REGISTRATION_PATH;
    }

    /**
     * Trang đăng nhập nhân sự mà một khách vãng lai ở `/oauth/authorize` được đưa tới (Task 4,
     * `bootstrap/app.php`). Filament không có route `login` [PL:79].
     *
     * Có `ADMIN_DOMAIN`: trên tên miền quản trị — cùng host với {@see self::authorizationEndpoint()}
     * mà AS metadata quảng bá, nên cookie phiên (chỉ theo host, `SESSION_DOMAIN` trống) đặt lúc đăng
     * nhập tới được màn hình đồng ý, và URL "intended" cất trong phiên đó còn nguyên sau bước 2FA.
     * Không có: trên CHÍNH host của request, cùng lý do.
     */
    public static function staffLoginUrl(): string
    {
        return self::adminUrl(route('filament.admin.auth.login', absolute: false));
    }

    /** Trang "Kết nối AI của tôi" (chính sách dùng AI, cam kết R12); host như {@see self::staffLoginUrl()}. */
    public static function myAiConnectionsUrl(): string
    {
        return self::adminUrl('/'.Filament::getPanel('admin')->getPath().'/'.self::MY_AI_CONNECTIONS_SLUG);
    }

    /** Một đường dẫn của panel `/admin`: trên gốc chuẩn khi tách tên miền, trên host của request khi không. */
    private static function adminUrl(string $path): string
    {
        $adminDomain = config('vkcrm.admin_domain');

        return is_string($adminDomain) && $adminDomain !== '' ? self::origin().$path : url($path);
    }

    /**
     * Một giá trị `resource` (RFC 8707) có trỏ đúng máy chủ MCP này không.
     *
     * Khớp khi, sau khi đưa scheme và host về chữ thường (RFC 3986 §6.2.2.1: hai phần đó không phân
     * biệt hoa thường) và bỏ ĐÚNG MỘT `/` cuối của đường dẫn, giá trị bằng {@see self::resource()}.
     * Bỏ một `/` cuối vì Laravel định tuyến `/mcp/` vào chính `/mcp`: người dùng gõ URL kèm `/`
     * cuối vào client thì client gửi `resource` y như vậy, và đó vẫn là máy chủ này. Mọi thứ khác
     * đều KHÔNG khớp: scheme khác (http/https), host khác (kể cả tên miền con giả), cổng khác (cổng
     * mặc định không được chuẩn hoá), đường dẫn khác, có query, có fragment (RFC 8707 §2 cấm), có
     * thông tin người dùng.
     */
    public static function isResource(string $value): bool
    {
        $parts = parse_url($value);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])
            || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])
            || str_contains($value, '?') || str_contains($value, '#')) {
            return false;
        }

        $path = $parts['path'] ?? '';

        if (str_ends_with($path, '/')) {
            $path = substr($path, 0, -1);
        }

        $normalized = strtolower($parts['scheme']).'://'.strtolower($parts['host'])
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .$path;

        return $normalized === self::resource();
    }
}
