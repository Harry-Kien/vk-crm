<?php

namespace App\Jobs;

use App\Actions\Intake\ConvertIntakeToMatter;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\User;
use App\Notifications\Staff\IntakeConvertedAlert;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Chuông TIN cho người đã xử lý một bản ghi tiếp nhận (người được giao bản ghi) khi người khác chuyển nó thành hồ sơ
 * (gộp integ-fa, rà soát chéo 2026-10-18, mục 15). {@see ConvertIntakeToMatter} xếp `->afterCommit()` trong
 * transaction lưu của `OpenMatter`; payload chỉ mang id.
 *
 * Đọc lại lúc chạy: bản ghi còn, vẫn trỏ tới hồ sơ đó; người nhận còn hoạt động, chưa xoá, không phải người bấm và
 * không phải luật sư phụ trách HIỆN TẠI của hồ sơ (người đó có lời báo "được giao hồ sơ" riêng). Mã hồ sơ và tên luật sư
 * phụ trách chỉ khi người nhận mở được hồ sơ (`ResolveStaffRecipients::qualifies()`); không thì câu chung. Khoá
 * `intake@<id dòng intake_converted>` chống trùng khi thử lại.
 */
class SendIntakeConvertedNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly int $intakeId,
        public readonly int $recipientId,
        public readonly int $actorId,
        public readonly int $eventId,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(ResolveStaffRecipients $recipients): void
    {
        $intake = IntakeRequest::query()->withoutGlobalScope(ClientPortalScope::class)->find($this->intakeId);
        $recipient = User::query()->where('is_active', true)->find($this->recipientId);

        if ($intake === null || $intake->matter_id === null || $recipient === null || $recipient->getKey() === $this->actorId) {
            return;
        }

        $matter = Matter::query()->withoutGlobalScope(ClientPortalScope::class)->with('leadLawyer')->find($intake->matter_id);

        if ($matter === null || $matter->lead_lawyer_id === $recipient->getKey()) {
            return;
        }

        $key = 'intake@'.$this->eventId;

        if ($recipient->notifications()->where('type', IntakeConvertedAlert::class)->where('data->viewData->intake_converted_key', $key)->exists()) {
            return;
        }

        $opens = $recipients->qualifies($recipient, $matter);

        $recipient->notify(new IntakeConvertedAlert(
            (string) $intake->code,
            (string) User::query()->withTrashed()->whereKey($this->actorId)->value('name'),
            $opens ? (int) $matter->getKey() : null,
            $opens ? (string) $matter->code : null,
            $opens ? (string) $matter->leadLawyer?->name : null,
            $key,
        ));
    }
}
