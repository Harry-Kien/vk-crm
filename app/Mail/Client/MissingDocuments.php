<?php

namespace App\Mail\Client;

use App\Actions\Document\ChecklistProgress;
use App\Actions\Notification\ResolveClientRecipients;
use App\Actions\Schedule\RemindMissingDocuments;
use App\Enums\ChecklistItemStatus;
use App\Jobs\SendMissingDocumentsMail;
use App\Mail\BrandedMailable;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Support\PortalUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

/**
 * Mẫu `client.missing_documents` của SPEC §6.9/§9 — nhắc khách những giấy tờ BẮT BUỘC còn thiếu
 * của một hồ sơ. Xếp bởi {@see RemindMissingDocuments}, gửi bởi {@see SendMissingDocumentsMail}
 * tới các tài khoản đã qua {@see ResolveClientRecipients} (R12).
 *
 * **R7 — thư nói phải làm gì:** liệt kê ĐÚNG những đầu mục bắt buộc còn thiếu, bằng tên người
 * thường đọc được, kèm một liên kết vào cổng. Danh sách (`$items`) do JOB tính lại ngay lúc gửi
 * bằng {@see ChecklistProgress::outstandingRequiredItems()} và truyền vào — mailable không tự truy
 * vấn, nên một thư đã dựng luôn nói đúng những gì job đã quyết định gửi.
 *
 * **Tiêu đề KHÔNG BAO GIỜ nêu tên giấy tờ** (cùng lý lẽ `DocumentPublished`: dòng
 * `outbound_messages` hiện cho mọi người xem được vụ việc, kể cả trợ lý). Chỉ mã hồ sơ — và mã
 * hồ sơ là bắt buộc, không phải trang trí: một người có thể đại diện hai khách hàng (hai tài
 * khoản), tiêu đề là chỗ duy nhất họ phân biệt được hồ sơ nào cần gì.
 *
 * **R6 — chỉ nội dung khách đã được đọc.** Thân thư lấy đúng ba trường mà cổng vẫn hiện cho khách
 * ở khối "việc anh/chị cần làm" (`MatterProgress::checklistItems()`): `name`, trạng thái, và
 * `rejection_reason` — CHỈ khi đầu mục đang `rejected` (một lý do còn sót lại trên đầu mục đã trở
 * về `missing` không phải thứ khách được đọc). Không đụng tới `matters.description_internal` hay
 * bất kỳ cột nội bộ nào; tiêu đề vụ việc cũng không cần nên không có mặt.
 */
class MissingDocuments extends BrandedMailable
{
    /**
     * @param  Collection<int, MatterChecklistItem>  $items  Đầu mục bắt buộc còn thiếu, theo thứ tự danh mục.
     */
    public function __construct(
        public Matter $matter,
        public ClientUser $recipient,
        public Collection $items,
    ) {}

    protected function template(): string
    {
        return 'client.missing_documents';
    }

    protected function relatedRecord(): ?Model
    {
        return $this->matter;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('portal.email.missing_documents.subject', [
                'code' => $this->matter->code,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.client.missing-documents',
            text: 'emails.client.missing-documents-text',
            with: [
                'name' => $this->recipient->name,
                'matterCode' => $this->matter->code,
                'lines' => $this->items->map(fn (MatterChecklistItem $item): array => [
                    'name' => $item->name,
                    'rejected' => $item->status === ChecklistItemStatus::Rejected,
                    'reason' => $item->status === ChecklistItemStatus::Rejected && filled($item->rejection_reason)
                        ? $item->rejection_reason
                        : null,
                ])->all(),
                'portalUrl' => PortalUrl::base(),
                'office' => config('vkcrm.brand.legal_name'),
                'hotline' => config('vkcrm.brand.hotline'),
            ],
        );
    }
}
