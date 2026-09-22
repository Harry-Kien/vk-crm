<?php

namespace App\Support;

use App\Filament\Portal\Pages\SubmitDocument;
use App\Http\Controllers\DocumentDownloadController;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Bộ đếm của endpoint tải tệp Livewire (`livewire.upload-file`) — SPEC §10.3, 20 tệp / giờ /
 * **tài khoản**.
 *
 * # Vì sao lớp này tồn tại, viết ra vì nó là một lỗi đã đo được trên người thật
 *
 * Bản trước cắm thẳng `throttle:20,60` vào `config/livewire.php`. `ThrottleRequests` không nhận
 * tham số về guard: nó gọi `$request->user()`, tức guard **MẶC ĐỊNH** (`web`). Trên cổng khách
 * hàng guard là `client`, nên `$request->user()` là `null` và
 * `ThrottleRequests::resolveRequestSignature()` rơi về **ĐỊA CHỈ**.
 *
 * Đo được qua HTTP thật: tài khoản A gửi 20 tệp thì bị chặn ở tệp 21 — đúng. Tài khoản B, một
 * người KHÁC của cùng khách hàng, cùng một đường truyền, bị chặn ngay ở tệp **ĐẦU TIÊN** với 429
 * và `Retry-After: 3600`. SPEC §4.3 nêu đích danh hai tài khoản cho một khách hàng — hai vợ
 * chồng — làm trường hợp được thiết kế, và hai vợ chồng thì dùng chung một wifi. Cửa được dựng
 * để giữ mức của SPEC lại khoá đúng người không làm gì.
 *
 * Nó còn rộng hơn thế: dự án chưa khai `TRUSTED_PROXIES` (mặc định không tin ai — xem
 * `config/trustedproxy.php` và mục CHẶN RA MẮT ở `docs/PROGRESS.md`), nên sau một reverse proxy
 * `$request->ip()` là địa chỉ của proxy và **mọi khách hàng trên đời** dùng chung một rổ đếm.
 *
 * # Cách chữa: một bộ đếm CÓ TÊN, cùng thành ngữ với `throttle:document-download`
 *
 * Dự án đã có đúng thiết bị này ở route tải tệp: `AppServiceProvider` đăng ký
 * `RateLimiter::for('document-download', …)` và khoá lấy từ
 * {@see DocumentDownloadController::rateLimitKey()}, thứ hỏi CẢ HAI guard
 * rồi mới rơi về địa chỉ. Lớp này làm đúng như vậy cho endpoint tải lên — một thành ngữ, hai chỗ
 * dùng, không phát minh thêm cái thứ hai.
 *
 * Địa chỉ chỉ còn là khoá khi **không ai đăng nhập**. Bỏ hẳn giới hạn ở nhánh đó sẽ biến chính
 * nó thành đường vòng: chưa đăng nhập thì `web` middleware vẫn cho POST tới route này, và mỗi
 * lần POST vẫn là một lần ghi đĩa.
 */
final class UploadThrottle
{
    /** Tên bộ đếm, dùng ở `config/livewire.php` (`throttle:livewire-upload`). */
    public const NAME = 'livewire-upload';

    /**
     * SPEC §10.3. **Một con số, ba cửa**: hai cửa của màn hình nộp
     * ({@see SubmitDocument::FILES_PER_HOUR}, thứ đọc thẳng hằng số
     * này) và cửa của endpoint. Hai con số cho cùng một luật là cách chắc chắn nhất để một ngày
     * chúng lệch nhau.
     */
    public const FILES_PER_HOUR = 20;

    /** Cửa sổ đếm, tính bằng phút. */
    public const WINDOW_MINUTES = 60;

    /**
     * Khoá đếm của một request — **tài khoản trước, địa chỉ chỉ khi không có ai**.
     *
     * `recipientToken()` chứ không phải id trần, cùng lý do với route tải tệp: hai guard có thể
     * cùng có một tài khoản mang id 12, và gộp chúng vào một rổ sẽ cho một khách hàng khoá được
     * một luật sư.
     */
    public static function keyFor(Request $request): string
    {
        $actor = auth('web')->user() ?? auth('client')->user();

        return $actor instanceof User || $actor instanceof ClientUser
            ? Document::recipientToken($actor)
            : 'ip:'.$request->ip();
    }

    /**
     * Khoá mà `ThrottleRequests` **thật sự** ghi vào cache cho một bộ đếm có tên.
     *
     * Đây là một chi tiết của framework, nên nó được đọc ra chứ không nhớ:
     * `ThrottleRequests::handleRequestUsingNamedLimiter()` dựng khoá bằng
     * `md5($limiterName.$limit->key)` khi `static::$shouldHashKeys` còn bật (mặc định bật) — đã
     * đọc trong bản đang cài.
     *
     * Nó được nói ra ở đây vì màn hình nộp phải HỎI LẠI chính bộ đếm ấy để biết một lời từ chối
     * 429 có phải của nó không: JS của Livewire truyền `errors` là `null` cho mọi mã khác 422,
     * nên component không đọc được lý do từ response. Và vì đây là một bản chép của framework,
     * nó được GHIM bằng một test đi qua HTTP thật: 21 lần POST rồi hỏi khoá này phải ra `true`.
     */
    public static function cacheKeyFor(string $key): string
    {
        return md5(self::NAME.$key);
    }
}
