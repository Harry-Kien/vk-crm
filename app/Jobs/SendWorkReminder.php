<?php

namespace App\Jobs;

use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Schedule\RemindPendingWork;
use App\Enums\OutboundStatus;
use App\Mail\Staff\MatterAssignmentNotice;
use App\Mail\Staff\TaskReminder;
use App\Models\Matter;
use App\Models\MatterUser;
use App\Models\OutboundMessage;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\MatterAssignmentAlert;
use App\Notifications\Staff\TaskReminderAlert;
use App\Support\Intake\FirstResponseClock;
use App\Support\Scopes\ClientPortalScope;
use App\Support\Tasks\AcceptanceClock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * MỘT lời nhắc của lịch {@see RemindPendingWork} (làn tk, Task 2) cho MỘT người: chuông rồi thư — với một
 * việc ({@see TaskReminderAlert} + {@see TaskReminder}) hay một hồ sơ chờ nhận ({@see MatterAssignmentAlert}
 * + {@see MatterAssignmentNotice}). Lịch xếp job `->afterCommit()` (M6.5 R2).
 *
 * # Đọc lại mọi thứ khi chạy
 *
 * Job nhận id, không nhận model: giữa lúc xếp và lúc chạy, người nhận có thể đã bấm "Đã nhận", việc có thể
 * đã xong hay đã dời hạn, vụ có thể đã kết thúc hay bị siết hạn chế. Ba lần lọc:
 *  1. việc/ghế còn, và lời nhắc còn ĐÚNG với khoá của nó — {@see RemindPendingWork::taskReminderHolds()} /
 *     {@see RemindPendingWork::seatReminderHolds()}, MỘT định nghĩa với lượt chọn;
 *  2. người nhận: việc qua `ResolveStaffRecipients::forTask()` (còn xem được việc), hồ sơ qua
 *     `ResolveStaffRecipients::qualifies()` (còn xem được vụ);
 *  3. chống trùng: chuông đã có `viewData.reminder_key`/`seat_key` = {@see self::fullKey()} thì không gửi
 *     lại; thư đã có dòng sổ thư `sent` cùng mẫu, cùng người, cùng bản ghi, cùng `payload.tier` thì không
 *     gửi lại ({@see self::alreadyMailed()} — lịch cũng hỏi câu này trước khi xếp).
 *
 * `ShouldBeUnique` theo (đối tượng, loại, người, khoá): hai lượt lịch liền nhau không xếp hai job cho cùng
 * một lời nhắc khi job đầu còn chờ hàng.
 */
class SendWorkReminder implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** Khoá duy nhất giữ tối đa một giờ — sau đó sổ thư là lưới chống trùng. */
    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $subject,
        public readonly int $subjectId,
        public readonly string $kind,
        public readonly int $recipientId,
        public readonly string $key,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function uniqueId(): string
    {
        return self::fullKey($this->subject, $this->subjectId, $this->kind, $this->key).'#'.$this->recipientId;
    }

    public function handle(ResolveStaffRecipients $recipients): void
    {
        $recipient = User::query()->find($this->recipientId);

        if ($recipient === null) {
            return;
        }

        $this->subject === RemindPendingWork::SUBJECT_SEAT
            ? $this->remindSeat($recipient, $recipients)
            : $this->remindTask($recipient, $recipients);
    }

    /** Khoá chuông của một lời nhắc: đối tượng, id, loại, khoá nhắc. */
    public static function fullKey(string $subject, int $subjectId, string $kind, string $key): string
    {
        return "{$subject}:{$subjectId}:{$kind}:{$key}";
    }

    /** Thư của lời nhắc này (mẫu theo đối tượng và loại, khoá `$key`) đã gửi thành công tới `$recipient` chưa. */
    public static function alreadyMailed(Task|Matter $related, User $recipient, string $subject, string $kind, string $key): bool
    {
        $template = $subject === RemindPendingWork::SUBJECT_SEAT
            ? MatterAssignmentNotice::TEMPLATE_PREFIX.$kind
            : TaskReminder::TEMPLATE_PREFIX.$kind;

        return OutboundMessage::query()
            ->withoutGlobalScopes()
            ->where('related_type', $related->getMorphClass())
            ->where('related_id', $related->getKey())
            ->where('template', $template)
            ->where('recipient', $recipient->email)
            ->where('payload->tier', $key)
            ->where('status', OutboundStatus::Sent)
            ->exists();
    }

    private function remindTask(User $recipient, ResolveStaffRecipients $recipients): void
    {
        $task = Task::query()->withoutGlobalScope(ClientPortalScope::class)
            ->with(['matter', 'assignee'])
            ->find($this->subjectId);

        if ($task === null || ! RemindPendingWork::taskReminderHolds($task, $this->kind, $this->key)) {
            return;
        }

        $user = $recipients->forTask($task, [$recipient])->first();

        if ($user === null) {
            return;
        }

        $detail = $this->taskDetail($task);
        $fullKey = self::fullKey($this->subject, $this->subjectId, $this->kind, $this->key);

        $alerted = $user->notifications()
            ->where('type', TaskReminderAlert::class)
            ->where('data->viewData->reminder_key', $fullKey)
            ->exists();

        if (! $alerted) {
            $user->notify(new TaskReminderAlert($task, $this->kind, $fullKey, $detail));
        }

        if (self::alreadyMailed($task, $user, $this->subject, $this->kind, $this->key)) {
            return;
        }

        Mail::to($user->email)->send(new TaskReminder($task, $this->kind, $user, $detail, $this->key));
    }

    private function remindSeat(User $recipient, ResolveStaffRecipients $recipients): void
    {
        $seat = MatterUser::query()->withoutGlobalScope(ClientPortalScope::class)->find($this->subjectId);
        $matter = $seat === null ? null : Matter::query()->withoutGlobalScope(ClientPortalScope::class)->find($seat->matter_id);
        $member = $seat === null ? null : User::query()->withTrashed()->find($seat->user_id);

        if ($seat === null || $matter === null || $member === null || ! $matter->isOpen()
            || ! RemindPendingWork::seatReminderHolds($seat, $this->kind, $this->key)
            || ! $recipients->qualifies($recipient, $matter)) {
            return;
        }

        $since = FirstResponseClock::formatMinutes(AcceptanceClock::fromConfig()->waitedMinutes($seat->assigned_at));
        $fullKey = self::fullKey($this->subject, $this->subjectId, $this->kind, $this->key);
        $alertKind = $this->kind === RemindPendingWork::UNACCEPTED ? MatterAssignmentAlert::REMINDER_UNACCEPTED : MatterAssignmentAlert::REMINDER_AWAITING;

        if (! SendMatterAssignmentNotice::alreadyAlerted($recipient, $fullKey)) {
            $recipient->notify(new MatterAssignmentAlert(
                $matter,
                $alertKind,
                (string) $member->name,
                '',
                $seat->role_in_matter->label(),
                null,
                $fullKey,
                $since,
            ));
        }

        if (self::alreadyMailed($matter, $recipient, $this->subject, $this->kind, $this->key)) {
            return;
        }

        Mail::to($recipient->email)->send(new MatterAssignmentNotice(
            $matter,
            $this->kind,
            $recipient,
            (string) $member->name,
            $seat->role_in_matter->label(),
            null,
            $since,
            $this->key,
        ));
    }

    /** Một cụm chữ cho thân lời nhắc: đã chờ bao lâu, hạn ngày nào, quá hạn mấy ngày. */
    private function taskDetail(Task $task): string
    {
        return match ($this->kind) {
            RemindPendingWork::AWAITING, RemindPendingWork::UNACCEPTED => FirstResponseClock::formatMinutes(
                AcceptanceClock::fromConfig()->waitedMinutes($task->assigned_at),
            ),
            RemindPendingWork::DUE_SOON => (string) $task->due_date?->format('d/m/Y'),
            default => (string) ($task->due_date === null ? 0 : (int) $task->due_date->diffInDays(today())),
        };
    }
}
