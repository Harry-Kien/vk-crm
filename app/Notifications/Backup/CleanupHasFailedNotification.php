<?php

namespace App\Notifications\Backup;

use App\Actions\Backup\ResolveBackupNotificationRecipients;
use App\Mail\Staff\BackupAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Spatie\Backup\Events\CleanupHasFailed;

/**
 * Thay `Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification` (SPEC §10 mục 8,
 * R3; M8a Task 1) — cùng cơ chế móc với {@see BackupHasFailedNotification}, đọc docblock ở đó.
 * TÊN LỚP PHẢI GIỮ NGUYÊN `CleanupHasFailedNotification`.
 *
 * CHỈ GIỮ CHUỖI, KHÔNG giữ `CleanupHasFailed $event` hay `Exception` gốc — cùng lý do (rủi ro
 * `serialize()` một ngăn xếp lỗi mang Closure khi `ShouldQueue` đẩy vào hàng đợi) đã ghi ở
 * docblock của {@see BackupHasFailedNotification}.
 */
class CleanupHasFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Làn fc (kiểm tra nghiệp vụ 2026-10-09): cùng ngân sách với mọi job thư khác (`SendStageUpdateNotification`,
     * `ResendOutboundMessageJob`…) — một lần SMTP chết thoáng qua không làm mất thư báo lỗi sao lưu. Trước
     * đó lớp không khai `$tries`, và `queue.drain` chạy `queue:work` không `--tries`: thư thử đúng một lần.
     * `SendQueuedNotifications` đọc hai thuộc tính này của thông báo.
     */
    public int $tries = 5;

    /** @var list<int> giây chờ trước lần thử 2, 3, 4, 5 */
    public array $backoff = [60, 300, 900, 3600];

    public readonly ?string $diskName;

    public readonly string $exceptionMessage;

    public function __construct(CleanupHasFailed $event)
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
            kind: BackupAlert::KIND_CLEANUP_FAILED,
            diskName: $this->diskName,
            detail: $this->exceptionMessage,
            recipients: app(ResolveBackupNotificationRecipients::class)->handle(),
        );
    }
}
