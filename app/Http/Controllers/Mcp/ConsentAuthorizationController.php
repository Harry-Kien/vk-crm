<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Middleware\Mcp\RequireConsentForMetadataDocumentClients;
use App\Http\Responses\Mcp\ConsentScreenResponse;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Laravel\Passport\Scope;

/**
 * `GET /oauth/authorize` của Passport, trừ đúng một điều (M11 Task 4): KHÔNG BAO GIỜ tự duyệt.
 *
 * `AuthorizationController::authorize()` của Passport 13.8.0 (dòng 84-86) cấp mã ngay, không hiện màn
 * hình nào, khi người của phiên đã có access token còn hạn, chưa thu hồi cho đúng client đó với các
 * scope được xin (`hasGrantedScopes()`), trừ khi `prompt` có `consent`. Nhánh đó bỏ qua mọi điều kiện
 * từ chối và dòng nhật ký của màn hình đồng ý ({@see ConsentScreenResponse}): một người vừa bị đổi
 * phiên bản chính sách, hay một phiên chỉ có mật khẩu, vẫn nhận mã nếu còn token cũ. Với client loopback
 * (cổng bị bỏ qua, RFC 8252) thì một tiến trình khác trên máy mở `/oauth/authorize` với client đó và
 * cổng của nó sẽ nhận mã, với PKCE của chính nó, mà nhân sự không thấy gì (rà soát Task 2 m7, Task 5
 * I1 và m2).
 *
 * Trả `false` ở đây đóng nhánh đó cho MỌI client (DCR, CIMD, `passport:client`), theo cấu trúc chứ
 * không theo cách đọc chuỗi `prompt`: mọi lần uỷ quyền thành công đi qua màn hình đồng ý; `prompt=none`
 * nhận `consent_required` từ chính Passport (dòng 89-90). `Client::skipsAuthorization()` của Passport
 * luôn `false` (app không thay model client). {@see RequireConsentForMetadataDocumentClients} (Task 5)
 * vẫn đứng trước, trả `consent_required` cho client CIMD từ trước khi có phiên.
 *
 * App bind lớp này thay lớp của gói (`AppServiceProvider`): route của Passport phân giải controller qua
 * container, nên không route nào phải khai lại.
 */
class ConsentAuthorizationController extends AuthorizationController
{
    /**
     * @param  Scope[]  $scopes
     */
    protected function hasGrantedScopes(Authenticatable $user, Client $client, array $scopes): bool
    {
        return false;
    }
}
