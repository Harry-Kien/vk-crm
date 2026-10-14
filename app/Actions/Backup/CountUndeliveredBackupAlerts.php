<?php

namespace App\Actions\Backup;

use App\Notifications\Backup\BackupHasFailedNotification;
use App\Notifications\Backup\CleanupHasFailedNotification;
use App\Notifications\Backup\UnhealthyBackupWasFoundNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Làn fc, vòng sửa 1 (I1) — số thư báo lỗi sao lưu HỎNG HẲN từ `$since`: mỗi lần một trong ba thông báo
 * sao lưu hết sạch `$tries` (5) lượt thử và hàng đợi chuyển job của nó sang bảng `failed_jobs`.
 *
 * **Vì sao không đếm dòng `outbound_messages` `failed`.** `RecordOutboundMessage::sending()` mở một dòng
 * MỚI cho MỖI LẦN THỬ của job, và `OutboundLedgerTransport` đánh dấu `failed` đúng dòng của lần thử ấy.
 * Lần 1 hỏng vì SMTP chập chờn rồi lần 2 gửi được vẫn để lại một dòng `failed` (thư đã tới), và một thư
 * hỏng cả năm lần để lại năm dòng (một thư). Một dòng `failed_jobs` là đúng một thư không tới ai.
 *
 * **Lọc theo tên lớp thông báo**, đọc ở khoá `displayName` của payload (`SendQueuedNotifications::displayName()`
 * trả tên lớp THÔNG BÁO): ba lớp của `App\Notifications\Backup`, so bằng nguyên tên đầy đủ, không theo
 * tên ngắn — lớp gốc của gói (`Spatie\Backup\Notifications\Notifications\…`) cùng tên ngắn không được tính.
 * Câu `like` trên tên ngắn chỉ để khỏi giải mã mọi payload hỏng trong cửa sổ; tên ngắn không có `_` hay
 * `%` (ký tự đại diện của LIKE) và không có `\` (ký tự thoát của LIKE trên MariaDB).
 *
 * Đọc bảng theo `config('queue.failed')` (kết nối, tên bảng) — cùng cấu hình mà hàng đợi dùng để GHI
 * (driver `database-uuids`, mặc định của dự án).
 */
class CountUndeliveredBackupAlerts
{
    /** @var list<class-string> */
    public const NOTIFICATIONS = [
        BackupHasFailedNotification::class,
        CleanupHasFailedNotification::class,
        UnhealthyBackupWasFoundNotification::class,
    ];

    public function handle(Carbon $since): int
    {
        return DB::connection(config('queue.failed.database'))
            ->table(config('queue.failed.table'))
            ->where('failed_at', '>=', $since)
            ->where(function ($query): void {
                foreach (self::NOTIFICATIONS as $class) {
                    $query->orWhere('payload', 'like', '%'.class_basename($class).'%');
                }
            })
            ->pluck('payload')
            ->filter(fn (string $payload): bool => in_array(json_decode($payload, true)['displayName'] ?? null, self::NOTIFICATIONS, true))
            ->count();
    }
}
