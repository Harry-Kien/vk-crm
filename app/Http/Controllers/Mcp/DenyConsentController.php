<?php

namespace App\Http\Controllers\Mcp;

use App\Actions\Mcp\RecordMcpConnectionDecision;
use App\Support\Mcp\ConsentRequest;
use Illuminate\Http\Request;
use Laravel\Passport\Exceptions\InvalidAuthTokenException;
use Laravel\Passport\Http\Controllers\DenyAuthorizationController;
use League\OAuth2\Server\AuthorizationServer;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nút "Từ chối" của màn hình đồng ý OAuth (`DELETE /oauth/authorize`, M11 Task 4): bước của Passport
 * (lấy yêu cầu ra khỏi phiên bằng `auth_token` dùng một lần, rồi chuyển hướng về client với
 * `error=access_denied` và `state`), cộng hai việc: yêu cầu phải thuộc đúng người của phiên
 * ({@see ConsentRequest::belongsTo()}, cùng lý do như {@see ApproveConsentController}), và một dòng
 * `mcp_connection_denied` lý do `user` ({@see RecordMcpConnectionDecision}).
 *
 * Dòng nhật ký ghi TRƯỚC khi gọi league: `completeAuthorizationRequest()` với một yêu cầu không được
 * duyệt luôn ném `OAuthServerException::accessDenied()`, và chính ngoại lệ đó (qua `withErrorHandling()`)
 * là phản hồi chuyển hướng. Nút này có cả trên màn hình từ chối, để client AI không phải chờ.
 */
class DenyConsentController extends DenyAuthorizationController
{
    public function __construct(
        AuthorizationServer $server,
        private readonly RecordMcpConnectionDecision $record,
    ) {
        parent::__construct($server);
    }

    public function deny(Request $request, ResponseInterface $psrResponse): Response
    {
        $authRequest = $this->getAuthRequestFromSession($request);
        $account = $request->user();

        if ($account === null || ! ConsentRequest::belongsTo($authRequest, $account)) {
            throw InvalidAuthTokenException::different();
        }

        $this->record->denied($account, ConsentRequest::clientId($authRequest), ConsentRequest::redirectUri($authRequest), null);

        $authRequest->setAuthorizationApproved(false);

        return $this->withErrorHandling(fn () => $this->convertResponse(
            $this->server->completeAuthorizationRequest($authRequest, $psrResponse)
        ), $authRequest->getGrantTypeId() === 'implicit');
    }
}
