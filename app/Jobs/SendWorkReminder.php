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
use App\Support\Audit;
use App\Support\Intake\FirstResponseClock;
use App\Support\Scopes\ClientPortalScope;
use App\Support\Tasks\AcceptanceClock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;
use Throwable;

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
 *  2. người nhận: việc qua `ResolveStaffRecipients::forTask()` (còn xem được việc) VÀ còn đúng là người
 *     lời nhắc nói tới ({@see RemindPendingWork::stillAddressedTo()} — vòng sửa polish 1, rà soát Task 2
 *     m6 + n2: người đang giữ việc với ba loại nhắc người nhận, người trong `forTaskAssigner()` với hai loại
 *     báo người giao; không còn thì bỏ, ghi một dòng log chỉ mang id), hồ sơ qua
 *     `ResolveStaffRecipients::qualifies()` (còn xem được vụ);
 *  3. chống trùng: chuông đã có `viewData.reminder_key`/`seat_key` = {@see self::fullKey()} thì không gửi
 *     lại; thư đã có dòng sổ thư `sent` cùng mẫu, cùng người, cùng bản ghi, cùng `payload.tier` thì không
 *     gửi lại ({@see self::alreadyMailed()} — lịch cũng hỏi câu này trước khi xếp).
 *
 * Hai loại báo người giao ({@see RemindPendingWork::isAssignerKind()}) tới người không phải `assigned_by`
 * LÚC GỬI — luật sư phụ trách nhận thay người giao đã rời vụ hay nghỉ việc (`ResolveStaffRecipients::
 * forTaskAssigner()`) — mang tên người giao (vòng sửa 1, I1): chuông và thư dùng câu chữ, đường dẫn của
 * người nhận thay ({@see TaskReminderAlert::onBehalf()}).
 *
 * `ShouldBeUnique` theo (đối tượng, loại, người, khoá): hai lượt lịch liền nhau không xếp hai job cho cùng
 * một lời nhắc khi job đầu còn chờ hàng.
 *
 * # Hỏng hẳn (vòng sửa polish 1 — rà soát Task 2, m7)
 *
 * Hết `$tries`: {@see self::failed()} ghi một dòng nhật ký `work_reminder_failed` (chỉ id: đối tượng, loại,
 * người nhận — không tiêu đề, không địa chỉ; lý do lỗi đã nằm ở `outbound_messages.error`), và lịch hỏi
 * {@see self::failedForGoodToday()} trước khi xếp lại — tối đa MỘT chuỗi thử hỏng cho mỗi (người nhận, việc
 * hay hồ sơ) mỗi ngày; lượt đầu hôm sau thử lại. Cùng cơ chế `SendUnansweredIntakeReminderMail`. Chuông đã
 * đi trước thư nên người nhận vẫn được báo.
 */
class SendWorkReminder implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * Khoá duy nhất giữ tối đa hai giờ — dài hơn trọn chuỗi thử lại của `backoff()` (~81 phút), cùng lý do
     * `SendUnansweredIntakeReminderMail`; sau đó sổ thư là lưới chống trùng.
     */
    public int $uniqueFor = 7200;

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

    /**
     * Thư của lời nhắc này (mẫu theo đối tượng và loại, khoá `$key`) đã gửi thành công tới `$recipient` chưa.
     *
     * Hồ sơ (vòng sửa polish 1): có `$seatId` thì một thư GỘP nhiều hồ sơ cũng tính — thư đó mang khoá
     * {@see SendSeatReminderDigest::ledgerKey()} (`|<khoá đầy đủ của từng ghế>|…`) trên dòng sổ thư của hồ sơ
     * đầu, nên tìm khoá đầy đủ của ghế này trong đó.
     */
    public static function alreadyMailed(Task|Matter $related, User $recipient, string $subject, string $kind, string $key, ?int $seatId = null): bool
    {
        $template = $subject === RemindPendingWork::SUBJECT_SEAT
            ? MatterAssignmentNotice::TEMPLATE_PREFIX.$kind
            : TaskReminder::TEMPLATE_PREFIX.$kind;

        $sent = fn () => OutboundMessage::query()
            ->withoutGlobalScopes()
            ->where('template', $template)
            ->where('recipient', $recipient->email)
            ->where('status', OutboundStatus::Sent);

        if ($sent()
            ->where('related_type', $related->getMorphClass())
            ->where('related_id', $related->getKey())
            ->where('payload->tier', $key)
            ->exists()) {
            return true;
        }

        return $subject === RemindPendingWork::SUBJECT_SEAT && $seatId !== null
            && $sent()->where('payload->tier', 'like', '%|'.self::fullKey($subject, $seatId, $kind, $key).'|%')->exists();
    }

    /**
     * Chạy đúng một lần, khi mọi lượt thử đều hỏng — xem docblock lớp, mục "Hỏng hẳn". Chủ thể là việc (hay
     * vụ của ghế), để dòng nằm ở đúng chỗ người xem được việc/vụ đó đọc nhật ký; thuộc tính chỉ mang id.
     */
    public function failed(?Throwable $exception): void
    {
        $related = $this->subject === RemindPendingWork::SUBJECT_SEAT
            ? Matter::query()->withoutGlobalScope(ClientPortalScope::class)->withTrashed()
                ->find(MatterUser::query()->withoutGlobalScope(ClientPortalScope::class)->whereKey($this->subjectId)->value('matter_id'))
            : Task::query()->withoutGlobalScope(ClientPortalScope::class)->withTrashed()->find($this->subjectId);

        $matterId = $related instanceof Task ? $related->matter_id : $related?->getKey();

        Audit::record('work_reminder_failed', $related, [
            // Cùng khoá `matter_id` của mọi dòng việc nội bộ: dòng quy về vụ, theo luật xem nhật ký của vụ.
            ...($matterId !== null ? ['matter_id' => $matterId] : []),
            'subject' => $this->subject,
            'subject_id' => $this->subjectId,
            'kind' => $this->kind,
            'recipient_id' => $this->recipientId,
        ]);
    }

    /**
     * Thư nhắc của `$subject` #`$subjectId` cho người `$recipientId` đã hỏng HẲN trong ngày hôm nay chưa — đọc
     * dòng `work_reminder_failed` mà {@see self::failed()} ghi. Lịch `RemindPendingWork` hỏi câu này trước khi
     * xếp: không có nó, một hộp thư hỏng cả ngày sinh một chuỗi thử mới mỗi 30 phút.
     */
    public static function failedForGoodToday(string $subject, int $subjectId, int $recipientId): bool
    {
        return Activity::query()
            ->where('event', 'work_reminder_failed')
            ->where('properties->subject', $subject)
            ->where('properties->subject_id', $subjectId)
            ->where('properties->recipient_id', $recipientId)
            ->where('created_at', '>=', today()->startOfDay())
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

        // Xem docblock lớp, bước 2: còn là người nhận / người giao (hay người nhận thay) lúc gửi.
        if (! RemindPendingWork::stillAddressedTo($task, $this->kind, $user)) {
            Log::info('tasks.remind: bỏ một lời nhắc — người nhận không còn là người lời nhắc nói tới.', [
                'task_id' => $task->getKey(),
                'kind' => $this->kind,
                'recipient_id' => $user->getKey(),
            ]);

            return;
        }

        $detail = $this->taskDetail($task);
        $fullKey = self::fullKey($this->subject, $this->subjectId, $this->kind, $this->key);
        // Xem docblock lớp: người nhận thay người giao.
        $onBehalfOf = RemindPendingWork::isAssignerKind($this->kind) && $user->getKey() !== $task->assigned_by
            ? $recipients->assignerName($task)
            : null;

        $alerted = $user->notifications()
            ->where('type', TaskReminderAlert::class)
            ->where('data->viewData->reminder_key', $fullKey)
            ->exists();

        if (! $alerted) {
            $user->notify(new TaskReminderAlert($task, $this->kind, $fullKey, $detail, $onBehalfOf));
        }

        if (self::alreadyMailed($task, $user, $this->subject, $this->kind, $this->key)) {
            return;
        }

        Mail::to($user->email)->send(new TaskReminder($task, $this->kind, $user, $detail, $this->key, $onBehalfOf));
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

        if (self::alreadyMailed($matter, $recipient, $this->subject, $this->kind, $this->key, $this->subjectId)) {
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
