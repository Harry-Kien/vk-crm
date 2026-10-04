<?php

namespace App\Mail\Staff;

use App\Mail\BrandedMailable;
use App\Models\Document;
use App\Models\User;
use App\Support\OfficeProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Mẫu `staff.new_client_document` của SPEC §9 — khách vừa nộp tài liệu qua cổng
 * (`App\Actions\Document\SubmitClientDocument`), kích hoạt bởi `App\Events\ClientDocumentSubmitted`
 * và gửi bởi `App\Actions\Notification\NotifyStaffOfNewClientDocument`.
 *
 * `$firstDocument` là ĐẠI DIỆN của cả lô (mọi tài liệu trong một lần nộp cùng một đầu mục danh
 * mục — xem docblock `App\Events\ClientDocumentSubmitted`); `$count` là số tệp thật của lô đó,
 * để thư nói đúng "hai tệp" thay vì chỉ nhắc một cái tên rồi im lặng về cái còn lại (CCCD hai
 * mặt là ca thường gặp nhất).
 *
 * Thư gửi NHÂN SỰ: được phép mang mã hồ sơ và tên đầu mục danh mục trong THÂN thư — cùng lý lẽ
 * `App\Mail\Staff\NewClientRequest`. **Tiêu đề KHÔNG mang tên đầu mục** (cùng luật "tiêu đề
 * không nêu tên tài liệu" mang từ M6.5 Task 13/M6 Task 3 sang task này): chỉ mã hồ sơ và một câu
 * chung.
 */
class NewClientDocument extends BrandedMailable
{
    /**
     * Tên mẫu SPEC §9. Hằng công khai vì `App\Actions\Notification\NotifyStaffOfNewClientDocument::
     * alreadyDelivered()` lọc nhật ký thư theo đúng chuỗi này (làn fu3, Task 1 mục B).
     */
    public const TEMPLATE = 'staff.new_client_document';

    public function __construct(
        public Document $firstDocument,
        public int $count,
        public User $recipient,
    ) {}

    protected function template(): string
    {
        return self::TEMPLATE;
    }

    protected function relatedRecord(): ?Model
    {
        return $this->firstDocument;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('requests.email.new_document.subject', [
                'code' => $this->firstDocument->matter?->code ?? '',
            ]),
        );
    }

    /** Đọc thông tin văn phòng LÚC RENDER, không lúc xếp hàng — xem docblock `OfficeProfile`. */
    public function content(): Content
    {
        $office = OfficeProfile::current();

        return new Content(
            view: 'emails.staff.new-client-document',
            text: 'emails.staff.new-client-document-text',
            with: [
                'recipientName' => $this->recipient->name,
                'matterCode' => $this->firstDocument->matter?->code,
                'matterTitle' => $this->firstDocument->matter?->title,
                'itemName' => $this->firstDocument->checklistItem?->name,
                'count' => $this->count,
                'office' => $office->legalName(),
            ],
        );
    }
}
