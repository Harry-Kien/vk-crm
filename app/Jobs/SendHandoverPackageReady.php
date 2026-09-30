<?php

namespace App\Jobs;

use App\Actions\Matter\BuildHandoverPackage;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Mail\Staff\HandoverPackageReady;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Support\Scopes\ClientPortalScope;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * M7 Task 4 — báo luật sư phụ trách rằng gói bàn giao đã sinh xong (thông báo trong hệ thống và
 * MỘT thư xếp hàng cho mỗi người nhận). Xếp hàng bởi {@see BuildHandoverPackage} `->afterCommit()`,
 * trên hàng `default` (một thư nhẹ, không nên chiếm hàng `handover` của job nén).
 *
 * Thư đi sau commit của gói, không bao giờ trong `DB::transaction` (M6.5 R2), và mỗi thư là MỘT job
 * riêng (`Mail::queue`) — một hộp thư hỏng không kéo job này thử lại và gửi trùng cho những người đã
 * nhận. Người nhận CHỈ qua {@see ResolveStaffRecipients} (R3) và được tính lại LÚC GỬI, không lúc xếp
 * hàng — người vừa nghỉ việc hay vụ vừa siết thành `restricted` giữa hai thời điểm đó tự bị loại.
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

        foreach ($recipients->handle($matter, [$matter->leadLawyer, $archive->handoverRequester]) as $user) {
            Notification::make()
                ->title(__('handover.notification.ready_title'))
                ->body(__('handover.notification.ready_body', ['code' => $matter->code]))
                ->success()
                ->sendToDatabase($user);

            Mail::to($user->email)->queue(new HandoverPackageReady($user, $matter, $document));
        }
    }
}
