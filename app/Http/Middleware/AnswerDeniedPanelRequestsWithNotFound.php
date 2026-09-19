<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * SPEC §10.10: "Không có quyền và không tồn tại đều trả 404." Mã viết tay trong ứng dụng đã
 * theo luật này từ M3 (`VisibleClientOptions`, `CreateMatter`), nhưng Filament tự
 * `abort(403)` ở `CanAuthorizeAccess`, `CanAuthorizeResourceAccess` và
 * `InteractsWithRecord` — nên cùng một tình huống "không có quyền" có hai câu trả lời khác
 * nhau tuỳ vào việc màn hình do ai viết.
 *
 * Nguy hiểm hơn chuyện không nhất quán: trên trang có `{record}` trong URL, Filament GIẢI BẢN
 * GHI TRƯỚC rồi mới hỏi `canAccess()` (`InteractsWithRecord::mountCanAuthorizeAccess()` mở đầu
 * bằng `$this->getRecord()`). Một bản ghi không giải được là 404; giải được nhưng bị
 * `canAccess()` chặn là 403. Cặp mã đó trả lời đúng câu hỏi SPEC §10.10 cấm trả lời — bản ghi
 * này có tồn tại không — cho đúng người không được biết: kế toán không có quyền nào về khách
 * hàng vẫn phân biệt được một `client_id` có thật với một id bịa ra.
 *
 * Đổi bằng cách đóng gói ở tầng middleware thay vì vá từng trang. Đặt ngay đầu danh sách
 * middleware của panel, tức vị trí THỨ HAI trong đường ống: `Panel::getMiddleware()` tự chèn
 * `panel:{id}` (`SetUpPanel`) lên trước, và phải như vậy thì panel hiện hành mới được dựng
 * trước khi bất cứ ai từ chối.
 *
 * RANH GIỚI — phải đọc là một phạm vi, không phải "mọi cách Filament có để từ chối":
 *
 * - PHỦ: mọi từ chối do middleware của route panel ném ra, kể cả trên request cập nhật
 *   Livewire. `isPersistent: true` đưa middleware này vào danh sách middleware bền của
 *   Livewire, nơi nó đứng trước `Filament\Http\Middleware\Authenticate` — vì thế một tài khoản
 *   vừa bị vô hiệu hoá, hay một người gõ nhầm panel, nhận 404 trên cả trang lẫn request cập
 *   nhật. Có test hành vi bằng request `/livewire/update` thật ở `DenialCodeTest`.
 * - KHÔNG PHỦ, và không thể phủ: từ chối phát sinh BÊN TRONG vòng đời một component Livewire —
 *   `hydrateCanAuthorizeAccess()` của Filament. Livewire chạy middleware bền qua
 *   `Utils::applyMiddleware()`, mà đích của đường ống đó là `fn () => new Response()`, tức một
 *   200 mới tinh; khung của middleware này đã kết thúc trước khi component được hydrate, nên
 *   `abort(403)` ở đó thoát ra ngoài nguyên vẹn.
 *
 * Không đuổi theo phần không phủ được, và đó là một lựa chọn chứ không phải một việc còn dở.
 * Trên đường cập nhật, cặp (403, 404) KHÔNG còn là máy dò sự tồn tại: `$record` là `#[Locked]`
 * và snapshot niêm bằng HMAC `APP_KEY`, nên muốn hỏi về một bản ghi thì phải có snapshot của
 * chính trang bản ghi đó — mà render được trang đó nghĩa là đã qua cổng. Còn cách duy nhất phủ
 * nốt (gắn middleware lên chính route cập nhật của Livewire) sẽ nuốt luôn hai `abort(403)` của
 * `Filament\Schemas\SchemasServiceProvider`, vốn nói về một LỜI GỌI PHƯƠNG THỨC chứ không về
 * sự tồn tại của bản ghi — trong đó có cổng chặn `_startUpload` mà các ô tải tệp của M4 nằm
 * sau, nơi 403 là tín hiệu lạm dụng đáng giữ nguyên.
 *
 * Giới hạn trong middleware của panel cũng là cố ý — 403 vẫn còn nghĩa ở ngoài panel, nơi nó
 * nói về ĐƯỜNG DẪN chứ không về bản ghi: route tải tệp có chữ ký (SPEC §10.4, §11 "Tải tệp")
 * phải trả 403 cho chữ ký hết hạn.
 */
class AnswerDeniedPanelRequestsWithNotFound
{
    public function handle(Request $request, Closure $next): mixed
    {
        try {
            $response = $next($request);
        } catch (AuthorizationException|HttpExceptionInterface $exception) {
            throw $this->isDenial($exception) ? $this->notFound($exception) : $exception;
        }

        // Đường thường đi không phải khối catch ở trên: `Illuminate\Routing\Pipeline` bọc TỪNG
        // middleware trong try/catch và biến ngoại lệ thành response ngay tại chỗ ném, nên
        // `abort(403)` sâu bên trong Livewire quay ra đây đã là một response 403 rồi. Khối
        // catch vẫn giữ cho các ngoại lệ ném ở ngoài pipeline route.
        if ($response instanceof Response && $response->getStatusCode() === 403) {
            // Vứt response đi là vứt cả header của nó. Middleware này nằm NGOÀI `StartSession`
            // và `EncryptCookies`, nên cookie ở đây đã mã hoá xong và chép nguyên văn được —
            // không chép thì request đầu tiên của một phiên mới mà bị từ chối sẽ không trả về
            // cookie phiên nào cả, và phiên sau lại là một phiên mới nữa.
            throw $this->notFound(cookies: $response->headers->getCookies());
        }

        return $response;
    }

    /**
     * `abort(403)` ném `HttpException` chung chung chứ không phải `AccessDeniedHttpException`,
     * nên phải hỏi mã chứ không bắt theo lớp. Mọi mã khác đi tiếp nguyên vẹn — 419 của CSRF và
     * 429 của giới hạn tần suất (SPEC §10.3) không được biến thành 404.
     */
    private function isDenial(AuthorizationException|HttpExceptionInterface $exception): bool
    {
        return $exception instanceof AuthorizationException || $exception->getStatusCode() === 403;
    }

    /**
     * `ResponseHeaderBag` hiểu `Set-Cookie` theo nghĩa riêng: đặt một mảng chuỗi cookie vào khoá
     * đó là nó dựng lại từng `Cookie` bằng `Cookie::fromString()`. Nhờ vậy cookie đi kèm ngoại
     * lệ mà không cần giữ tham chiếu tới response cũ.
     *
     * @param  array<int, Cookie>  $cookies
     */
    private function notFound(?Throwable $previous = null, array $cookies = []): NotFoundHttpException
    {
        return new NotFoundHttpException(
            previous: $previous,
            headers: $cookies === [] ? [] : ['Set-Cookie' => array_map(strval(...), $cookies)],
        );
    }
}
