<?php

namespace App\Jobs;

use App\Actions\Document\MarkDocumentSignedFiled;
use App\Actions\Document\ReturnDocumentToDraft;
use App\Actions\Document\SubmitDocumentForApproval;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Enums\DocumentStatus;
use App\Enums\OutboundStatus;
use App\Mail\Staff\DocumentReviewRequest;
use App\Models\Document;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Notifications\Staff\DocumentReviewAlert;
use App\Support\ActivityPeople;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;

/**
 * Lời báo của MỘT bước trong vòng trình duyệt văn bản nội bộ nhóm B (làn fa2, phán quyết 7). Xếp `->afterCommit()`
 * bởi {@see SubmitDocumentForApproval} ({@see DocumentReviewAlert::SUBMITTED}), {@see MarkDocumentSignedFiled}
 * ({@see DocumentReviewAlert::APPROVED}) và {@see ReturnDocumentToDraft} ({@see DocumentReviewAlert::RETURNED});
 * payload chỉ mang id tài liệu, loại, id dòng nhật ký của bước đó.
 *
 * # Ai nhận, đọc lại lúc chạy
 *
 *  - "Trình duyệt": người DUYỆT — luật sư phụ trách nếu còn hợp lệ, không thì chuỗi dự phòng của
 *    `ResolveStaffRecipients::handle()` (người quản lý được xem vụ → quản trị viên), MỌI tầng đều phải duyệt được
 *    chính văn bản (`DocumentPolicy::publish` — `document.publish` trên hồ sơ mình mở được). Trừ người trình: luật sư
 *    tự trình văn bản của mình thì không ai bị báo. Chuông + thư {@see DocumentReviewRequest}.
 *  - "Đã ký, đã nộp" / "Trả về bản nháp": người ĐÃ TRÌNH lần gần nhất (người ghi dòng
 *    `document_submitted_for_approval` mới nhất trước bước này), nếu khác người bấm và còn mở được hồ sơ
 *    (`qualifies()`). Chỉ chuông; lời trả về mang lý do đọc từ dòng nhật ký của chính lần trả về.
 *
 * Lời báo phải còn ĐÚNG: tài liệu còn (chưa xoá), hồ sơ còn, và trạng thái vẫn là trạng thái bước đó để lại
 * ("Chờ duyệt"; "Đã ký, đã nộp" hay đã công bố sau đó; "Bản thảo nội bộ") — đã chuyển tiếp thì không báo chuyện cũ.
 *
 * # Chống trùng khi thử lại
 *
 * Khoá `event@<id dòng nhật ký>` ở `viewData.document_review_key` của chuông và `payload.tier` của dòng sổ thư.
 */
class SendDocumentReviewNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly int $documentId,
        public readonly string $kind,
        public readonly int $eventId,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(ResolveStaffRecipients $recipients): void
    {
        $document = Document::query()->withoutGlobalScope(ClientPortalScope::class)->find($this->documentId);
        $event = Activity::query()->find($this->eventId);

        if ($document === null || $event === null || ! in_array($document->status, $this->expectedStatuses(), true)) {
            return;
        }

        $matter = Matter::query()->withoutGlobalScope(ClientPortalScope::class)->find($document->matter_id);

        if ($matter === null) {
            return;
        }

        $document->setRelation('matter', $matter);
        $actorId = $event->causer_id === null ? null : (int) $event->causer_id;
        $actorName = ActivityPeople::name($event->causer) ?? '';
        $key = 'event@'.$this->eventId;

        foreach ($this->recipientsFor($recipients, $document, $matter, $actorId) as $recipient) {
            $alreadyRung = $recipient->notifications()
                ->where('type', DocumentReviewAlert::class)
                ->where('data->viewData->document_review_key', $key)
                ->exists();

            if (! $alreadyRung) {
                $recipient->notify(new DocumentReviewAlert(
                    $document,
                    $this->kind,
                    $actorName,
                    $key,
                    $this->kind === DocumentReviewAlert::RETURNED ? (string) $event->getExtraProperty('reason') : null,
                ));
            }

            if ($this->kind === DocumentReviewAlert::SUBMITTED && ! $this->alreadyMailed($document, $recipient, $key)) {
                Mail::to($recipient->email)->send(new DocumentReviewRequest($document, $recipient, $actorName, $key));
            }
        }
    }

    /** @return Collection<int, User> */
    private function recipientsFor(ResolveStaffRecipients $recipients, Document $document, Matter $matter, ?int $actorId): Collection
    {
        if ($this->kind === DocumentReviewAlert::SUBMITTED) {
            return $recipients->handle($matter, [$matter->leadLawyer], ['publish' => $document])
                ->reject(fn (User $user): bool => $user->getKey() === $actorId)
                ->values();
        }

        $submitterId = Activity::query()
            ->where('subject_type', $document->getMorphClass())
            ->where('subject_id', $document->getKey())
            ->where('event', 'document_submitted_for_approval')
            ->where('id', '<', $this->eventId)
            ->orderByDesc('id')
            ->value('causer_id');

        $submitter = $submitterId === null ? null : User::query()->find($submitterId);

        if ($submitter === null || $submitter->getKey() === $actorId || ! $recipients->qualifies($submitter, $matter)) {
            return collect();
        }

        return collect([$submitter]);
    }

    /** @return list<DocumentStatus> */
    private function expectedStatuses(): array
    {
        return match ($this->kind) {
            DocumentReviewAlert::SUBMITTED => [DocumentStatus::PendingApproval],
            DocumentReviewAlert::APPROVED => [DocumentStatus::SignedFiled, DocumentStatus::Published],
            default => [DocumentStatus::InternalDraft],
        };
    }

    private function alreadyMailed(Document $document, User $recipient, string $key): bool
    {
        return OutboundMessage::query()
            ->withoutGlobalScopes()
            ->where('related_type', $document->getMorphClass())
            ->where('related_id', $document->getKey())
            ->where('template', DocumentReviewRequest::TEMPLATE_PREFIX.DocumentReviewRequest::KIND_SUBMITTED)
            ->where('recipient', $recipient->email)
            ->where('payload->tier', $key)
            ->where('status', OutboundStatus::Sent)
            ->exists();
    }
}
