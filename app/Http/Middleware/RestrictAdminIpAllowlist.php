<?php

namespace App\Http\Middleware;

use App\Support\Pwa\PwaPanels;
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
 *
 * **Nhưng PHỦ bí danh trong scope của app nội bộ**, `/admin/documents/{id}/download` (M12 Task 3,
 * `routes/web.php`) — việc sau gộp M12 (làn fu4, mục 4). Bản M12 giữ quyết định trên cho bí danh, và
 * nó thành path duy nhất dưới `/admin` trả lời một IP ngoài danh sách: 403 của `signed` thay vì 404,
 * tức biết được có một app nội bộ ở đây. Nay nhóm bí danh gắn middleware này (cùng luật `$ipGate` của
 * `routes/pwa.php`), đứng trước `signed`. URL ký cho nhân sự chỉ được dựng trên trang của panel, vốn
 * đã sau giới hạn này, nên không đường tải nào của nhân sự bị mất; cái giá là đường dẫn mở trong văn
 * phòng, bấm lại từ ngoài dải trong 5 phút còn lại, nhận 404. Route gốc `documents.download` giữ
 * nguyên quyết định cũ.
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
        if (! self::admits($request)) {
            throw new NotFoundHttpException;
        }

        return $next($request);
    }

    /**
     * "IP của request này có được vào `/admin` không" — danh sách rỗng là tắt (mọi IP được vào).
     * Đây là câu DUY NHẤT {@see self::handle()} hỏi; tách ra để trang lỗi 403/404 hỏi lại đúng câu
     * đó ({@see PwaPanels::startUrlFor()}): một IP ngoài danh sách không được thấy nút "Về trang
     * chính" trỏ `/admin`, kẻo trang 404 của nó dưới `/admin` khác trang 404 của một path lạ bất kỳ.
     */
    public static function admits(Request $request): bool
    {
        $allowlist = self::entries();

        return $allowlist === [] || IpUtils::checkIp($request->ip(), $allowlist);
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
