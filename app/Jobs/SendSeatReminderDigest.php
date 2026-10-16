<?php

namespace App\Jobs;

use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Schedule\RemindPendingWork;
use App\Mail\Staff\MatterAssignmentNotice;
use App\Models\Matter;
use App\Models\MatterUser;
use App\Models\User;
use App\Notifications\Staff\MatterAssignmentAlert;
use App\Support\Intake\FirstResponseClock;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * MỘT lời nhắc hồ sơ GỘP của lịch {@see RemindPendingWork} (làn tk, vòng sửa polish 1 — phán quyết controller
 * (3): bàn giao hàng loạt không được "dội" thông báo): cùng một loại nhắc (`reminder.awaiting` cho người được
 * giao, `reminder.unaccepted` cho người giao hay quản lý) cho NHIỀU ghế của cùng một người nhận trong một
 * lượt lịch — một chuông ({@see MatterAssignmentAlert::REMINDER_AWAITING_BATCH} /
 * {@see MatterAssignmentAlert::REMINDER_UNACCEPTED_BATCH}) và một thư (cùng mẫu
 * `staff.task.matter.reminder.*`, {@see MatterAssignmentNotice} với `$batch`) liệt kê các hồ sơ, thay vì
 * một chuông và một thư cho mỗi hồ sơ.
 *
 * # Hỏi lại từng ghế lúc gửi
 *
 * Cùng ba lần lọc của {@see SendWorkReminder} cho từng ghế: ghế và vụ còn, vụ còn mở, lời nhắc còn đúng
 * với khoá của nó ({@see RemindPendingWork::seatReminderHolds()} — gồm cả "người được giao còn nhận được hồ
 * sơ": không bao giờ "X chưa nhận" về một người đã nghỉ), người nhận còn mở được vụ
 * (`ResolveStaffRecipients::qualifies()`), thư của ghế đó chưa gửi ({@see SendWorkReminder::alreadyMailed()}).
 * Không còn ghế nào: không gửi gì. Còn MỘT ghế: chạy đúng lời nhắc một hồ sơ ({@see SendWorkReminder}, cùng
 * khoá, cùng câu chữ). Nhiều ghế: chuông rồi thư gộp.
 *
 * # Chống trùng
 *
 * Chuông: `viewData.seat_key` = `seats:<loại>:<sha1 khoá thư>` ({@see SendMatterAssignmentNotice::
 * alreadyAlerted()}). Thư: `payload.tier` = {@see self::ledgerKey()} — khoá đầy đủ của từng ghế
 * (`SendWorkReminder::fullKey()`) nối bằng `|`; `SendWorkReminder::alreadyMailed()` tìm khoá của một ghế
 * trong đó, nên lượt lịch sau không xếp lại ghế nào đã nằm trong một thư gộp đã gửi.
 *
 * # Hỏng hẳn
 *
 * {@see self::failed()} ghi `work_reminder_failed` cho TỪNG ghế của job (`SendWorkReminder::failed()`), nên
 * lịch bỏ qua chúng tới hết ngày ({@see SendWorkReminder::failedForGoodToday()}) — một chuỗi thử mỗi ngày.
 */
class SendSeatReminderDigest implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** Cùng lý do {@see SendWorkReminder::$uniqueFor}. */
    public int $uniqueFor = 7200;

    /**
     * @param  string  $kind  `RemindPendingWork::AWAITING` hay `RemindPendingWork::UNACCEPTED`
     * @param  array<int, string>  $seats  `id ghế => khoá nhắc` (`RemindPendingWork::awaitingKey()`)
     */
    public function __construct(
        public readonly string $kind,
        public readonly int $recipientId,
        public readonly array $seats,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function uniqueId(): string
    {
        $seats = $this->seats;
        ksort($seats);

        return 'seats:'.$this->kind.'#'.$this->recipientId.'#'.sha1((string) json_encode($seats));
    }

    /** @param  list<string>  $fullKeys  khoá đầy đủ của từng ghế trong thư */
    public static function ledgerKey(array $fullKeys): string
    {
        return '|'.implode('|', $fullKeys).'|';
    }

    public function handle(ResolveStaffRecipients $recipients): void
    {
        $recipient = User::query()->find($this->recipientId);

        if ($recipient === null || $this->seats === []) {
            return;
        }

        $rows = $this->rowsStillDue($recipient, $recipients);

        if ($rows->isEmpty()) {
            return;
        }

        if ($rows->count() === 1) {
            $row = $rows->first();
            (new SendWorkReminder(RemindPendingWork::SUBJECT_SEAT, (int) $row['seat']->getKey(), $this->kind, $this->recipientId, $row['key']))
                ->handle($recipients);

            return;
        }

        $ledgerKey = self::ledgerKey($rows->map(fn (array $row): string => SendWorkReminder::fullKey(
            RemindPendingWork::SUBJECT_SEAT, (int) $row['seat']->getKey(), $this->kind, $row['key'],
        ))->all());
        $bellKey = 'seats:'.$this->kind.':'.sha1($ledgerKey);
        $toMember = $this->kind === RemindPendingWork::AWAITING;
        $since = FirstResponseClock::formatMinutes(RemindPendingWork::thresholdFor($this->kind) * 60);
        /** @var Matter $first */
        $first = $rows->first()['matter'];

        if (! SendMatterAssignmentNotice::alreadyAlerted($recipient, $bellKey)) {
            $recipient->notify(new MatterAssignmentAlert(
                $first,
                $toMember ? MatterAssignmentAlert::REMINDER_AWAITING_BATCH : MatterAssignmentAlert::REMINDER_UNACCEPTED_BATCH,
                '',
                '',
                '',
                null,
                $bellKey,
                $since,
                batchCodes: $rows->map(fn (array $row): string => $toMember
                    ? (string) $row['matter']->code
                    : $row['matter']->code.' ('.$row['member']->name.')')->all(),
            ));
        }

        Mail::to($recipient->email)->send(new MatterAssignmentNotice(
            $first,
            $this->kind,
            $recipient,
            '',
            '',
            null,
            $since,
            $ledgerKey,
            batch: $rows->map(fn (array $row): array => [
                'code' => (string) $row['matter']->code,
                'member' => (string) $row['member']->name,
                'role' => $row['seat']->role_in_matter->label(),
            ])->all(),
        ));
    }

    /** Xem docblock lớp, mục "Hỏng hẳn". */
    public function failed(?Throwable $exception): void
    {
        foreach ($this->seats as $seatId => $key) {
            (new SendWorkReminder(RemindPendingWork::SUBJECT_SEAT, (int) $seatId, $this->kind, $this->recipientId, $key))
                ->failed($exception);
        }
    }

    /**
     * Các ghế của job mà lời nhắc còn phải gửi lúc này — xem docblock lớp, mục "Hỏi lại từng ghế lúc gửi" —
     * theo mã hồ sơ.
     *
     * @return Collection<int, array{seat: MatterUser, matter: Matter, member: User, key: string}>
     */
    private function rowsStillDue(User $recipient, ResolveStaffRecipients $recipients): Collection
    {
        return MatterUser::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->whereKey(array_keys($this->seats))
            ->get()
            ->map(function (MatterUser $seat) use ($recipient, $recipients): ?array {
                $key = $this->seats[$seat->getKey()];
                $matter = Matter::query()->withoutGlobalScope(ClientPortalScope::class)->find($seat->matter_id);
                $member = User::query()->withTrashed()->find($seat->user_id);

                if ($matter === null || $member === null || ! $matter->isOpen()
                    || ! RemindPendingWork::seatReminderHolds($seat, $this->kind, $key)
                    || ! $recipients->qualifies($recipient, $matter)
                    || SendWorkReminder::alreadyMailed($matter, $recipient, RemindPendingWork::SUBJECT_SEAT, $this->kind, $key, (int) $seat->getKey())) {
                    return null;
                }

                return ['seat' => $seat, 'matter' => $matter, 'member' => $member, 'key' => $key];
            })
            ->filter()
            ->sortBy(fn (array $row): string => (string) $row['matter']->code)
            ->values();
    }
}
