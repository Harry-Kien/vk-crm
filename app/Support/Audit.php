<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

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
 * `$causer` là tham số tuỳ chọn: khi một Action đã nhận một actor tường minh (không tin vào
 * `auth()` ambient — ví dụ actor được truyền từ một lệnh console, một job chạy lại, hay một
 * caller quên `actingAs` trong test), truyền actor đó vào đây để dòng nhật ký được gán đúng
 * người, thay vì suy luận (có thể sai, hoặc rỗng) từ phiên đăng nhập hiện tại.
 */
final class Audit
{
    public static function record(string $event, ?Model $subject = null, array $properties = [], ?Model $causer = null): void
    {
        $log = activity()->event($event)->withProperties($properties);

        if ($subject !== null) {
            $log->performedOn($subject);
        }

        $causer ??= auth('web')->user() ?? auth('client')->user();

        if ($causer !== null) {
            $log->causedBy($causer);
        }

        $log->log($event);
    }
}
