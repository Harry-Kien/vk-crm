<?php

namespace App\Mail\Client;

use App\Actions\Notification\ResolveClientRecipients;
use App\Mail\BrandedMailable;
use App\Models\ClientUser;
use App\Models\Document;
use App\Support\OfficeProfile;
use App\Support\PortalUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Mẫu `client.document_published` của SPEC §9 — báo khách có một văn bản mới (nhóm B "Issued" hay
 * nhóm C "Authority") vừa được đưa ra tới họ. Dispatch bởi `App\Events\DocumentPublished`
 * (`PublishDocument::handle()`, chỉ lần công bố ĐẦU) và gửi bởi
 * `App\Actions\Notification\NotifyClientOfDocumentPublished` qua listener
 * `App\Listeners\SendDocumentPublishedNotification`.
 *
 * **Tiêu đề KHÔNG BAO GIỜ nêu tên tài liệu** (sổ tay M6.5 Task 13, "Carried to M6 Task 3/4"):
 * dòng `outbound_messages` của thư về tài liệu hiện ra cho MỌI người xem được vụ việc, kể cả trợ
 * lý, trên màn hình nhật ký thư (M6.5 Task 13) — payload của dòng đó lưu nguyên văn `subject`. Một
 * tiêu đề mang tên văn bản là rò rỉ nội dung nhóm B/C (có thể là một quyết định, một bản án) cho
 * một người chỉ có quyền xem NHẬT KÝ THƯ chứ không có quyền xem chính văn bản. Tiêu đề vì vậy chỉ
 * mang mã hồ sơ và một câu chung; TÊN tài liệu chỉ nằm trong THÂN thư — nơi chỉ người nhận (đã qua
 * {@see ResolveClientRecipients}) đọc được.
 *
 * **R6:** thân thư chỉ lấy từ các cột đã CÔNG BỐ của `Document` (`title`, `group`) — không đụng
 * tới bất kỳ cột nội bộ nào (không có `internal_note` ở `Document`, nhưng nguyên tắc vẫn được nhắc
 * ở đây cho người đọc sau: không thêm trường nào từ `Document` vào view mà chưa tự hỏi "khách đã
 * được phép đọc cái này chưa").
 */
class DocumentPublished extends BrandedMailable
{
    public function __construct(
        public Document $document,
        public ClientUser $recipient,
    ) {}

    protected function template(): string
    {
        return 'client.document_published';
    }

    protected function relatedRecord(): ?Model
    {
        return $this->document;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('portal.email.document_published.subject', [
                'code' => $this->document->matter?->code ?? '',
            ]),
        );
    }

    /** Đọc thông tin văn phòng LÚC RENDER, không lúc xếp hàng — xem docblock `OfficeProfile`. */
    public function content(): Content
    {
        $office = OfficeProfile::current();

        return new Content(
            view: 'emails.client.document-published',
            text: 'emails.client.document-published-text',
            with: [
                'name' => $this->recipient->name,
                'matterCode' => $this->document->matter?->code,
                'documentTitle' => $this->document->title,
                'groupLabel' => $this->document->group->label(),
                'portalUrl' => PortalUrl::base(),
                'office' => $office->legalName(),
                'hotline' => $office->hotline(),
            ],
        );
    }
}
