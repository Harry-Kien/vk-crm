<?php

namespace App\Mail\Staff;

use App\Filament\Admin\Resources\Matters\RelationManagers\DocumentsRelationManager;
use App\Jobs\SendDocumentReviewNotice;
use App\Mail\BrandedMailable;
use App\Mail\OutboundHeaders;
use App\Models\Document;
use App\Models\User;
use App\Support\MatterTab;
use App\Support\OfficeProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Mẫu `staff.document_review.submitted` (làn fa2, phán quyết 7): "một văn bản chờ anh/chị duyệt" — gửi người duyệt
 * khi một văn bản nhóm B được "Trình duyệt" ({@see SendDocumentReviewNotice}, sau khi đã lọc người nhận). Họ mẫu
 * `staff.document_review.*` (hằng `KIND_*`): hôm nay một loại — duyệt xong hay trả về chỉ báo bằng chuông.
 *
 * Tiêu đề không mang mã hồ sơ, tên văn bản hay tên khách (hiện trên màn hình khoá); thân thư mang tên văn bản, mã
 * hồ sơ và người trình — người nhận mở được hồ sơ ở lúc gửi. Bản ghi liên quan là tài liệu. `LEDGER_TIER` mang khoá
 * của lần trình. Không gửi lại được từ nhật ký thư (`ResendTargets::NOT_RESENDABLE`).
 */
class DocumentReviewRequest extends BrandedMailable
{
    public const KIND_SUBMITTED = 'submitted';

    public const TEMPLATE_PREFIX = 'staff.document_review.';

    public function __construct(
        public Document $document,
        public User $recipient,
        public string $actorName,
        public string $ledgerKey,
        public string $kind = self::KIND_SUBMITTED,
    ) {}

    protected function template(): string
    {
        return self::TEMPLATE_PREFIX.$this->kind;
    }

    protected function relatedRecord(): ?Model
    {
        return $this->document;
    }

    /** @return array<string, string> */
    protected function additionalLedgerHeaders(): array
    {
        return [OutboundHeaders::LEDGER_TIER => $this->ledgerKey];
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('internal_flow.document_review.email.subject'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.staff.task-notice',
            text: 'emails.staff.task-notice-text',
            with: [
                'recipientName' => $this->recipient->name,
                'headline' => __('internal_flow.document_review.email.headline', ['who' => $this->actorName]),
                'details' => [
                    ['label' => __('internal_flow.document_review.email.fields.title'), 'value' => (string) $this->document->title],
                    ['label' => __('internal_flow.document_review.email.fields.matter'), 'value' => (string) $this->document->matter?->code],
                    ['label' => __('internal_flow.document_review.email.fields.submitter'), 'value' => $this->actorName],
                ],
                'url' => MatterTab::url((int) $this->document->matter_id, DocumentsRelationManager::class),
                'action' => __('internal_flow.document_review.email.action'),
                'office' => OfficeProfile::current()->legalName(),
            ],
        );
    }
}
