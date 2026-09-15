<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Đầu mối ghi nhật ký có cấu trúc cho các sự kiện không phải là thay đổi thuộc tính model
 * (đăng nhập, tải tài liệu, công bố, đổi phân quyền, ...) — xem SPEC §10.6.
 * Việc ghi nhật ký khi model bị sửa (created/updated/deleted) do trait LogsActivity của
 * spatie/laravel-activitylog tự lo, không đi qua đây.
 */
final class Audit
{
    public static function record(string $event, ?Model $subject = null, array $properties = []): void
    {
        $log = activity()->event($event)->withProperties($properties);

        if ($subject !== null) {
            $log->performedOn($subject);
        }

        if (($user = auth('web')->user()) !== null) {
            $log->causedBy($user);
        }

        $log->log($event);
    }
}
