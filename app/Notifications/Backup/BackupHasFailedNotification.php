<?php

namespace App\Notifications\Backup;

use App\Actions\Backup\ResolveBackupNotificationRecipients;
use App\Mail\Staff\BackupAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Spatie\Backup\Events\BackupHasFailed;

/**
 * Thay `Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification` (SPEC §10 mục 8,
 * R3; M8a Task 1) — móc qua CLASS BASENAME trong `config('backup.notifications.notifications')`,
 * xem docblock ở đó. TÊN LỚP PHẢI GIỮ NGUYÊN `BackupHasFailedNotification`, dù đổi namespace.
 *
 * `ShouldQueue` — thư không gửi đồng bộ trong chính tiến trình `backup:run` (Task 1 brief).
 *
 * CHỈ GIỮ CHUỖI (`$diskName`, `$exceptionMessage`), KHÔNG giữ `BackupHasFailed $event` hay
 * `Exception` gốc: `Spatie\Backup\Notifications\EventHandler::determineNotification()` luôn gọi
 * `new $notificationClass($event)` — constructor buộc phải nhận `$event` — nhưng lưu THẲNG đối
 * tượng `Exception` làm thuộc tính rồi để `ShouldQueue` đẩy notification này vào hàng đợi sẽ
 * `serialize()` luôn cả `Exception::getTrace()`; nếu bất kỳ khung nào trong ngăn xếp lỗi (kể cả
 * của chính `spatie/laravel-backup` hay Flysystem) nhận một Closure làm tham số, `serialize()`
 * ném `Serialization of 'Closure' is not allowed` — ĐÚNG LÚC hệ thống đang cố báo một lỗi sao
 * lưu, tự nó lại tạo ra một lỗi khác. Đo được: `tests/Feature/Backup/BackupNotificationsTest.php`
 * ném lỗi này khi lớp còn giữ `$event` trực tiếp.
 */
class BackupHasFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public readonly ?string $diskName;

    public readonly string $exceptionMessage;

    public function __construct(BackupHasFailed $event)
    {
        $this->diskName = $event->diskName;
        $this->exceptionMessage = $event->exception->getMessage();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): BackupAlert
    {
        return new BackupAlert(
            kind: BackupAlert::KIND_BACKUP_FAILED,
            diskName: $this->diskName,
            detail: $this->exceptionMessage,
            recipients: app(ResolveBackupNotificationRecipients::class)->handle(),
        );
    }
}
