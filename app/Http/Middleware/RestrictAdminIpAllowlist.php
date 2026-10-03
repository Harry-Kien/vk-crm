<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * R7 (SPEC §3: "có thể bật qua middleware, cấu hình `.env`"; SPEC §10 mục 10) — giới hạn IP được
 * vào panel `/admin`.
 *
 * `ADMIN_IP_ALLOWLIST` (`config('vkcrm.security.admin_ip_allowlist')`): danh sách địa chỉ IPv4/
 * IPv6 và dải CIDR, phân tách dấu phẩy, khoảng trắng quanh mỗi phần tử bị bỏ. RỖNG = TẮT HẲN, đây
 * là mặc định — một văn phòng chưa quyết định dải IP nào không bị khoá ngoài admin panel của
 * chính mình.
 *
 * Có giá trị: request từ IP KHÔNG khớp bất kỳ phần tử nào nhận **404** — cùng ngôn ngữ từ chối
 * với {@see AnswerDeniedPanelRequestsWithNotFound} (SPEC §10 mục 10: "không có quyền và không tồn
 * tại đều trả 404"), không phải 403: một IP ngoài danh sách không được biết là panel `/admin` có
 * tồn tại.
 *
 * **Phạm vi — đăng ký trên panel `admin` với `isPersistent: true`** (`AdminPanelProvider`), ĐỨNG
 * TRƯỚC `Filament\Http\Middleware\Authenticate` — cùng vị trí và cùng lý do với
 * {@see AnswerDeniedPanelRequestsWithNotFound}: `isPersistent: true` đưa middleware này vào danh
 * sách middleware BỀN của Livewire, nên nó cũng chặn request cập nhật (`/livewire/update`) của
 * một component admin, không chỉ trang HTML đầu tiên.
 *
 * **Cũng phủ route PWA của `/admin`** (`routes/pwa.php`, M12: manifest, rồi `sw.js` và trang ngoại
 * tuyến). Các route đó đứng ngoài chồng middleware có phiên của panel, nên nhóm của chúng gắn
 * middleware này trực tiếp — khi và chỉ khi panel mang nó trong `getMiddleware()`. Middleware này
 * không đụng phiên, nên gắn ở đó không đẻ cookie hay dòng `sessions` nào.
 *
 * **KHÔNG phủ `documents.download` và `/livewire/upload-file`.** URL tải tệp có chữ ký sống 5
 * phút và chỉ được SINH RA bên trong panel (một nhân sự đã qua allowlist mới bấm được nút tải) —
 * phủ luôn route đó nghĩa là một link vừa mở trong văn phòng không mở được ở nơi khác trong 5
 * phút còn lại của nó, một hành vi khó hiểu hơn cái giá phải trả: một nhân sự tải link đó ở ngoài
 * dải IP vẫn tải xong trong 5 phút. Chấp nhận cái giá đó có chủ đích (ghi trong brief Task 1).
 * Quyết định đó giữ nguyên cho bí danh trong scope của app nội bộ, `/admin/documents/{id}/download`
 * (M12 Task 3, `routes/web.php`): cùng controller, cùng middleware với `documents.download`, không
 * thêm middleware này. Cái giá đi kèm: một IP ngoài danh sách gọi đường dẫn đó mà không có chữ ký
 * hợp lệ nhận 403 của `signed` chứ không phải 404 — biết được có một route dưới `/admin`, dù không
 * thấy gì của panel và không lấy được gì.
 *
 * IP đọc qua `$request->ip()`, tức PHỤ THUỘC `TRUSTED_PROXIES`
 * (`config/trustedproxy.php`) giống mọi chỗ khác hỏi "IP thật của ai đang gọi" — không tin proxy
 * thì `ip()` trả về địa chỉ của chính proxy cho MỌI người, và danh sách này hoặc chặn hết hoặc mở
 * hết tuỳ proxy có nằm trong dải hay không.
 */
class RestrictAdminIpAllowlist
{
    public function handle(Request $request, Closure $next): mixed
    {
        $allowlist = self::entries();

        if ($allowlist === []) {
            return $next($request);
        }

        if (! IpUtils::checkIp($request->ip(), $allowlist)) {
            throw new NotFoundHttpException;
        }

        return $next($request);
    }

    /**
     * @return list<string> danh sách đã bỏ khoảng trắng và phần tử rỗng, GIỮ THỨ TỰ khai báo
     */
    public static function entries(): array
    {
        $raw = (string) config('vkcrm.security.admin_ip_allowlist', '');

        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            fn (string $entry): bool => $entry !== '',
        ));
    }
}
