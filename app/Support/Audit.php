<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

/**
 * Đầu mối ghi nhật ký có cấu trúc cho các sự kiện không phải là thay đổi thuộc tính model
 * (đăng nhập, tải tài liệu, công bố, đổi phân quyền, ...) — xem SPEC §10.6.
 * Việc ghi nhật ký khi model bị sửa (created/updated/deleted) do trait LogsActivity của
 * spatie/laravel-activitylog tự lo, không đi qua đây.
 *
 * Ghi nhận người thực hiện ở bất kỳ guard nào đang đăng nhập — nhân sự nội bộ (guard `web`)
 * hoặc khách hàng ở portal (guard `client`), ưu tiên nhân sự nếu cả hai cùng có phiên (cùng thứ
 * tự ưu tiên với ClientPortalScope). SPEC §10.6 bắt buộc ghi cả đăng nhập và tải tài liệu ở
 * guard `client`, nên không được hardcode một guard duy nhất.
 *
 * # "Tài liệu này ra tới khách lúc nào" — HỢP của HAI tên sự kiện
 *
 * Phát biểu ở đây vì nó là một luật về TỪ VỰNG của nhật ký, và một luật về từ vựng chép ra ba
 * chỗ là một luật sẽ lệch. Ba chỗ cần nó: `PublishDocument`, `UploadStaffDocument` và
 * `SubmitClientDocument`.
 *
 * Mọi lần `documents.published_at` được ghi đều đi kèm một dòng nhật ký của `Audit`, nhưng
 * **không phải lúc nào cũng là `document_published`**:
 *
 * - `document_published` — **văn phòng** quyết định đưa một tài liệu ra trước mặt khách.
 *   `PublishDocument` ghi nó; `UploadStaffDocument` cũng ghi khi nhóm mặc định đã ra tới khách
 *   ngay lúc tạo (nhóm A), vì ở đó có một nhân sự chọn nội dung tệp và bấm nút.
 * - `document_submitted` — **khách** gửi một tệp lên qua portal. `SubmitClientDocument` ghi nó,
 *   và CỐ Ý không ghi thêm `document_published`: không ai trong văn phòng đưa ra thứ gì, thứ ra
 *   tới khách là chính cái họ vừa gửi. Dòng này vẫn kèm `published_at` trên bản ghi, vì tệp đó ở
 *   trong tầm tay khách kể từ giây nó được tạo.
 *
 * Hệ quả cho người đi tìm, và đây là lý do luật này phải được viết ra: một truy vấn
 * `where('event', 'document_published')` trả lời đúng câu **"văn phòng đã công bố những gì"** và
 * KHÔNG trả lời câu "những tài liệu nào khách đọc được". Câu thứ hai là hợp của hai tên sự kiện
 * trên. Cả hai dòng đều mang `version`, `group`, `matter_id` và `client_id` với cùng hình dạng,
 * nên hợp chúng lại đọc được bằng một truy vấn.
 *
 * `$causer` là tham số tuỳ chọn: khi một Action đã nhận một actor tường minh (không tin vào
 * `auth()` ambient — ví dụ actor được truyền từ một lệnh console, một job chạy lại, hay một
 * caller quên `actingAs` trong test), truyền actor đó vào đây để dòng nhật ký được gán đúng
 * người, thay vì suy luận (có thể sai, hoặc rỗng) từ phiên đăng nhập hiện tại.
 *
 * Return value (M6.5 Task 8, R13g / conflict-06): record() now returns the logged Activity (or
 * null) instead of void. RunConflictCheck needs it — the conflict_check_run row it writes during
 * OpenMatter's check phase is logged before the Matter exists, so its subject starts out empty;
 * OpenMatter later re-points that exact row at the freshly-saved Matter, which requires holding
 * on to the row's id. Every existing caller already discards the return value, so this is a
 * behaviour-preserving widening, not a breaking change.
 */
final class Audit
{
    public static function record(string $event, ?Model $subject = null, array $properties = [], ?Model $causer = null): ?Activity
    {
        $log = activity()->event($event)->withProperties($properties);

        if ($subject !== null) {
            $log->performedOn($subject);
        }

        $causer ??= auth('web')->user() ?? auth('client')->user();

        if ($causer !== null) {
            $log->causedBy($causer);
        }

        return $log->log($event);
    }

    /**
     * Băm MỘT định danh (số CCCD, số điện thoại — đã bỏ ký tự không phải chữ số). HMAC-SHA256 với
     * `APP_KEY`, không phải `sha256` trần: định danh chỉ có 10–12 chữ số, nên một sha256 trần dò
     * ngược được bằng vét cạn bởi bất kỳ ai đọc được bảng chứa nó. Cùng một số vẫn cho cùng một
     * hash (đối chiếu được giữa các dòng) trong khi còn cùng `APP_KEY`.
     *
     * ĐỊNH NGHĨA DUY NHẤT cho hai chỗ lưu (M8 Task 4, SPEC §10.5):
     *  - `properties` của một dòng nhật ký (final review X8, C-I4) — `client_lookup`,
     *    `client_lookup_throttled`, …;
     *  - `matter_parties.id_number_hash`, cột so trùng CCCD của kiểm tra xung đột lợi ích —
     *    {@see Normalizer::idNumberHash()} gọi thẳng hàm này (trước Task 4 cột đó là `sha256` trần,
     *    đúng lỗ hổng X8 đã đóng cho nhật ký nhưng để lại ở cột so trùng).
     *
     * Vì hai chỗ dùng chung một cách băm, cùng một CCCD cho cùng một giá trị ở cả hai — một lần tra
     * trong nhật ký đối chiếu được với bên mà nó khớp. Và vì khoá là `APP_KEY`, đổi khoá làm cả hai
     * thôi khớp với mọi giá trị đã lưu (xem docblock `Normalizer::idNumberHash()`).
     */
    public static function identifierHash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
