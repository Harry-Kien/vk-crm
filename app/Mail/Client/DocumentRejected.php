<?php

namespace App\Mail\Client;

use App\Mail\BrandedMailable;
use App\Mail\OutboundHeaders;
use App\Models\ClientUser;
use App\Models\MatterChecklistItem;
use App\Support\PortalUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Mẫu `client.document_rejected` của SPEC §9 — báo khách một giấy tờ vừa nộp bị từ chối, kèm lý
 * do văn phòng đã viết (SPEC §6.7: tối thiểu 20 ký tự, viết cho khách đọc — xem docblock
 * `App\Actions\Document\ReviewChecklistItem`). Dispatch bởi `App\Events\ChecklistItemRejected`
 * (`ReviewChecklistItem::handle()`, nhánh từ chối) và gửi bởi
 * `App\Actions\Notification\NotifyClientOfChecklistItemRejected` qua listener
 * `App\Listeners\SendChecklistItemRejectedNotification`.
 *
 * **Tiêu đề KHÔNG BAO GIỜ nêu tên đầu mục hay lý do** — cùng lý lẽ với `DocumentPublished`: dòng
 * `outbound_messages` hiện cho mọi người xem được vụ việc. Chỉ mã hồ sơ và một câu chung; tên đầu
 * mục và lý do chỉ nằm trong THÂN thư.
 *
 * **R6:** `rejection_reason` là câu văn phòng ĐÃ VIẾT ĐỂ KHÁCH ĐỌC (nó hiện nguyên văn trên portal
 * — SPEC §6.7, §8.3 mục 4), không phải một ghi chú nội bộ; đưa nó vào thân thư không phải một rò
 * rỉ, nó là đúng SPEC §9 ("nội dung thông báo dựa trên bản ghi ĐÃ CÔNG BỐ").
 *
 * **Khoá chống gửi trùng mang thêm LẦN TỪ CHỐI** (`reviewed_at`), không chỉ `related` + `recipient`
 * — xem {@see self::additionalLedgerHeaders()}: hai lần từ chối khác nhau của CÙNG một đầu mục
 * (khách nộp lại, bị từ chối lần nữa) phải là HAI thư, cùng lối bậc@ngày của M6.5 Task 14.
 */
class DocumentRejected extends BrandedMailable
{
    public function __construct(
        public MatterChecklistItem $checklistItem,
        public ClientUser $recipient,
    ) {}

    protected function template(): string
    {
        return 'client.document_rejected';
    }

    protected function relatedRecord(): ?Model
    {
        return $this->checklistItem;
    }

    /**
     * Khoá chống gửi trùng của nhật ký thư — xem {@see self::ledgerKeyFor()} và docblock lớp.
     *
     * @return array<string, string>
     */
    protected function additionalLedgerHeaders(): array
    {
        return [OutboundHeaders::LEDGER_TIER => self::ledgerKeyFor($this->checklistItem)];
    }

    /**
     * MỘT định nghĩa duy nhất của khoá "lần từ chối nào" — mailable ghi nó vào header, và
     * `App\Actions\Notification\NotifyClientOfChecklistItemRejected::alreadyDelivered()` so đúng
     * chuỗi này khi đọc lại nhật ký thư. `reviewed_at` (không phải `updated_at`) vì đó là cột
     * `ReviewChecklistItem::handle()` ghi ĐÚNG LÚC quyết định từ chối này được lưu — ổn định qua
     * mọi lần đọc lại sau đó của cùng một lần từ chối.
     */
    public static function ledgerKeyFor(MatterChecklistItem $checklistItem): string
    {
        return 'rejected@'.($checklistItem->reviewed_at?->toISOString() ?? 'unknown');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('portal.email.document_rejected.subject', [
                'code' => $this->checklistItem->matter?->code ?? '',
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.client.document-rejected',
            text: 'emails.client.document-rejected-text',
            with: [
                'name' => $this->recipient->name,
                'matterCode' => $this->checklistItem->matter?->code,
                'itemName' => $this->checklistItem->name,
                'reason' => $this->checklistItem->rejection_reason,
                'portalUrl' => PortalUrl::base(),
                'office' => config('vkcrm.brand.legal_name'),
                'hotline' => config('vkcrm.brand.hotline'),
            ],
        );
    }
}
