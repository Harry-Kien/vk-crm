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
