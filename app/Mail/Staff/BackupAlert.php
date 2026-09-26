<?php

namespace App\Mail\Staff;

use App\Mail\BrandedMailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Thư báo lỗi sao lưu (SPEC §10 mục 8, R3; M8a Task 1) — ba trường hợp: sao lưu thất bại, dọn
 * dẹp thất bại, bản sao không lành mạnh (không có thư "thành công", theo brief).
 *
 * Không tự tính người nhận: `App\Notifications\Backup\*` (một cho mỗi sự kiện của
 * `spatie/laravel-backup`) tính người nhận qua `App\Actions\Backup\
 * ResolveBackupNotificationRecipients` rồi truyền vào constructor, để Mailable này chỉ lo hiển
 * thị — cùng phân công với `App\Mail\Staff\DeadlineReminder`.
 *
 * `$detail` LUÔN LÀ THÔNG ĐIỆP LỖI CỦA NGOẠI LỆ (`Exception::getMessage()`) hoặc mô tả kiểm tra
 * sức khoẻ — KHÔNG BAO GIỜ là giá trị `BACKUP_ARCHIVE_PASSWORD`. Ba lớp notification gọi lớp
 * này không đọc cấu hình mật khẩu, nên không có đường nào để mật khẩu lọt vào thư qua `$detail`.
 */
class BackupAlert extends BrandedMailable
{
    public const KIND_BACKUP_FAILED = 'backup_failed';

    public const KIND_CLEANUP_FAILED = 'cleanup_failed';

    public const KIND_UNHEALTHY = 'unhealthy';

    /**
     * @param  self::KIND_*  $kind
     * @param  list<string>  $recipients
     */
    public function __construct(
        public string $kind,
        public ?string $diskName,
        public string $detail,
        public array $recipients,
    ) {}

    protected function template(): string
    {
        return 'staff.backup_alert.'.$this->kind;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            to: $this->recipients,
            subject: __('backup.email.subject.'.$this->kind, ['disk' => $this->diskName ?? '—']),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.staff.backup-alert',
            text: 'emails.staff.backup-alert-text',
            with: [
                'kind' => $this->kind,
                'diskName' => $this->diskName,
                'detail' => $this->detail,
                'office' => config('vkcrm.brand.legal_name'),
            ],
        );
    }
}
