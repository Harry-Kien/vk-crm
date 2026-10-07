<?php

namespace App\Mail\Client;

use App\Actions\Notification\NotifyClientOfDocumentPublished;
use App\Actions\Notification\ResolveClientRecipients;
use App\Mail\BrandedMailable;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\MatterArchive;
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
 *
 * **Gói bàn giao hồ sơ (việc sau gộp M7, làn fu2).** Cùng mẫu (cùng sự kiện SPEC §9 "Công bố văn
 * bản nhóm B hoặc C", cùng nút "Gửi lại"), nhưng khi tài liệu là gói bàn giao HIỆN TẠI của vụ
 * ({@see MatterArchive::whereCurrentHandoverPackageIs()}) thì tiêu đề và thân thư là của gói: nói đây
 * là gói hồ sơ bàn giao (các tài liệu của hồ sơ cùng MUC-LUC.pdf) và hạn tải theo
 * `client_access_until` — sau ngày đó vụ rời cổng (M7 Task 5). Không in tên tài liệu nào, kể cả tiêu
 * đề của gói. Bốn câu, theo hai câu hỏi đọc LÚC RENDER: khách có được tải không
 * (`client_can_download` — công bố "chỉ xem" thì không hứa tải), và có hạn không
 * (`client_access_until` null khi vụ đã được mở lại — không bịa ra một hạn). Đọc lúc render chứ
 * không lúc dựng, nên lần "Gửi lại" in đúng tình trạng lúc gửi lại. Người nhận vẫn do
 * {@see NotifyClientOfDocumentPublished} quyết; lớp này chỉ chọn câu chữ.
 */
class DocumentPublished extends BrandedMailable
{
    /**
     * Tên mẫu SPEC §9. Hằng công khai vì {@see NotifyClientOfDocumentPublished::alreadyDelivered()}
     * lọc nhật ký thư theo đúng chuỗi này (làn fu3, Task 1 mục B).
     */
    public const TEMPLATE = 'client.document_published';

    public function __construct(
        public Document $document,
        public ClientUser $recipient,
    ) {}

    protected function template(): string
    {
        return self::TEMPLATE;
    }

    protected function relatedRecord(): ?Model
    {
        return $this->document;
    }

    public function envelope(): Envelope
    {
        $key = $this->handoverArchive() === null ? 'document_published' : 'handover_published';

        return new Envelope(
            subject: __("portal.email.{$key}.subject", [
                'code' => $this->document->matter?->code ?? '',
            ]),
        );
    }

    /** Đọc thông tin văn phòng LÚC RENDER, không lúc xếp hàng — xem docblock `OfficeProfile`. */
    public function content(): Content
    {
        $office = OfficeProfile::current();
        $archive = $this->handoverArchive();

        $common = [
            'name' => $this->recipient->name,
            'matterCode' => $this->document->matter?->code,
            'portalUrl' => PortalUrl::base(),
            'office' => $office->legalName(),
            'hotline' => $office->hotline(),
        ];

        if ($archive !== null) {
            return new Content(
                view: 'emails.client.handover-published',
                text: 'emails.client.handover-published-text',
                with: [...$common, 'accessLine' => $this->handoverAccessLine($archive)],
            );
        }

        return new Content(
            view: 'emails.client.document-published',
            text: 'emails.client.document-published-text',
            with: [
                ...$common,
                'documentTitle' => $this->document->title,
                'groupLabel' => $this->document->group->label(),
            ],
        );
    }

    private function handoverArchive(): ?MatterArchive
    {
        return MatterArchive::whereCurrentHandoverPackageIs($this->document);
    }

    /**
     * Câu "tải được tới bao giờ" của thư gói bàn giao — xem docblock lớp cho bốn trường hợp.
     */
    private function handoverAccessLine(MatterArchive $archive): string
    {
        $mode = $this->document->client_can_download ? 'download' : 'view_only';

        if ($archive->client_access_until === null) {
            return __("portal.email.handover_published.{$mode}");
        }

        return __("portal.email.handover_published.{$mode}_until", [
            'date' => $archive->client_access_until->format('d/m/Y'),
        ]);
    }
}
