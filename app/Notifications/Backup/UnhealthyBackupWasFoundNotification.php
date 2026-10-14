<?php

namespace App\Notifications\Backup;

use App\Actions\Backup\ResolveBackupNotificationRecipients;
use App\Mail\Staff\BackupAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Spatie\Backup\Events\UnhealthyBackupWasFound;

/**
 * Thay `Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification` (SPEC
 * §10 mục 8, R3; M8a Task 1) — cùng cơ chế móc với {@see BackupHasFailedNotification}, đọc
 * docblock ở đó. TÊN LỚP PHẢI GIỮ NGUYÊN `UnhealthyBackupWasFoundNotification`.
 *
 * CHỈ GIỮ MỘT CHUỖI ĐÃ GỘP SẴN (`$detail`), KHÔNG giữ `UnhealthyBackupWasFound $event` — cùng lý
 * do rủi ro serialize đã ghi ở {@see BackupHasFailedNotification} (event này không mang
 * `Exception`, nhưng giữ nguyên tắc "chỉ mang theo dữ liệu nguyên thuỷ vào hàng đợi" cho cả ba
 * lớp, để không ai vô tình thêm lại một property mang đối tượng phức tạp sau này).
 */
class UnhealthyBackupWasFoundNotification extends Notification implements ShouldQueue
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

    public readonly string $diskName;

    public readonly string $detail;

    public function __construct(UnhealthyBackupWasFound $event)
    {
        $this->diskName = $event->diskName;

        $this->detail = $event->failureMessages
            ->map(fn (array $failure): string => "[{$failure['check']}] {$failure['message']}")
            ->implode(' ');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): BackupAlert
    {
        return new BackupAlert(
            kind: BackupAlert::KIND_UNHEALTHY,
            diskName: $this->diskName,
            detail: $this->detail,
            recipients: app(ResolveBackupNotificationRecipients::class)->handle(),
        );
    }
}
