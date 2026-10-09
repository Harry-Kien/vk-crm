<?php

namespace App\Http\Middleware\Mcp;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * M11 R7 — header `Origin` của request tới `/mcp`.
 *
 * Đặc tả Streamable HTTP 2026-07-28: máy chủ MUST kiểm `Origin` và trả 403 khi nó có mặt mà không
 * hợp lệ (chống DNS rebinding) [PL:158], [DC:627]. laravel/mcp 1.0.1 không kiểm `Origin` ở đâu cả
 * (rà soát Task 0), nên lớp này là chỗ duy nhất làm việc đó.
 *
 * - **Không có `Origin`: cho qua.** Claude, ChatGPT và các client chạy ở máy chủ không gửi header
 *   này; chặn chúng là đúng cái lỗi "kiểm quá chặt thì timeout" mà tài liệu Claude cảnh báo
 *   [DC:672]. Bước kiểm token phía sau vẫn chạy.
 * - **Có `Origin`: phải khớp CHÍNH XÁC (không phân biệt hoa thường) một mục của
 *   {@see self::allowedOrigins()}**, không thì 403. Không so tiền tố hay tên miền con:
 *   `https://claude.ai.evil.example` và `http://claude.ai` đều bị chặn. Chuỗi rỗng và `null` (giá
 *   trị trình duyệt gửi từ một ngữ cảnh mờ) là "có `Origin`" và cũng bị chặn.
 *
 * Đứng đầu các middleware mà `routes/ai.php` thêm cho `/mcp`, tức TRƯỚC bước xác thực (ba middleware
 * của gói đứng trước nó chỉ sắp lại `Accept` và so header MCP với thân request): một trang lạ nhận
 * 403 dù có token hay không, và không bao giờ đi tới chỗ token được đọc.
 */
class CheckOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->headers->has('Origin')) {
            return $next($request);
        }

        $origin = strtolower(trim((string) $request->headers->get('Origin')));

        if (! in_array($origin, self::allowedOrigins(), true)) {
            return response()->json(['message' => __('mcp.http.origin_forbidden')], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }

    /**
     * `config('vkcrm.mcp.allowed_origins')` (mặc định Claude và ChatGPT, thêm bằng
     * `MCP_EXTRA_ALLOWED_ORIGINS`) cộng origin của chính ứng dụng, lấy từ `APP_URL`.
     *
     * @return list<string> dạng `scheme://host[:port]`, chữ thường
     */
    public static function allowedOrigins(): array
    {
        $origins = (array) config('vkcrm.mcp.allowed_origins', []);

        $app = parse_url((string) config('app.url'));

        if (isset($app['scheme'], $app['host'])) {
            $origins[] = $app['scheme'].'://'.$app['host'].(isset($app['port']) ? ':'.$app['port'] : '');
        }

        return array_values(array_unique(array_map(
            fn (string $origin) => strtolower(rtrim(trim($origin), '/')),
            $origins,
        )));
    }
}
