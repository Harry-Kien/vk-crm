<?php

namespace App\Jobs;

use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Actions\Matter\TransferStaffWork;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Schedule\CheckDeadlines;
use App\Enums\Confidentiality;
use App\Enums\OutboundStatus;
use App\Mail\Staff\StaffWorkTransferred;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\DeadlineAssignedAlert;
use App\Notifications\Staff\StaffWorkTransferAlert;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * MỘT lời báo cho cả lô "Chuyển việc nội bộ và ghế" ({@see TransferStaffWork}, làn fa2, Task 1, vòng sửa 1 — I2):
 * các Action con của lô chạy ở chế độ không báo (`AddTeamMember`, `ChangeDeadlineResponsible`, `RemoveTeamMember` với
 * `notify: false`; `ReassignTask` không báo ai — gộp integ-fa), và job này thay chúng. Xếp `->afterCommit()` sau dòng
 * nhật ký `staff_work_transferred`; payload chỉ mang id (người cũ, người nhận, người bấm, dòng nhật ký, các hồ sơ có
 * thay đổi, các việc và các mốc đã chuyển) và các con số của lần chuyển.
 *
 *  - Người NHẬN: một chuông {@see StaffWorkTransferAlert::RECEIVED} + một thư {@see StaffWorkTransferred} — khi còn
 *    hoạt động, chưa xoá, không phải người bấm (người tự nhận việc thì không tự báo mình), và có ít nhất một thứ
 *    đã sang tay họ. Gộp integ-fa (C1 của rà soát cuối làn fa2): chuông và thư liệt kê TỪNG mốc hạn đã sang tay họ
 *    ({@see self::deadlineLines()}) — cùng các điều `SendDeadlineAssignmentNotice` nói cho một mốc — vì `reminders_sent`
 *    thuộc về mốc: bậc nhắc đã gửi cho người cũ không gửi lại cho người nhận.
 *  - Người CŨ: một chuông {@see StaffWorkTransferAlert::HANDED_OVER} — khi còn hoạt động, chưa xoá, không phải người
 *    bấm. Không thư (cùng mức `TeamDepartureAlert::MEMBER_LEFT`).
 *  - Gộp integ-fa (I-A của rà soát cuối làn fa2): NGƯỜI GIAO của các việc đã chuyển — với mỗi việc còn đúng như lần
 *    chuyển để lại (người nhận vẫn giữ), người giao hay người nhận thay họ (`ResolveStaffRecipients::forTaskAssigner()`
 *    — chỉ người mở được việc; `decisionNeeded: false` — gộp integ-fa, rà soát chéo mục 6: việc đã có người giữ mới,
 *    lời báo chỉ là tin, không rơi tới người quản lý của vụ), trừ người bấm, người cũ và người nhận: MỘT chuông
 *    {@see StaffWorkTransferAlert::ASSIGNER} mỗi người cho mọi việc của họ trong lô — cùng luật
 *    `SendTeamDepartureNotices` (`TASKS_HANDLED`) của một lần gỡ thành viên.
 *
 * Mã hồ sơ: cùng luật {@see SendTeamDepartureNotices} — hiện khi vụ không hạn chế, hay người nhận lời báo còn mở được
 * nó (`ResolveStaffRecipients::qualifies()`, đọc lúc chạy); vụ hạn chế người đó không mở được, và hồ sơ đã huỷ sau lần
 * chuyển, chỉ được đếm. Với dòng mốc hạn của một hồ sơ như vậy, cả tên mốc cũng không hiện ("một mốc hạn" trên "một hồ
 * sơ" — tên mốc là chữ người dùng gõ, có thể mang tên khách); hạn, số ngày còn lại và mức độ vẫn hiện.
 *
 * Các con số là của lần chuyển (đã xảy ra), không đọc lại: việc đã sang tay người nhận vẫn là "đã chuyển" dù sau đó
 * họ đã bấm nhận hay giao tiếp. Danh sách mốc thì đọc lại: chỉ mốc người nhận VẪN giữ và chưa xong (đã đổi tiếp thì
 * lời báo của lần sau nói thay).
 *
 * # Chống trùng khi thử lại
 *
 * Khoá `staff_work@<id dòng nhật ký>` ở `viewData.staff_work_key` của chuông và `payload.tier` của dòng sổ thư.
 */
class SendStaffWorkTransferNotices implements ShouldQueue
{
    use Queueable;
    use ReadsWithoutPortalScope;

    public int $tries = 5;

    /**
     * @param  list<int>  $matterIds  hồ sơ có thay đổi trong lô (không gồm hồ sơ thất bại hay không quản lý được)
     * @param  array{tasks: int, followed: int, deadlines: int, requests: int, seats: int}  $counts
     * @param  list<int>  $movedTaskIds  gộp integ-fa: việc đã sang tay người nhận (trên hồ sơ và việc chung)
     * @param  list<int>  $deadlineIds  gộp integ-fa: mốc đã sang tay người nhận
     */
    public function __construct(
        public readonly int $fromId,
        public readonly int $toId,
        public readonly int $actorId,
        public readonly int $eventId,
        public readonly array $matterIds,
        public readonly array $counts,
        public readonly array $movedTaskIds = [],
        public readonly array $deadlineIds = [],
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(ResolveStaffRecipients $recipients): void
    {
        $from = User::query()->withTrashed()->find($this->fromId);
        $to = User::query()->withTrashed()->find($this->toId);

        if ($from === null || $to === null) {
            return;
        }

        $key = 'staff_work@'.$this->eventId;
        $actorName = (string) User::query()->withTrashed()->whereKey($this->actorId)->value('name');
        $matters = $this->scopelessly(Matter::query())->withTrashed()->whereKey($this->matterIds)->orderBy('id')->get();
        $counts = $this->counts + ['tasks' => 0, 'followed' => 0, 'deadlines' => 0, 'requests' => 0, 'seats' => 0];

        if ($this->reachable($to) && array_sum($counts) > 0) {
            [$codes, $hidden] = $this->named($to, $matters, $recipients);
            $lines = $this->deadlineLines($to, $recipients);

            $this->ring($to, new StaffWorkTransferAlert(StaffWorkTransferAlert::RECEIVED, $actorName, (string) $from->name, (string) $to->name, $codes, $hidden, $counts, $key, $lines), $key);

            if (! $this->alreadyMailed($to, $key)) {
                Mail::to($to->email)->send(new StaffWorkTransferred($to, $actorName, (string) $from->name, $codes, $hidden, $counts, $key, $lines));
            }
        }

        if ($this->reachable($from)) {
            [$codes, $hidden] = $this->named($from, $matters, $recipients);

            $this->ring($from, new StaffWorkTransferAlert(StaffWorkTransferAlert::HANDED_OVER, $actorName, (string) $from->name, (string) $to->name, $codes, $hidden, $counts, $key), $key);
        }

        $this->ringAssigners($from, $to, $actorName, $key, $recipients);
    }

    private function reachable(User $user): bool
    {
        return $user->is_active && ! $user->trashed() && $user->getKey() !== $this->actorId;
    }

    /**
     * Mã hồ sơ `$user` được thấy, và số hồ sơ còn lại chỉ được đếm — xem docblock lớp.
     *
     * @param  Collection<int, Matter>  $matters
     * @return array{0: list<string>, 1: int}
     */
    private function named(User $user, Collection $matters, ResolveStaffRecipients $recipients): array
    {
        [$named, $hidden] = $matters->partition(fn (Matter $matter): bool => $this->mayName($user, $matter, $recipients));

        return [$named->map(fn (Matter $matter): string => (string) $matter->code)->values()->all(), $hidden->count()];
    }

    private function mayName(User $user, ?Matter $matter, ResolveStaffRecipients $recipients): bool
    {
        return $matter !== null && ! $matter->trashed()
            && ($matter->confidentiality !== Confidentiality::Restricted || $recipients->qualifies($user, $matter));
    }

    /**
     * Gộp integ-fa (C1): một dòng mỗi mốc người nhận VẪN giữ và chưa xong, theo hạn — tên mốc (hay "một mốc hạn"), hồ
     * sơ (mã, hay "một hồ sơ" — xem docblock lớp), hạn và số ngày còn lại ({@see DeadlineAssignedAlert::dueLine()} — một
     * định nghĩa với lời báo từng mốc), mức độ. Mốc còn {@see CheckDeadlines::TIERS} nhỏ nhất (bậc nhắc cuối) ngày trở
     * xuống, hôm nay hay đã quá hạn mở đầu bằng dấu GẤP: lời nhắc của bậc đó có thể đã gửi cho người cũ. Chữ thô — chuông
     * escape, thư in qua `{{ }}`.
     *
     * @return list<string>
     */
    private function deadlineLines(User $to, ResolveStaffRecipients $recipients): array
    {
        if ($this->deadlineIds === []) {
            return [];
        }

        $deadlines = $this->scopelessly(Deadline::query())
            ->whereKey($this->deadlineIds)
            ->where('responsible_user_id', $to->getKey())
            ->where('is_completed', false)
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();
        $matters = $this->scopelessly(Matter::query())->withTrashed()->whereKey($deadlines->pluck('matter_id')->unique()->all())->get()->keyBy('id');
        $lastTier = min(CheckDeadlines::TIERS);

        return $deadlines->map(function (Deadline $deadline) use ($to, $matters, $recipients, $lastTier): string {
            $matter = $matters->get($deadline->matter_id);
            $named = $this->mayName($to, $matter, $recipients);
            $line = __('internal_flow.staff_work.digest.deadline_line', [
                'name' => $named ? (string) $deadline->name : __('internal_flow.staff_work.digest.deadline_unnamed'),
                'matter' => $named
                    ? __('internal_flow.team_removal.matter_named', ['code' => (string) $matter?->code])
                    : __('internal_flow.team_removal.matter_hidden'),
                'due' => DeadlineAssignedAlert::dueLine($deadline),
                'severity' => $deadline->severity->label(),
            ]);

            return (int) today()->diffInDays($deadline->due_date, false) <= $lastTier
                ? __('internal_flow.staff_work.digest.deadline_urgent', ['days' => $lastTier]).' '.$line
                : $line;
        })->values()->all();
    }

    /** Gộp integ-fa (I-A): một chuông mỗi người giao của các việc đã chuyển — xem docblock lớp. */
    private function ringAssigners(User $from, User $to, string $actorName, string $key, ResolveStaffRecipients $recipients): void
    {
        if ($this->movedTaskIds === []) {
            return;
        }

        $tasks = $this->scopelessly(Task::query())
            ->whereKey($this->movedTaskIds)
            ->where('assignee_id', $to->getKey())
            ->held()
            ->with(['assigner', 'matter.leadLawyer'])
            ->orderBy('id')
            ->get();

        /** @var array<int, array{user: User, tasks: int, matters: array<int, Matter>, general: bool}> $assigners */
        $assigners = [];

        foreach ($tasks as $task) {
            // Gộp integ-fa (rà soát chéo, mục 6): việc đã có người giữ mới — lời báo chỉ là tin, không rơi tới người
            // quản lý của vụ.
            foreach ($recipients->forTaskAssigner($task, decisionNeeded: false) as $user) {
                if (in_array($user->getKey(), [$this->actorId, $this->fromId, $this->toId], true)) {
                    continue;
                }

                $assigners[$user->getKey()] ??= ['user' => $user, 'tasks' => 0, 'matters' => [], 'general' => false];
                $assigners[$user->getKey()]['tasks']++;

                if ($task->matter === null) {
                    $assigners[$user->getKey()]['general'] = true;
                } else {
                    $assigners[$user->getKey()]['matters'][$task->matter->getKey()] = $task->matter;
                }
            }
        }

        foreach ($assigners as $row) {
            [$codes, $hidden] = $this->named($row['user'], collect($row['matters'])->sortKeys()->values(), $recipients);

            $this->ring($row['user'], new StaffWorkTransferAlert(
                StaffWorkTransferAlert::ASSIGNER,
                $actorName,
                (string) $from->name,
                (string) $to->name,
                $codes,
                $hidden,
                ['tasks' => $row['tasks'], 'followed' => 0, 'deadlines' => 0, 'requests' => 0, 'seats' => 0],
                $key,
                general: $row['general'],
            ), $key);
        }
    }

    private function ring(User $user, StaffWorkTransferAlert $alert, string $key): void
    {
        $already = $user->notifications()
            ->where('type', StaffWorkTransferAlert::class)
            ->where('data->viewData->staff_work_key', $key)
            ->exists();

        if (! $already) {
            $user->notify($alert);
        }
    }

    private function alreadyMailed(User $recipient, string $key): bool
    {
        return OutboundMessage::query()
            ->withoutGlobalScopes()
            ->where('template', StaffWorkTransferred::TEMPLATE)
            ->where('recipient', $recipient->email)
            ->where('payload->tier', $key)
            ->where('status', OutboundStatus::Sent)
            ->exists();
    }
}
