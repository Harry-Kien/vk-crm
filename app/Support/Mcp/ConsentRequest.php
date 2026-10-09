<?php

namespace App\Support\Mcp;

use App\Support\Security\ContentSecurityPolicy;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Laravel\Passport\Bridge\Client as BridgeClient;
use Laravel\Passport\Bridge\Scope as BridgeScope;
use Laravel\Passport\Bridge\User as BridgeUser;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;

/**
 * Đọc yêu cầu uỷ quyền OAuth mà màn hình đồng ý `/oauth/authorize` đang hỏi (M11 Task 4): nó đi tới
 * redirect URI nào, host nào để hiện và ghi nhật ký, origin nào được mở trong CSP `form-action`, và nó
 * thuộc về người nào.
 *
 * Mọi câu trả lời lấy từ ĐỐI TƯỢNG `AuthorizationRequest` mà league/oauth2-server đã kiểm (client,
 * redirect URI khớp bản đăng ký, PKCE) và Passport sẽ duyệt — không từ query thô, không từ
 * `client_name` tự khai.
 */
final class ConsentRequest
{
    /** Khoá phiên nơi `AuthorizationController::authorize()` của Passport cất yêu cầu đã tuần tự hoá. */
    public const SESSION_KEY = 'authRequest';

    /** Đúng danh sách lớp mà Passport cho phép khi giải tuần tự (`RetrievesAuthRequestFromSession`). */
    private const ALLOWED_CLASSES = [
        AuthorizationRequest::class,
        BridgeClient::class,
        BridgeScope::class,
        BridgeUser::class,
    ];

    /**
     * Yêu cầu uỷ quyền mà `GET /oauth/authorize` VỪA cất vào phiên, đọc mà không lấy ra (bước "Đồng ý"
     * hay "Từ chối" của Passport mới lấy ra, đúng một lần). `null` khi phiên không có hay giá trị hỏng.
     */
    public static function peek(Request $request): ?AuthorizationRequestInterface
    {
        $serialized = $request->session()->get(self::SESSION_KEY);

        if (! is_string($serialized)) {
            return null;
        }

        $authRequest = unserialize($serialized, ['allowed_classes' => self::ALLOWED_CLASSES]);

        return $authRequest instanceof AuthorizationRequestInterface ? $authRequest : null;
    }

    /**
     * Redirect URI mà mã (hay lỗi `access_denied`) sẽ đi tới — đúng phép chọn của league
     * (`AuthCodeGrant::completeAuthorizationRequest()`): `redirect_uri` của yêu cầu (đã kiểm khớp bản
     * đăng ký), không có thì URI đầu tiên của client (league chỉ cho vắng mặt khi client có một URI).
     */
    public static function redirectUri(AuthorizationRequestInterface $authRequest): string
    {
        return $authRequest->getRedirectUri()
            ?? (string) Arr::wrap($authRequest->getClient()->getRedirectUri())[0];
    }

    /** `oauth_clients.id` của yêu cầu (với client CIMD cũng là UUID của dòng, không phải URL). */
    public static function clientId(AuthorizationRequestInterface $authRequest): string
    {
        return (string) $authRequest->getClient()->getIdentifier();
    }

    /**
     * Host của redirect, chữ thường, kèm `:cổng` khi URI có cổng (`localhost`, `127.0.0.1:33418`,
     * `claude.ai`). Đây là thứ màn hình hiện ngay cạnh tên nền tảng, và là `redirect_host` của nhật ký.
     */
    public static function displayHost(string $uri): string
    {
        $parts = parse_url($uri);

        if ($parts === false || ! isset($parts['host'])) {
            return '';
        }

        return strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * Origin (`scheme://host[:cổng]`) của redirect, để CSP `form-action` của CHÍNH màn hình này cho
     * phép chuyển hướng sau khi bấm nút ({@see ContentSecurityPolicy}). `null` khi
     * không biểu diễn được thành một nguồn CSP (host IPv6 như `[::1]`: cú pháp nguồn của CSP không có
     * dấu ngoặc vuông).
     */
    public static function origin(string $uri): ?string
    {
        $parts = parse_url($uri);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);

        if (! in_array($scheme, ['http', 'https'], true) || preg_match('/^[a-z0-9.-]+$/D', $host) !== 1) {
            return null;
        }

        return $scheme.'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * Yêu cầu này thuộc ĐÚNG người của phiên đang bấm nút. Passport gắn người vào yêu cầu lúc mở màn
     * hình (`setUser(new Bridge\User(id))`) rồi cất cả yêu cầu vào phiên; bước "Đồng ý" của gói duyệt
     * yêu cầu trong phiên mà không so lại với người của phiên — phiên đổi người giữa hai bước (đăng
     * nhập tài khoản khác trên cùng trình duyệt) sẽ cấp mã cho người đã MỞ màn hình.
     */
    public static function belongsTo(AuthorizationRequestInterface $authRequest, Authenticatable $account): bool
    {
        $owner = $authRequest->getUser();

        return $owner !== null && (string) $owner->getIdentifier() === (string) $account->getAuthIdentifier();
    }
}
