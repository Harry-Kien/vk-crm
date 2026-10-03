<?php

namespace App\Jobs;

use App\Actions\Matter\BuildHandoverPackage;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Enums\OutboundStatus;
use App\Mail\Staff\HandoverPackageReady;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * M7 Task 4 — báo luật sư phụ trách rằng gói bàn giao đã sinh xong (thông báo trong hệ thống và
 * MỘT thư cho mỗi người nhận). Xếp hàng bởi {@see BuildHandoverPackage} `->afterCommit()`, trên hàng
 * `default` (một thư nhẹ, không nên chiếm hàng `handover` của job nén).
 *
 * Thư đi sau commit của gói, không bao giờ trong `DB::transaction` (M6.5 R2). Người nhận CHỈ qua
 * {@see ResolveStaffRecipients} (R3) và được tính lại LÚC GỬI, không lúc xếp hàng — người vừa nghỉ
 * việc hay vụ vừa siết thành `restricted` giữa hai thời điểm đó tự bị loại.
 *
 * # Gửi thẳng từ job, không `Mail::queue()` (việc sau gộp M7, làn fu2)
 *
 * Bản M7 xếp mỗi thư thành một job riêng bằng `Mail::queue()`. `BrandedMailable` không dùng
 * `SerializesModels`, nên cả model `User` (mã băm mật khẩu, `two_factor_secret`,
 * `two_factor_recovery_codes` đã mã hoá — `$hidden` không ảnh hưởng `serialize()`), `Matter` và
 * `Document` nằm nguyên trong `jobs.payload`, và sau năm lần hỏng thì nằm vĩnh viễn trong
 * `failed_jobs`. Nay job này — vốn đã ở trên hàng đợi và chỉ mang hai id — tự gửi bằng `Mail::send()`
 * như mọi job thư khác của `main` (`SendStaleMatterMail`, `SendMissingDocumentsMail`…).
 *
 * # Không báo trùng khi thử lại
 *
 * Thử từng người nhận độc lập, giữ lỗi ĐẦU TIÊN rồi ném lại để hàng đợi thử lại (`$tries`). Lần thử
 * sau chạy lại từ đầu, nên mỗi người được hỏi hai câu trước:
 *  - {@see self::alreadyDelivered()} — đã có dòng `sent` của mẫu `staff.handover_ready` về ĐÚNG tài
 *    liệu gói này tới người này: bỏ qua cả chuông lẫn thư;
 *  - {@see self::alreadyAlerted()} — đã có chuông cho ĐÚNG tài liệu gói này (khoá
 *    `viewData.handover_document_id`): không gửi chuông thứ hai cho người mà lần trước chỉ hỏng thư.
 * Chuông đi trước thư: người nhận có thư hỏng hẳn vẫn biết gói đã sẵn sàng.
 *
 * Bỏ qua (không gửi gì) nếu tài liệu gói này không còn là gói MỚI NHẤT của vụ: một lần sinh lại
 * xong trước khi thư này được gửi đã có thư riêng của nó, và báo "sẵn sàng" cho một version đã bị
 * thay là báo sai.
 */
class SendHandoverPackageReady implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly int $matterId,
        public readonly int $documentId,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(ResolveStaffRecipients $recipients): void
    {
        $matter = Matter::query()->withoutGlobalScope(ClientPortalScope::class)->find($this->matterId);

        $archive = MatterArchive::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->where('matter_id', $this->matterId)
            ->first();

        if ($matter === null || $archive === null || $archive->handover_document_id !== $this->documentId) {
            return;
        }

        $document = Document::query()->withoutGlobalScope(ClientPortalScope::class)->find($this->documentId);

        if ($document === null) {
            return;
        }

        $failure = null;

        foreach ($recipients->handle($matter, [$matter->leadLawyer, $archive->handoverRequester]) as $user) {
            if ($this->alreadyDelivered($document, $user)) {
                continue;
            }

            if (! $this->alreadyAlerted($user, $document)) {
                Notification::make()
                    ->title(__('handover.notification.ready_title'))
                    ->body(__('handover.notification.ready_body', ['code' => $matter->code]))
                    ->success()
                    ->viewData(['matter_id' => $matter->getKey(), 'handover_document_id' => $document->getKey()])
                    ->sendToDatabase($user);
            }

            try {
                Mail::to($user->email)->send(new HandoverPackageReady($user, $matter, $document));
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * Cùng hình dạng `NotifyStaffOfNewClientDocument::alreadyDelivered()`, cộng điều kiện `template`
     * (cùng lý do `SendStaleMatterMail::alreadyDelivered()`): chỉ một thư `staff.handover_ready` đã
     * gửi mới tính là "đã báo".
     */
    private function alreadyDelivered(Document $document, User $recipient): bool
    {
        return OutboundMessage::query()
            ->withoutGlobalScopes()
            ->where('related_type', $document->getMorphClass())
            ->where('related_id', $document->getKey())
            ->where('template', 'staff.handover_ready')
            ->where('recipient', $recipient->email)
            ->where('status', OutboundStatus::Sent)
            ->exists();
    }

    /** Cùng hình dạng `NotifyStaffOfNewClientDocument::alreadyAlerted()`, khoá theo tài liệu gói. */
    private function alreadyAlerted(User $recipient, Document $document): bool
    {
        return $recipient->notifications()
            ->where('data->viewData->handover_document_id', $document->getKey())
            ->exists();
    }
}
