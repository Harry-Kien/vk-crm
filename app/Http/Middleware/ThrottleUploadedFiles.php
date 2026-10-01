<?php

namespace App\Http\Middleware;

use App\Support\UploadThrottle;
use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cửa của endpoint tải tệp Livewire (`livewire.upload-file`): đếm TỆP, không đếm request — SPEC
 * §10.3 (M8 Task 3). Cắm ở `config/livewire.php` (`temporary_file_upload.middleware`).
 *
 * Toàn bộ lý lẽ — vì sao không dùng `throttle:` của framework, vì sao khoá theo tài khoản, vì sao
 * có hai trần (khách 20, nhân sự 200) — nằm ở {@see UploadThrottle}. Ở đây chỉ có cơ chế:
 *
 *  1. `$count` = số tệp trong request ({@see UploadThrottle::fileCount()});
 *  2. tăng bộ đếm đúng `$count` (`RateLimiter::increment()`, nguyên tử trên cache) rồi so với trần
 *     của người này ({@see UploadThrottle::limitFor()});
 *  3. vượt trần → HOÀN LẠI đúng `$count` và từ chối CẢ request bằng 429 kèm `Retry-After` (số giây
 *     tới khi cửa sổ mở lại), rồi đánh dấu lần từ chối ({@see UploadThrottle::markRefused()}) để
 *     màn hình nộp nhận ra. Không nhận một phần, không cắt bớt: một lô bị từ chối không tiêu suất.
 *
 * "Tăng rồi so, vượt thì hoàn" thay vì "đọc rồi so rồi tăng": hai request song song cùng đọc thấy
 * 19/20 rồi cùng qua là một khe hở đọc-rồi-ghi; một phép tăng nguyên tử không có khe đó.
 *
 * Tăng TRƯỚC khi controller chạy (như `ThrottleRequests`): một request bị validator từ chối (tệp
 * quá lớn, sai loại) vẫn đã tốn suất — mỗi POST là một lần ghi đĩa tiềm năng, và trần an ninh
 * không được đi vòng bằng cách cố ý gửi tệp sai. (Khác với cửa thứ hai của `SubmitDocument`, thứ
 * chỉ tính tệp thật sự vào hồ sơ — xem docblock `SubmitDocument::submit()`.)
 *
 * **Cửa sổ:** `RateLimiter::increment()` đặt hạn của khoá bằng `WINDOW_MINUTES` ở lần tăng ĐẦU và
 * không gia hạn ở các lần sau — cùng hành vi `hit()` mà bộ đếm cũ dùng.
 */
final class ThrottleUploadedFiles
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = UploadThrottle::keyFor($request);
        $cacheKey = UploadThrottle::cacheKeyFor($key);
        $limit = UploadThrottle::limitFor($request);
        $count = UploadThrottle::fileCount($request);
        $decay = UploadThrottle::WINDOW_MINUTES * 60;

        $used = RateLimiter::increment($cacheKey, $decay, $count);

        if ($used > $limit) {
            $used = RateLimiter::decrement($cacheKey, $decay, $count);

            UploadThrottle::markRefused($key);

            $retryAfter = max(1, RateLimiter::availableIn($cacheKey));

            throw new ThrottleRequestsException('Too Many Attempts.', null, [
                'Retry-After' => $retryAfter,
                'X-RateLimit-Limit' => $limit,
                'X-RateLimit-Remaining' => max(0, $limit - $used),
                'X-RateLimit-Reset' => now()->addSeconds($retryAfter)->getTimestamp(),
            ]);
        }

        return $next($request);
    }
}
