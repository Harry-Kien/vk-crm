<?php

namespace App\Http\Controllers\Mcp;

use App\Actions\Mcp\RecordMcpConnectionDecision;
use App\Http\Responses\Mcp\ConsentScreenResponse;
use App\Support\Mcp\ConsentRequest;
use App\Support\Mcp\McpAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Exceptions\InvalidAuthTokenException;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use League\OAuth2\Server\AuthorizationServer;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nút "Đồng ý" của màn hình đồng ý OAuth (`POST /oauth/authorize`, M11 Task 4) — bước của Passport cộng
 * ba việc, theo thứ tự:
 *
 *  1. Lấy yêu cầu ra khỏi phiên bằng `auth_token` DÙNG MỘT LẦN (`getAuthRequestFromSession()` của gói:
 *     sai mã, hay mã đã dùng → `InvalidAuthTokenException`, 403);
 *  2. yêu cầu phải thuộc ĐÚNG người của phiên ({@see ConsentRequest::belongsTo()}), không thì cùng
 *     ngoại lệ đó — bước của gói không so lại;
 *  3. kiểm lại MỌI điều kiện từ chối ở chính lúc bấm ({@see McpAccess::consentRefusal()}): giữa lúc mở
 *     màn hình và lúc bấm, quản trị có thể đã tắt AI của người này, hạ công tắc, đổi phiên bản chính
 *     sách; và một POST ép tay không đi qua màn hình. Từ chối → 403 với câu lý do, không form nào,
 *     một dòng `mcp_connection_denied`; yêu cầu đã bị lấy ra nên không thể bấm lại.
 *
 * Đạt cả ba thì duyệt đúng như phần còn lại của `ApproveAuthorizationController::approve()` 13.8.0
 * (`setAuthorizationApproved(true)`, rồi `completeAuthorizationRequest()` qua `withErrorHandling()`),
 * trong MỘT transaction với dòng `mcp_connection_authorized` ({@see RecordMcpConnectionDecision}):
 * ghi nhật ký hỏng thì mã uỷ quyền vừa lưu cũng không còn, nên không có kết nối nào thiếu dòng nhật ký.
 *
 * Route của Passport có sẵn `web` + `auth:web` (khách vãng lai về trang đăng nhập `/admin`); app bind
 * lớp này thay lớp của gói (`AppServiceProvider`).
 */
class ApproveConsentController extends ApproveAuthorizationController
{
    public function __construct(
        AuthorizationServer $server,
        private readonly RecordMcpConnectionDecision $record,
    ) {
        parent::__construct($server);
    }

    public function approve(Request $request, ResponseInterface $psrResponse): Response
    {
        $authRequest = $this->getAuthRequestFromSession($request);
        $account = $request->user();

        if ($account === null || ! ConsentRequest::belongsTo($authRequest, $account)) {
            throw InvalidAuthTokenException::different();
        }

        $clientId = ConsentRequest::clientId($authRequest);
        $redirectUri = ConsentRequest::redirectUri($authRequest);
        $refusal = McpAccess::consentRefusal($account);

        if ($refusal !== null) {
            $this->record->denied($account, $clientId, $redirectUri, $refusal);

            return ConsentScreenResponse::render($request, $account, $authRequest, null, $refusal);
        }

        // `consentRefusal()` rỗng nghĩa là `$account` là một nhân sự (`User`): lý do đầu tiên của nó là
        // `NotStaff` cho mọi thứ khác, nên `authorized(User …)` bên dưới luôn nhận đúng kiểu.
        $authRequest->setAuthorizationApproved(true);

        return DB::transaction(function () use ($authRequest, $psrResponse, $account, $clientId, $redirectUri): Response {
            $response = $this->withErrorHandling(fn () => $this->convertResponse(
                $this->server->completeAuthorizationRequest($authRequest, $psrResponse)
            ), $authRequest->getGrantTypeId() === 'implicit');

            $this->record->authorized($account, $clientId, $redirectUri);

            return $response;
        });
    }
}
