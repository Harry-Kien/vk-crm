<?php

namespace App\Mail\Staff;

use App\Actions\Schedule\CheckDocumentStoreHealth;
use App\Mail\BrandedMailable;
use App\Support\OfficeProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Thư cảnh báo kho tài liệu (kế hoạch M14, Task 5; R5, R9, R10, R13), một mẫu cho mỗi loại sự cố:
 * `staff.document_store_alert.<loại>`. Do {@see CheckDocumentStoreHealth} xếp hàng (sau commit), chống
 * trùng theo loại mỗi ngày qua `outbound_messages` (chỉ dòng `sent`).
 *
 * Người nhận do nơi gửi tính (`ResolveBackupNotificationRecipients`: cùng người vận hành nhận thư sao lưu)
 * rồi truyền vào, như {@see BackupAlert}: lớp này chỉ lo hiển thị.
 *
 * Thư CHỈ mang loại sự cố và số đếm (`$figures`: `count`, `days_left`): không mã tệp Drive, không tiêu
 * đề tài liệu, mã hồ sơ, tên khách, cũng không email của thành viên lạ trên Shared Drive. Chi tiết nằm
 * ở `vkcrm:storage:check` và trang "Kho tài liệu", sau đăng nhập.
 */
class DocumentStoreAlert extends BrandedMailable
{
    /**
     * Xếp hàng được (`Mail::queue(...)->afterCommit()`), trên hàng `default` mà `queue.drain` rút. An toàn
     * để tuần tự hoá: lớp chỉ mang chuỗi và số, không model nào.
     */
    use Queueable;

    public const KIND_SHARING_DRIFT = 'sharing_drift';

    public const KIND_UNAVAILABLE = 'unavailable';

    public const KIND_MISCONFIGURED = 'misconfigured';

    public const KIND_NOT_ENABLED = 'not_enabled';

    public const KIND_PUSH_BACKLOG = 'push_backlog';

    public const KIND_OFFICE_COPY_STALE = 'office_copy_stale';

    public const KIND_OFFICE_COPY_ERROR = 'office_copy_error';

    public const KIND_TRANSFER_DOSSIER_DUE = 'transfer_dossier_due';

    public const TEMPLATE_PREFIX = 'staff.document_store_alert.';

    /**
     * @param  self::KIND_*  $kind
     * @param  array{count?: int, days_left?: int}  $figures
     * @param  list<string>  $recipients
     */
    public function __construct(
        public string $kind,
        public array $figures,
        public array $recipients,
    ) {}

    protected function template(): string
    {
        return self::TEMPLATE_PREFIX.$this->kind;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            to: $this->recipients,
            subject: __('document_store.alert.subject.'.$this->kind),
        );
    }

    /** Đọc thông tin văn phòng LÚC RENDER, không lúc xếp hàng — xem docblock `OfficeProfile`. */
    public function content(): Content
    {
        return new Content(
            view: 'emails.staff.document-store-alert',
            text: 'emails.staff.document-store-alert-text',
            with: [
                'heading' => __('document_store.alert.heading.'.$this->kind, [
                    'count' => (int) ($this->figures['count'] ?? 0),
                    'days_left' => (int) ($this->figures['days_left'] ?? 0),
                ]),
                'office' => OfficeProfile::current()->legalName(),
            ],
        );
    }
}
