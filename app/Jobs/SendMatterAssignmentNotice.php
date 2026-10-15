<?php

namespace App\Jobs;

use App\Actions\Notification\ResolveStaffRecipients;
use App\Enums\MatterAssignmentStatus;
use App\Enums\OutboundStatus;
use App\Mail\Staff\MatterAssignmentNotice;
use App\Models\Matter;
use App\Models\MatterUser;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Notifications\Staff\MatterAssignmentAlert;
use App\Support\ActivityPeople;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;

/**
 * Lời báo của MỘT lần giao / nhận / trả lại một ghế trong đội ngũ vụ (làn tk, Task 2) cho MỘT người:
 * chuông ({@see MatterAssignmentAlert}), cộng thư ({@see MatterAssignmentNotice}) với loại "trả lại".
 * `AddTeamMember`, `ReassignMatter`, `AcceptMatterAssignment`, `DeclineMatterAssignment` xếp job này
 * `->afterCommit()` (M6.5 R2).
 *
 * # Đọc lại mọi thứ khi chạy
 *
 *  1. vụ còn (chưa huỷ), dòng nhật ký của lần đó còn, người nhận còn;
 *  2. lời báo còn ĐÚNG — ghế vẫn còn và vẫn ở tình trạng lời báo nói: "được giao" chỉ khi ghế còn chờ
 *     nhận, "đã nhận" khi ghế đã nhận, "trả lại" khi ghế còn trả lại (người đó đã bị gỡ hay ghế đã được
 *     giao lại thì việc đã được xử lý — không báo một chuyện cũ);
 *  3. người nhận qua `ResolveStaffRecipients::qualifies()` — đang hoạt động, chưa xoá, còn xem được vụ.
 *     Không chuỗi dự phòng: lời báo nói với đúng một người.
 *
 * # Chống trùng khi thử lại
 *
 * Khoá `event@<id dòng nhật ký>`: chuông đã có `viewData.seat_key` đó thì không gửi lại; thư đã có dòng sổ
 * thư `sent` cùng mẫu, cùng người, cùng `payload.tier` thì không gửi lại. Chuông đi trước thư.
 */
class SendMatterAssignmentNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly int $matterId,
        public readonly int $memberId,
        public readonly string $kind,
        public readonly int $recipientId,
        public readonly int $eventId,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(ResolveStaffRecipients $recipients): void
    {
        $matter = Matter::query()->withoutGlobalScope(ClientPortalScope::class)->find($this->matterId);
        $event = Activity::query()->find($this->eventId);
        $recipient = User::query()->find($this->recipientId);
        $member = User::query()->withTrashed()->find($this->memberId);

        if ($matter === null || $event === null || $recipient === null || $member === null) {
            return;
        }

        $seat = MatterUser::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->where('matter_id', $matter->getKey())
            ->where('user_id', $member->getKey())
            ->first();

        if ($seat === null || $seat->assignmentStatus() !== $this->expectedStatus() || ! $recipients->qualifies($recipient, $matter)) {
            return;
        }

        $key = self::ledgerKey($this->eventId);
        $role = $seat->role_in_matter->label();

        if (! self::alreadyAlerted($recipient, $key)) {
            $recipient->notify(new MatterAssignmentAlert(
                $matter,
                $this->kind,
                (string) $member->name,
                ActivityPeople::name($event->causer) ?? '',
                $role,
                $seat->decline_reason,
                $key,
            ));
        }

        if ($this->kind !== MatterAssignmentAlert::DECLINED || self::alreadyMailed($matter, $recipient, MatterAssignmentNotice::KIND_DECLINED, $key)) {
            return;
        }

        Mail::to($recipient->email)->send(new MatterAssignmentNotice(
            $matter,
            MatterAssignmentNotice::KIND_DECLINED,
            $recipient,
            (string) $member->name,
            $role,
            $seat->decline_reason,
            '',
            $key,
        ));
    }

    public static function ledgerKey(int $eventId): string
    {
        return 'event@'.$eventId;
    }

    /** Chuông `MatterAssignmentAlert` mang khoá `$key` đã tới `$recipient` chưa. */
    public static function alreadyAlerted(User $recipient, string $key): bool
    {
        return $recipient->notifications()
            ->where('type', MatterAssignmentAlert::class)
            ->where('data->viewData->seat_key', $key)
            ->exists();
    }

    /** Thư mẫu `staff.task.matter.<$kind>` mang khoá `$key` đã gửi thành công tới `$recipient` chưa. */
    public static function alreadyMailed(Matter $matter, User $recipient, string $kind, string $key): bool
    {
        return OutboundMessage::query()
            ->withoutGlobalScopes()
            ->where('related_type', $matter->getMorphClass())
            ->where('related_id', $matter->getKey())
            ->where('template', MatterAssignmentNotice::TEMPLATE_PREFIX.$kind)
            ->where('recipient', $recipient->email)
            ->where('payload->tier', $key)
            ->where('status', OutboundStatus::Sent)
            ->exists();
    }

    /** Xem docblock lớp, mục "Đọc lại mọi thứ khi chạy", bước 2. */
    private function expectedStatus(): MatterAssignmentStatus
    {
        return match ($this->kind) {
            MatterAssignmentAlert::ACCEPTED => MatterAssignmentStatus::Accepted,
            MatterAssignmentAlert::DECLINED => MatterAssignmentStatus::Declined,
            default => MatterAssignmentStatus::Pending,
        };
    }
}
