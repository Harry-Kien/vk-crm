<?php

namespace App\Support;

use App\Filament\Admin\Concerns\ExplainsStaffUploadRefusal;
use App\Filament\Portal\Pages\SubmitDocument;
use App\Http\Controllers\DocumentDownloadController;
use App\Http\Middleware\ThrottleUploadedFiles;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Bộ đếm của endpoint tải tệp Livewire (`livewire.upload-file`) — SPEC §10.3, 20 tệp / giờ /
 * **tài khoản** (khách) và một trần riêng cho nhân sự.
 *
 * # Vì sao lớp này tồn tại, viết ra vì nó là một lỗi đã đo được trên người thật
 *
 * Bản đầu cắm thẳng `throttle:20,60` vào `config/livewire.php`. `ThrottleRequests` không nhận
 * tham số về guard: nó gọi `$request->user()`, tức guard **MẶC ĐỊNH** (`web`). Trên cổng khách
 * hàng guard là `client`, nên `$request->user()` là `null` và
 * `ThrottleRequests::resolveRequestSignature()` rơi về **ĐỊA CHỈ**.
 *
 * Đo được qua HTTP thật: tài khoản A gửi 20 tệp thì bị chặn ở tệp 21 — đúng. Tài khoản B, một
 * người KHÁC của cùng khách hàng, cùng một đường truyền, bị chặn ngay ở tệp **ĐẦU TIÊN** với 429
 * và `Retry-After: 3600`. SPEC §4.3 nêu đích danh hai tài khoản cho một khách hàng — hai vợ
 * chồng — làm trường hợp được thiết kế, và hai vợ chồng thì dùng chung một wifi.
 *
 * Nó còn rộng hơn thế: dự án chưa khai `TRUSTED_PROXIES` (mặc định không tin ai — xem
 * `config/trustedproxy.php`), nên sau một reverse proxy `$request->ip()` là địa chỉ của proxy và
 * **mọi khách hàng trên đời** dùng chung một rổ đếm.
 *
 * # Cách chữa: khoá theo TÀI KHOẢN, do middleware của dự án tự dựng
 *
 * Khoá lấy từ cùng thành ngữ với route tải tệp (`DocumentDownloadController::rateLimitKey()`):
 * hỏi CẢ HAI guard rồi mới rơi về địa chỉ. Địa chỉ chỉ còn là khoá khi **không ai đăng nhập** —
 * bỏ hẳn giới hạn ở nhánh đó sẽ biến chính nó thành đường vòng: chưa đăng nhập thì `web` middleware
 * vẫn cho POST tới route này, và mỗi lần POST vẫn là một lần ghi đĩa.
 *
 * # M8 Task 3: đếm TỆP, không đếm REQUEST — và hai trần
 *
 * Bản trước là một bộ đếm CÓ TÊN của `ThrottleRequests` (`RateLimiter::for('livewire-upload')`),
 * thứ luôn tăng đúng MỘT đơn vị cho mỗi request. SPEC §10.3 viết "20 TỆP / giờ". Sau M6.5 R10 một
 * lần nộp có thể mang nhiều tệp (`multiple()`; Livewire gửi cả lô trong MỘT POST `files[]`), nên
 * một request 20 tệp chỉ tốn một suất: 20 request × 20 tệp = 400 tệp/giờ lọt qua trần 20. Nay
 * {@see ThrottleUploadedFiles} thay chỗ của `throttle:`: nó đếm số tệp thật trong request, và
 * **từ chối cả request** nếu số tệp đó làm vượt trần (không nhận một phần, không cắt bớt) — cùng
 * luật "cả lô cùng vào hoặc cả lô cùng bị chặn" mà `SubmitDocument::guardRate()` đã dùng.
 *
 * **Hai trần, vì SPEC §10.3 viết về "nộp tài liệu" của KHÁCH** (SPEC §6.6, §8.4):
 *
 *  - khách (guard `client`, hoặc chưa đăng nhập theo địa chỉ): {@see self::FILES_PER_HOUR} = 20;
 *  - nhân sự (guard `web`): {@see self::STAFF_FILES_PER_HOUR} = 200.
 *
 * Endpoint này dùng CHUNG cho cả hai panel, và trước task này mức 20 áp cả lên nhân sự — một luật
 * sư tải bộ hồ sơ toà 30 trang là chạm giới hạn của khách. Không bỏ trần cho nhân sự, vì mỗi POST
 * vẫn ghi đĩa và một phiên nhân sự bị chiếm sẽ ghi không giới hạn. **Giá nếu sai:** một phiên nhân
 * sự bị chiếm ghi được 200 thay vì 20 tệp/giờ; một luật sư tải hơn 200 trang trong một giờ gặp lời
 * từ chối. Lời từ chối ấy đi ra bằng câu riêng cho nhân sự, không nhắc số điện thoại văn phòng
 * (`documents.errors.staff_upload_rate_limited`), qua
 * {@see ExplainsStaffUploadRefusal} — JS của Livewire chỉ báo "tải lên
 * không thành công" cho mọi mã khác 422, nên không có câu đó thì luật sư không biết vì sao và thử lại mãi.
 *
 * # Khoá cache mà màn hình nộp hỏi lại
 *
 * Khoá cache là `md5(NAME.$key)` — cùng công thức mà `ThrottleRequests` từng dùng, giữ nguyên để
 * {@see SubmitDocument::_uploadErrored()} vẫn hỏi lại được cùng một khoá (test HTTP ghim nó). Vì
 * một lời từ chối 429 do một lô làm vượt trần KHÔNG tăng bộ đếm, "bộ đếm đã đầy" không còn là
 * cách duy nhất để một request bị từ chối — nên middleware còn đánh dấu lần từ chối gần nhất
 * ({@see self::markRefused()}), để màn hình nộp nhận ra nó.
 */
final class UploadThrottle
{
    /** Tên bộ đếm — nay chỉ còn là tiền tố của khoá cache ({@see self::cacheKeyFor()}). */
    public const NAME = 'livewire-upload';

    /**
     * SPEC §10.3, trần của KHÁCH. **Một con số, ba cửa**: hai cửa của màn hình nộp
     * ({@see SubmitDocument::FILES_PER_HOUR}, thứ đọc thẳng hằng số này) và cửa của endpoint. Hai
     * con số cho cùng một luật là cách chắc chắn nhất để một ngày chúng lệch nhau.
     */
    public const FILES_PER_HOUR = 20;

    /**
     * Trần của NHÂN SỰ trên cùng endpoint — xem "hai trần" ở docblock lớp. Đặt cạnh
     * {@see self::FILES_PER_HOUR} để hai con số của một luật nằm cạnh nhau.
     */
    public const STAFF_FILES_PER_HOUR = 200;

    /** Cửa sổ đếm, tính bằng phút. */
    public const WINDOW_MINUTES = 60;

    /** Dấu "vừa từ chối" sống bao lâu (giây): đủ cho `_uploadErrored()` của chính request ấy hỏi lại. */
    private const REFUSED_MARK_SECONDS = 60;

    /**
     * Người đang tải lên — nhân sự trước, khách sau (cùng thứ tự ưu tiên `Audit::record()`).
     */
    private static function actor(): User|ClientUser|null
    {
        $actor = auth('web')->user() ?? auth('client')->user();

        return $actor instanceof User || $actor instanceof ClientUser ? $actor : null;
    }

    /**
     * Khoá đếm của một request — **tài khoản trước, địa chỉ chỉ khi không có ai**.
     *
     * `recipientToken()` chứ không phải id trần, cùng lý do với route tải tệp: hai guard có thể
     * cùng có một tài khoản mang id 12, và gộp chúng vào một rổ sẽ cho một khách hàng khoá được
     * một luật sư.
     */
    public static function keyFor(Request $request): string
    {
        $actor = self::actor();

        return $actor !== null ? Document::recipientToken($actor) : 'ip:'.$request->ip();
    }

    /**
     * Trần tệp / giờ áp cho người đang tải: nhân sự {@see self::STAFF_FILES_PER_HOUR}, còn lại
     * (khách, hoặc chưa đăng nhập) {@see self::FILES_PER_HOUR}. Cùng nguồn "ai đang tải" với
     * {@see self::keyFor()}, nên khoá và trần không thể nói về hai người khác nhau.
     */
    public static function limitFor(Request $request): int
    {
        return self::actor() instanceof User ? self::STAFF_FILES_PER_HOUR : self::FILES_PER_HOUR;
    }

    /**
     * Số tệp trong request tải lên: Livewire gửi `files[]` (một hoặc nhiều tệp). Tối thiểu **1** —
     * một POST không mang tệp nào (hoặc mang thứ không phải tệp) không được miễn phí: nó vẫn chạm
     * tới validator và là một lần thử; đếm 0 sẽ cho một vòng lặp POST rỗng vô hạn không tốn suất.
     */
    public static function fileCount(Request $request): int
    {
        $files = $request->file('files');

        return max(1, is_array($files) ? count($files) : ($files === null ? 0 : 1));
    }

    /**
     * Khoá mà bộ đếm **thật sự** ghi vào cache cho một khoá tài khoản/địa chỉ.
     *
     * `md5(NAME.$key)` — công thức mà `ThrottleRequests::handleRequestUsingNamedLimiter()` từng
     * dùng khi bộ đếm còn là một bộ đếm có tên; giữ nguyên để màn hình nộp
     * ({@see SubmitDocument::_uploadErrored()}) hỏi lại đúng khoá cũ. Nay nó là công thức CỦA DỰ
     * ÁN chứ không còn là một bản chép của framework, nhưng test HTTP thật vẫn ghim nó (21 lần
     * POST rồi hỏi đúng khoá này).
     */
    public static function cacheKeyFor(string $key): string
    {
        return md5(self::NAME.$key);
    }

    /**
     * Ghi nhận rằng request tải lên của `$key` vừa bị TỪ CHỐI vì vượt trần. Cần vì một lô làm vượt
     * trần bị từ chối mà KHÔNG tăng bộ đếm (không nhận một phần): bộ đếm có thể mới ở 19/20 trong
     * khi lô 2 tệp bị từ chối, và `_uploadErrored()` — không đọc được lý do từ response (JS của
     * Livewire đặt `errors` là `null` cho mọi mã khác 422) — phải phân biệt được "hết suất giờ"
     * với "tệp lỗi khác".
     */
    public static function markRefused(string $key): void
    {
        Cache::put(self::cacheKeyFor($key).':refused', true, self::REFUSED_MARK_SECONDS);
    }

    public static function wasRecentlyRefused(string $key): bool
    {
        return Cache::has(self::cacheKeyFor($key).':refused');
    }

    /**
     * Fix round 1 (F2): người đang tải vừa bị chặn bởi TRẦN GIỜ của chính họ không, và nếu có thì
     * phải chờ bao nhiêu phút (tối thiểu 1). `null` = không phải lời từ chối của trần giờ.
     *
     * Hai dấu hiệu như {@see SubmitDocument::_uploadErrored()}: bộ đếm đã đầy ở trần của người này
     * ({@see self::limitFor()}), hoặc middleware vừa đánh dấu một lô bị từ chối
     * ({@see self::wasRecentlyRefused()}) — bộ đếm có thể chưa đầy khi một lô làm vượt trần. Dùng
     * bởi {@see ExplainsStaffUploadRefusal}.
     */
    public static function refusalWaitMinutes(Request $request): ?int
    {
        $key = self::keyFor($request);
        $cacheKey = self::cacheKeyFor($key);

        if (! RateLimiter::tooManyAttempts($cacheKey, self::limitFor($request)) && ! self::wasRecentlyRefused($key)) {
            return null;
        }

        return max(1, (int) ceil(RateLimiter::availableIn($cacheKey) / 60));
    }
}
