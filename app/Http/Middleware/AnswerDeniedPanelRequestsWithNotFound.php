<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
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
 * Đổi bằng cách đóng gói ngoài cùng thay vì vá từng trang: mọi cách Filament có để từ chối,
 * kể cả những cách các phiên bản sau thêm vào, đều đi qua đây. Giới hạn trong middleware của
 * panel là cố ý — 403 vẫn còn nghĩa ở ngoài panel, nơi nó nói về ĐƯỜNG DẪN chứ không về bản
 * ghi: route tải tệp có chữ ký (SPEC §10.4, §11 "Tải tệp") phải trả 403 cho chữ ký hết hạn.
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
            throw $this->notFound();
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

    private function notFound(?Throwable $previous = null): NotFoundHttpException
    {
        return new NotFoundHttpException(previous: $previous);
    }
}
