<?php

namespace App\Http\Responses\Mcp;

use App\Actions\Mcp\RecordMcpConnectionDecision;
use App\Enums\AiAccessMode;
use App\Enums\McpAccessRefusal;
use App\Enums\McpPlatform;
use App\Http\Controllers\Mcp\ApproveConsentController;
use App\Models\User;
use App\Support\Mcp\ConsentRequest;
use App\Support\Mcp\McpAccess;
use App\Support\Mcp\McpEndpoint;
use App\Support\Mcp\McpSwitches;
use App\Support\Security\ContentSecurityPolicy;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Màn hình đồng ý OAuth của máy chủ MCP (M11 Task 4) — `resources/views/mcp/authorize.blade.php`,
 * viết mới (không publish view của Passport: CSS của nó đang hỏng, issue #348 [PL:58]).
 *
 * Passport gọi lớp này ở `GET /oauth/authorize` sau khi đã kiểm yêu cầu (client, redirect URI, PKCE)
 * và đã có người của phiên `web`, ngay sau khi cất `authToken` và yêu cầu vào phiên. App bind lớp này
 * cho `Laravel\Passport\Contracts\AuthorizationViewResponse` (`AppServiceProvider`). Màn hình hiện:
 *  - nền tảng suy từ host redirect và CHÍNH host đó (không bao giờ `client_name` tự khai), cảnh báo
 *    riêng khi redirect là loopback;
 *  - "AI sẽ hành động với danh nghĩa và quyền của anh/chị" [DC:139], chế độ hiện tại (`ai_access`),
 *    đường dẫn tới chính sách dùng AI;
 *  - hai form gửi về `/oauth/authorize` (POST "Đồng ý", DELETE "Từ chối"), có CSRF và `auth_token`
 *    dùng một lần của Passport.
 *
 * **Từ chối** ({@see McpAccess::consentRefusal()} khác `null`): 403, câu lý do, KHÔNG có nút "Đồng ý";
 * nút "Từ chối" còn, để client AI nhận `access_denied` ngay thay vì treo. Mỗi lần từ chối ghi một dòng
 * `mcp_connection_denied` kèm lý do ({@see RecordMcpConnectionDecision}). Bấm "Đồng ý" ép tay vẫn bị
 * {@see ApproveConsentController} kiểm lại.
 *
 * CSP: trang mở `form-action` tới ĐÚNG origin của redirect URI của yêu cầu này
 * ({@see ContentSecurityPolicy::allowFormActionTo()}), vì cả hai nút kết thúc bằng một chuyển hướng
 * về client. Chống nhúng (`X-Frame-Options: DENY`, `frame-ancestors 'none'`) là header toàn cục. View
 * không có script nào.
 */
final class ConsentScreenResponse implements AuthorizationViewResponse
{
    /** @var array<string, mixed> */
    private array $parameters = [];

    public function __construct(private readonly RecordMcpConnectionDecision $record) {}

    /** @param  array<string, mixed>  $parameters */
    public function withParameters(array $parameters = []): static
    {
        $this->parameters = $parameters;

        return $this;
    }

    /** @param  Request  $request */
    public function toResponse($request): Response
    {
        $account = $this->parameters['user'] ?? null;
        $authToken = $this->parameters['authToken'] ?? null;
        $authRequest = ConsentRequest::peek($request);

        if (! $account instanceof Model || ! $account instanceof Authenticatable || ! is_string($authToken) || $authRequest === null) {
            throw new LogicException('Màn hình đồng ý thiếu người, auth_token hay yêu cầu uỷ quyền trong phiên.');
        }

        $refusal = McpAccess::consentRefusal($account);

        if ($refusal !== null) {
            $this->record->denied($account, ConsentRequest::clientId($authRequest), ConsentRequest::redirectUri($authRequest), $refusal);
        }

        return self::render($request, $account, $authRequest, $authToken, $refusal);
    }

    /**
     * Vẽ màn hình cho một yêu cầu uỷ quyền. `$authToken` là `null` khi yêu cầu đã được lấy ra khỏi phiên
     * (bước "Đồng ý" vừa từ chối): khi đó không còn form nào để gửi, và CSP không mở gì.
     */
    public static function render(Request $request, Authenticatable $account, AuthorizationRequestInterface $authRequest, ?string $authToken, ?McpAccessRefusal $refusal): Response
    {
        $redirectUri = ConsentRequest::redirectUri($authRequest);
        $origin = ConsentRequest::origin($redirectUri);

        if ($authToken !== null && $origin !== null) {
            ContentSecurityPolicy::allowFormActionTo($request, $origin);
        }

        $staff = $account instanceof User ? $account : null;

        return response()->view('mcp.authorize', [
            'platform' => McpPlatform::fromRedirectUri($redirectUri),
            'host' => ConsentRequest::displayHost($redirectUri),
            'staff' => $staff,
            'mode' => $staff?->ai_access,
            'writeSwitchOff' => $staff?->ai_access === AiAccessMode::ReadWrite && ! McpSwitches::writeEnabled(),
            'refusal' => $refusal,
            'authToken' => $authToken,
            'policyUrl' => McpEndpoint::myAiConnectionsUrl(),
        ], $refusal === null ? 200 : 403);
    }
}
