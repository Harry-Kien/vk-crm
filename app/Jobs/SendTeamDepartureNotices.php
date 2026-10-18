<?php

namespace App\Jobs;

use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Actions\Matter\RemoveTeamMember;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Enums\Confidentiality;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\TeamDepartureAlert;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Lời báo của MỘT lần gỡ thành viên khỏi đội ngũ ({@see RemoveTeamMember}, làn fa2, phán quyết 1). Xếp
 * `->afterCommit()`; payload chỉ mang id: vụ, người bị gỡ, người bấm, dòng `team_member_removed`, các việc đã chuyển
 * (`$movedTaskIds`, sang `$recipientId`) và các việc để "Cần giao lại" (`$leftTaskIds`).
 *
 * # Ai nhận, đọc lại lúc chạy
 *
 *  - Người bị gỡ: còn hoạt động, chưa xoá, không phải người bấm — {@see TeamDepartureAlert::MEMBER_LEFT}, một chuông
 *    kể cả khi không giữ việc nào. Mã hồ sơ hiện khi vụ không hạn chế (họ vừa ngồi trong đội ngũ, đã biết mã) hay họ
 *    vẫn mở được hồ sơ (`qualifies()`); vụ hạn chế họ không mở được thì câu chung.
 *  - Với mỗi việc còn đúng như lần gỡ để lại (việc đã chuyển: người giữ vẫn là người nhận thay; việc để lại: người bị
 *    gỡ vẫn giữ, chưa ai xử lý), người giao của nó hay người nhận thay họ (`ResolveStaffRecipients::forTaskAssigner()`
 *    — chỉ người mở được việc, nên vụ hạn chế không lộ), trừ người bấm, người bị gỡ và người nhận thay (họ đã có chuông
 *    "được giao" của từng việc) — {@see TeamDepartureAlert::TASKS_HANDLED}, một chuông mỗi người cho mọi việc của họ.
 *
 * # Chống trùng khi thử lại
 *
 * Khoá `team@<id dòng nhật ký>` ở `viewData.team_departure_key`.
 */
class SendTeamDepartureNotices implements ShouldQueue
{
    use Queueable;
    use ReadsWithoutPortalScope;

    public int $tries = 5;

    /**
     * @param  list<int>  $movedTaskIds
     * @param  list<int>  $leftTaskIds
     */
    public function __construct(
        public readonly int $matterId,
        public readonly int $memberId,
        public readonly int $actorId,
        public readonly int $eventId,
        public readonly array $movedTaskIds,
        public readonly array $leftTaskIds,
        public readonly ?int $recipientId,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(ResolveStaffRecipients $recipients): void
    {
        $matter = $this->scopelessly(Matter::query())->find($this->matterId);
        $member = User::query()->withTrashed()->find($this->memberId);

        if ($matter === null || $member === null) {
            return;
        }

        $key = 'team@'.$this->eventId;
        $actorName = (string) User::query()->withTrashed()->whereKey($this->actorId)->value('name');
        $toName = $this->recipientId === null ? '' : (string) User::query()->withTrashed()->whereKey($this->recipientId)->value('name');

        if ($member->is_active && ! $member->trashed() && $member->getKey() !== $this->actorId) {
            $named = $matter->confidentiality !== Confidentiality::Restricted || $recipients->qualifies($member, $matter);

            $this->ring($member, new TeamDepartureAlert(
                TeamDepartureAlert::MEMBER_LEFT,
                (int) $matter->getKey(),
                $named ? (string) $matter->code : null,
                (string) $member->name,
                $actorName,
                count($this->movedTaskIds),
                count($this->leftTaskIds),
                $toName,
                $key,
            ), $key);
        }

        /** @var array<int, array{user: User, moved: int, left: int}> $assigners */
        $assigners = [];

        foreach ($this->stillAsLeft() as [$task, $moved]) {
            foreach ($recipients->forTaskAssigner($task) as $user) {
                if (in_array($user->getKey(), [$this->actorId, $this->memberId, $this->recipientId], true)) {
                    continue;
                }

                $assigners[$user->getKey()] ??= ['user' => $user, 'moved' => 0, 'left' => 0];
                $assigners[$user->getKey()][$moved ? 'moved' : 'left']++;
            }
        }

        foreach ($assigners as $row) {
            $this->ring($row['user'], new TeamDepartureAlert(
                TeamDepartureAlert::TASKS_HANDLED,
                (int) $matter->getKey(),
                (string) $matter->code,
                (string) $member->name,
                $actorName,
                $row['moved'],
                $row['left'],
                $toName,
                $key,
            ), $key);
        }
    }

    /**
     * Việc còn đúng như lần gỡ để lại — xem docblock lớp. Cặp `[việc, đã chuyển?]`.
     *
     * @return list<array{0: Task, 1: bool}>
     */
    private function stillAsLeft(): array
    {
        $rows = [];

        foreach ([[$this->movedTaskIds, true, $this->recipientId], [$this->leftTaskIds, false, $this->memberId]] as [$ids, $moved, $holderId]) {
            if ($ids === [] || $holderId === null) {
                continue;
            }

            $tasks = $this->scopelessly(Task::query())
                ->whereKey($ids)
                ->where('assignee_id', $holderId)
                ->held()
                ->with(['assigner', 'matter.leadLawyer'])
                ->orderBy('id')
                ->get();

            foreach ($tasks as $task) {
                $rows[] = [$task, $moved];
            }
        }

        return $rows;
    }

    private function ring(User $user, TeamDepartureAlert $alert, string $key): void
    {
        $already = $user->notifications()
            ->where('type', TeamDepartureAlert::class)
            ->where('data->viewData->team_departure_key', $key)
            ->exists();

        if (! $already) {
            $user->notify($alert);
        }
    }
}
